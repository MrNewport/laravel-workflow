<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStepAssignee;
use MrNewport\LaravelFlow\Support\Persistence;
use MrNewport\LaravelFlow\Support\SubjectIdentity;
use MrNewport\LaravelWorkflow\Contracts\ImportAuthorizer;
use MrNewport\LaravelWorkflow\Contracts\WorkflowAuthorizer;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\DefinitionPublisher;
use MrNewport\LaravelWorkflow\Exceptions\WorkflowConflict;
use MrNewport\LaravelWorkflow\Models\WorkflowCommand;
use MrNewport\LaravelWorkflow\Models\WorkflowImport;
use MrNewport\LaravelWorkflow\Models\WorkflowMessage;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;
use MrNewport\LaravelWorkflow\Support\CanonicalJson;
use MrNewport\LaravelWorkflow\Support\WriteCollision;
use MrNewport\LaravelWorkflow\WorkflowManager;

/** Adopts historical records without replaying transitions, callbacks or notifications. */
final class HistoryImporter
{
    public function __construct(private ImportAuthorizer $imports, private WorkflowAuthorizer $authorizer, private DefinitionPublisher $publisher, private WorkflowManager $manager) {}

    public function import(Definition $definition, Model $subject, HistorySnapshot $snapshot, string $scope, string $actor, string $idempotencyKey, string $reviewedFingerprint): WorkflowRun
    {
        $this->manager->assertSameConnection($subject);
        foreach ([$scope, $actor, $idempotencyKey] as $value) {
            if (trim($value) === '' || mb_strlen($value) > 191 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('Import scope, actor and request key must be nonempty text up to 191 characters.');
            }
        }
        if (! $subject->exists || ! hash_equals($snapshot->fingerprint(), $reviewedFingerprint)) {
            throw new WorkflowConflict('Review the exact snapshot before importing it.');
        }
        $data = $snapshot->toArray();
        if (! in_array($data['subject']['type'], [get_class($subject), $subject->getMorphClass()], true) || $data['subject']['id'] !== (string) $subject->getKey()) {
            throw new WorkflowConflict('The imported subject does not match the saved model.');
        }
        foreach ($data['tasks'] as $task) {
            $definition->step($task['step']);
            self::storageDate($task['started_at']);
            if (($task['finished_at'] ?? null) !== null) {
                self::storageDate($task['finished_at']);
            }
            foreach ($task['messages'] as $message) {
                self::storageDate($message['created_at']);
            }
        }
        $binding = hash('sha256', CanonicalJson::encode([$snapshot->fingerprint(), $definition->fingerprint(), $scope, $actor, get_class($subject), (string) $subject->getKey()]));
        $authorize = function (Model $model) use ($definition, $snapshot, $scope, $actor) {
            $this->imports->authorizeImport($definition, $model, $snapshot, $scope, $actor);
            $this->authorizer->authorizeStart($definition, $model, $scope, $actor);
        };
        $authorize($subject);

        $collision = new WriteCollision;
        try {
            return (new WorkflowRun)->getConnection()->transaction(function () use ($definition, $subject, $snapshot, $scope, $actor, $idempotencyKey, $data, $binding, $authorize, $collision) {
                $lockedSubject = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
                $authorize($lockedSubject);
                [$subjectType, $subjectKey] = SubjectIdentity::resolve($lockedSubject);
                if ($existing = $this->existing($scope, $data['source'], $binding)) {
                    return $existing;
                }
                if (WorkflowRun::query()->inScope($scope)->where('start_key_hash', hash('sha256', $idempotencyKey))->exists()) {
                    throw new WorkflowConflict('This import request key already belongs to another run.');
                }
                $published = $this->publisher->publish($definition);
                $pending = array_values(array_filter($data['tasks'], fn ($task) => ($task['finished_at'] ?? null) === null));
                $instance = FlowInstance::query()->create(['current_step_id' => $pending === [] ? 'END' : $this->publisher->flowStepId($definition, $pending[0]['step']), 'model_type' => $subjectType, 'model_id' => $subjectKey]);
                Persistence::created($instance);
                $finished = $data['status'] === 'active' ? null : max(array_map(fn ($task) => self::storageDate($task['finished_at'])->format('Y-m-d H:i:s.u'), $data['tasks']));
                try {
                    $run = WorkflowRun::query()->create(['scope_key' => $scope, 'scope_hash' => hash('sha256', $scope), 'definition_id' => $published->getKey(), 'flow_instance_id' => $instance->getKey(), 'start_key_hash' => hash('sha256', $idempotencyKey), 'start_fingerprint' => $binding, 'started_by' => $actor, 'status' => $data['status'], 'version' => 1, 'finished_at' => $finished]);
                } catch (UniqueConstraintViolationException $exception) {
                    $collision->expected = true;
                    throw $exception;
                }
                Persistence::created($run);
                $command = WorkflowCommand::query()->create(['run_id' => $run->getKey(), 'key_hash' => hash('sha256', $idempotencyKey), 'fingerprint' => $binding, 'fingerprint_version' => 2, 'action' => 'import', 'actor' => $actor, 'step_id' => null, 'result_version' => 1, 'result_status' => $run->status, 'step_ids' => [], 'details' => ['source' => $data['source'], 'snapshot_fingerprint' => $snapshot->fingerprint()], 'created_at' => now()]);
                Persistence::created($command);
                $mapping = $assignments = $messages = [];
                $createdAt = now()->format('Y-m-d H:i:s');
                foreach ($data['tasks'] as $task) {
                    $step = FlowInstanceStep::query()->create(['flow_instance_id' => $instance->getKey(), 'step_id' => $this->publisher->flowStepId($definition, $task['step']), 'started_at' => self::storageDate($task['started_at']), 'finished_at' => ($task['finished_at'] ?? null) === null ? null : self::storageDate($task['finished_at']), 'action_taken' => $task['action'] ?? null]);
                    Persistence::created($step);
                    $mapping[] = ['source_id' => $task['source_id'], 'step_id' => (int) $step->getKey()];
                    foreach ($task['assignees'] as $assignee) {
                        $assignments[] = ['flow_instance_step_id' => $step->getKey(), 'assignee_type' => $assignee['type'], 'assignee_value' => $assignee['value'], 'created_at' => $createdAt, 'updated_at' => $createdAt];
                        if (count($assignments) === 100) {
                            FlowStepAssignee::query()->insert($assignments);
                            $assignments = [];
                        }
                    }
                    foreach ($task['messages'] as $message) {
                        $messages[] = ['run_id' => $run->getKey(), 'command_id' => $command->getKey(), 'step_id' => $step->getKey(), 'author' => $message['author'], 'body' => $message['body'], 'created_at' => self::storageDate($message['created_at'])->format('Y-m-d H:i:s')];
                        if (count($messages) === 100) {
                            WorkflowMessage::query()->insert($messages);
                            $messages = [];
                        }
                    }
                }
                if ($assignments !== []) {
                    FlowStepAssignee::query()->insert($assignments);
                }
                if ($messages !== []) {
                    WorkflowMessage::query()->insert($messages);
                }
                try {
                    $record = WorkflowImport::query()->create(['run_id' => $run->getKey(), 'scope_hash' => hash('sha256', $scope), 'source_hash' => hash('sha256', $data['source']), 'snapshot_hash' => $snapshot->fingerprint(), 'binding_fingerprint' => $binding, 'snapshot' => $data, 'task_mapping' => $mapping, 'created_at' => now()]);
                    Persistence::created($record);
                } catch (UniqueConstraintViolationException $exception) {
                    $collision->expected = true;
                    throw $exception;
                }

                return $run->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $collision->expected) {
                throw $exception;
            }
            $authorize($subject);
            if ($existing = $this->existing($scope, $data['source'], $binding, true)) {
                return $existing;
            }
            if (WorkflowRun::query()->inScope($scope)->where('start_key_hash', hash('sha256', $idempotencyKey))->sharedLock()->first(['id']) !== null) {
                throw new WorkflowConflict('This import request key already belongs to another run.', previous: $exception);
            }
            throw $exception;
        }
    }

    /** Match native host-local naive storage without silently changing an instant at a DST fold. */
    private static function storageDate(string $value): DateTimeImmutable
    {
        $zone = new DateTimeZone(config('app.timezone', 'UTC'));
        $instant = HistorySnapshot::date($value);
        $stored = $instant->setTimezone($zone);
        $roundTrip = new DateTimeImmutable($stored->format('Y-m-d H:i:s.u'), new DateTimeZone(date_default_timezone_get()));
        if ($roundTrip->format('U.u') !== $instant->format('U.u') || (int) $stored->format('Y') < 1000 || (int) $stored->format('Y') > 9999) {
            throw new InvalidArgumentException('The import timestamp cannot round-trip in the application storage timezone. Use UTC storage for ambiguous historical instants.');
        }

        return $stored;
    }

    /** JSON columns retain object/list type even when associative Eloquent casts cannot. */
    private static function assertStoredEvidenceShape(string $json): void
    {
        $walk = function (mixed $value) use (&$walk): void {
            if ($value instanceof \stdClass) {
                $properties = get_object_vars($value);
                $keys = array_keys($properties);
                sort($keys, SORT_NUMERIC);
                if ($keys !== [] && $keys === range(0, count($keys) - 1)) {
                    throw new WorkflowConflict('This saved import contains a numeric-key object that requires an explicit evidence review.');
                }
                foreach ($properties as $property) {
                    $walk($property);
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }
            }
        };
        $walk(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    private function existing(string $scope, string $source, string $binding, bool $current = false): ?WorkflowRun
    {
        $query = WorkflowImport::query()->where('scope_hash', hash('sha256', $scope))->where('source_hash', hash('sha256', $source));
        if ($current) {
            $query->sharedLock();
        }
        $existing = $query->first();
        if (! $existing) {
            return null;
        }
        if (! $current) {
            $existing = WorkflowImport::query()->whereKey($existing->getKey())->sharedLock()->firstOrFail();
        }
        self::assertStoredEvidenceShape($existing->getRawOriginal('snapshot'));
        if (! hash_equals($existing->binding_fingerprint, $binding)) {
            throw new WorkflowConflict('This source run was already imported with different mappings or evidence.');
        }

        return WorkflowRun::query()->inScope($scope)->sharedLock()->findOrFail($existing->run_id);
    }
}
