<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use MrNewport\LaravelFlow\Assignments\AssignmentStrategyFactory;
use MrNewport\LaravelFlow\Assignments\AssignmentValues;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Support\Persistence;
use MrNewport\LaravelWorkflow\Contracts\ActionValidator;
use MrNewport\LaravelWorkflow\Contracts\AssignmentAuthorizer;
use MrNewport\LaravelWorkflow\Contracts\WorkflowAuthorizer;
use MrNewport\LaravelWorkflow\Events\WorkflowChanged;
use MrNewport\LaravelWorkflow\Exceptions\WorkflowConflict;
use MrNewport\LaravelWorkflow\Models\PublishedDefinition;
use MrNewport\LaravelWorkflow\Models\WorkflowCommand;
use MrNewport\LaravelWorkflow\Models\WorkflowMessage;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;
use MrNewport\LaravelWorkflow\Support\CanonicalJson;
use MrNewport\LaravelWorkflow\Support\WriteCollision;

/** Coordinates policy, persistence and replay; Flow remains the transition/assignment engine. */
final class WorkflowManager
{
    public function __construct(private WorkflowAuthorizer $authorizer, private ActionValidator $validator, private DefinitionPublisher $publisher, private AssignmentAuthorizer $assignments) {}

    public function start(Definition $definition, Model $subject, string $scope, string $actor, string $idempotencyKey, ?Closure $withinTransaction = null): WorkflowRun
    {
        $this->context($scope, $actor, $idempotencyKey);
        $this->assertSameConnection($subject);
        if (! $subject->exists || $subject->getKey() === null) {
            throw new InvalidArgumentException('The workflow subject must already be saved.');
        }
        $fingerprint = hash('sha256', CanonicalJson::encode([$definition->fingerprint(), get_class($subject), (string) $subject->getKey(), $scope, $actor]));
        $this->authorizer->authorizeStart($definition, $subject, $scope, $actor);

        $collision = new WriteCollision;
        try {
            return (new WorkflowRun)->getConnection()->transaction(function () use ($definition, $subject, $scope, $actor, $idempotencyKey, $withinTransaction, $fingerprint, $collision) {
                // Serializes concurrent starts for the same saved subject, including host callbacks.
                $lockedSubject = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
                $this->authorizer->authorizeStart($definition, $lockedSubject, $scope, $actor);
                if ($existing = $this->existingStart($scope, $idempotencyKey, $fingerprint)) {
                    return $existing;
                }
                $published = $this->publisher->publish($definition);
                $instance = FlowManager::startFlow($this->publisher->flowStepId($definition, $definition->initial), $lockedSubject);
                try {
                    $run = WorkflowRun::query()->create([
                        'scope_key' => $scope, 'scope_hash' => hash('sha256', $scope), 'definition_id' => $published->getKey(),
                        'flow_instance_id' => $instance->getKey(), 'start_key_hash' => hash('sha256', $idempotencyKey),
                        'start_fingerprint' => $fingerprint, 'started_by' => $actor, 'status' => 'active', 'version' => 1,
                    ]);
                } catch (UniqueConstraintViolationException $exception) {
                    $collision->expected = true;
                    throw $exception;
                }
                Persistence::created($run);
                if ($withinTransaction !== null) {
                    $withinTransaction($run, $lockedSubject);
                }
                $this->receipt($run, $idempotencyKey, $fingerprint, 'start', $actor, null, $run->pendingSteps()->pluck('id')->all(), null);

                return $run->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $collision->expected) {
                throw $exception;
            }
            // Different subjects can race with the same request key. The unique index is the authority.
            $this->authorizer->authorizeStart($definition, $subject, $scope, $actor);

            return $this->existingStart($scope, $idempotencyKey, $fingerprint, true) ?? throw $exception;
        }
    }

    public function act(WorkflowRun $run, int $stepId, string $action, string $scope, string $actor, string $idempotencyKey, int $expectedVersion, array $payload = [], ?Closure $withinTransaction = null, ?string $message = null): WorkflowRun
    {
        $payload = CanonicalJson::detach($payload);
        $this->text($action, 100, 'action');

        return $this->mutate($run, $stepId, $action, $scope, $actor, $idempotencyKey, $expectedVersion, ['payload' => $payload, 'message' => $message], function (WorkflowRun $locked, FlowInstanceStep $step, Definition $definition) use ($action, $payload, $withinTransaction) {
            $local = $this->publisher->localStepId($definition, $step->step_id);
            if (! isset($definition->step($local)['transitions'][$action])) {
                throw new InvalidArgumentException('This action is not defined for the workflow step.');
            }
            $validated = $this->validator->validate($locked, $step, $action, $payload);
            if ($withinTransaction !== null) {
                $withinTransaction($locked, $step, $validated);
            }
            $created = FlowManager::actionStep($step, $action);

            return array_values(array_map(fn ($next) => $next->getKey(), array_filter($created)));
        }, $message);
    }

    /** Cancel all branches only when every pending step explicitly defines cancel => END. */
    public function cancel(WorkflowRun $run, string $scope, string $actor, string $idempotencyKey, int $expectedVersion, ?string $message = null): WorkflowRun
    {
        return $this->mutate($run, null, 'cancel-run', $scope, $actor, $idempotencyKey, $expectedVersion, ['message' => $message], function (WorkflowRun $locked, ?FlowInstanceStep $unused, Definition $definition) use ($actor) {
            $pending = $locked->pendingSteps()->lockForUpdate()->get();
            foreach ($pending as $step) {
                $local = $this->publisher->localStepId($definition, $step->step_id);
                if (($definition->step($local)['transitions']['cancel'] ?? null) !== ['END']) {
                    throw new WorkflowConflict('Every pending branch must explicitly allow cancellation.');
                }
                $this->authorizer->authorize($locked, $step, 'cancel', $actor);
            }
            foreach ($pending as $step) {
                FlowManager::actionStep($step, 'cancel');
            }

            return [];
        }, $message, 'cancelled');
    }

    /** Append a plain-text note, including after completion. History is never rewritten. */
    public function comment(WorkflowRun $run, string $scope, string $actor, string $idempotencyKey, int $expectedVersion, string $message, ?int $stepId = null): WorkflowRun
    {
        if (trim($message) === '') {
            throw new InvalidArgumentException('A comment cannot be empty.');
        }

        return $this->mutate($run, $stepId, 'comment', $scope, $actor, $idempotencyKey, $expectedVersion, ['message' => $message], fn () => [], $message, null, true);
    }

    /** Host policy authorizes this administrative operation; existing Flow strategies own writes. */
    public function reassign(WorkflowRun $run, int $stepId, array $assignees, string $scope, string $actor, string $idempotencyKey, int $expectedVersion): WorkflowRun
    {
        $assignees = CanonicalJson::detach($assignees);

        return $this->mutate($run, $stepId, 'reassign', $scope, $actor, $idempotencyKey, $expectedVersion, ['assignees' => $assignees], function (WorkflowRun $locked, FlowInstanceStep $step, Definition $definition) use ($assignees, $actor) {
            $local = $this->publisher->localStepId($definition, $step->step_id);
            $strategy = $definition->step($local)['assignment_strategy'];
            if ($strategy === null || ! array_is_list($assignees) || count($assignees) < 1 || count($assignees) > 250 || ($strategy === 'single_user' && count($assignees) !== 1)) {
                throw new InvalidArgumentException('Reassignment must match the published assignment strategy.');
            }
            $assignees = $strategy === 'email_list' ? AssignmentValues::emails($assignees) : AssignmentValues::users($assignees);
            $current = $step->assignees()->orderBy('id')->sharedLock()->get(['assignee_type', 'assignee_value'])->map(fn ($record) => ['type' => $record->assignee_type, 'value' => $record->assignee_value])->all();
            $proposed = array_map(fn ($value) => ['type' => $strategy === 'email_list' ? 'email' : 'user', 'value' => (string) $value], $assignees);
            $this->assignments->authorizeAssignment($locked, $step, $actor, $current, $proposed);
            AssignmentStrategyFactory::make($strategy)->reassign($step, $assignees);

            return ['step_ids' => [], 'details' => ['assignments_before' => $current, 'assignments_after' => $proposed]];
        });
    }

    /** Run lock and receipts are shared by all mutations, so notes and reassignments also conflict safely. */
    private function mutate(WorkflowRun $run, ?int $stepId, string $action, string $scope, string $actor, string $key, int $expectedVersion, array $data, Closure $operation, ?string $message = null, ?string $finalStatus = null, bool $allowFinished = false): WorkflowRun
    {
        $this->context($scope, $actor, $key);
        $this->assertSameConnection($run);
        if ($expectedVersion < 1 || ($stepId !== null && $stepId < 1)) {
            throw new InvalidArgumentException('Versions and step ids must be positive.');
        }
        if ($message !== null && (mb_strlen($message) > 10000 || str_contains($message, "\0"))) {
            throw new InvalidArgumentException('Messages must be plain text up to 10000 characters.');
        }
        $encoded = CanonicalJson::encode([$run->getKey(), $stepId, $action, $scope, $actor, $expectedVersion, $data]);
        if (strlen($encoded) > 1048576) {
            throw new InvalidArgumentException('Workflow command input exceeds 1 MiB.');
        }
        $fingerprint = hash('sha256', $encoded);

        $collision = new WriteCollision;
        try {
            return (new WorkflowRun)->getConnection()->transaction(function () use ($run, $stepId, $action, $scope, $actor, $key, $expectedVersion, $operation, $message, $finalStatus, $allowFinished, $fingerprint, $data, $collision) {
                $locked = WorkflowRun::query()->inScope($scope)->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
                $step = $stepId === null ? null : FlowInstanceStep::query()->where('flow_instance_id', $locked->flow_instance_id)->whereKey($stepId)->lockForUpdate()->firstOrFail();
                $this->authorizer->authorize($locked, $step, $action, $actor);
                $existing = $locked->commands()->where('key_hash', hash('sha256', $key))->first();
                if ($existing === null && $locked->version !== $expectedVersion) {
                    $existing = $locked->commands()->where('key_hash', hash('sha256', $key))->sharedLock()->first();
                }
                if ($existing !== null) {
                    $this->assertReplayShape($existing, $data);
                    if (! hash_equals($existing->fingerprint, $fingerprint)) {
                        throw new WorkflowConflict('This request key has already been used for different input.');
                    }

                    return $locked;
                }
                if ($locked->version !== $expectedVersion) {
                    throw new WorkflowConflict('The workflow changed; reload before acting.');
                }
                if ((! $allowFinished && $locked->status !== 'active') || (! $allowFinished && $step !== null && $step->finished_at !== null)) {
                    throw new WorkflowConflict('This workflow or step has already finished.');
                }
                $published = PublishedDefinition::query()->whereKey($locked->definition_id)->sharedLock()->firstOrFail();
                $locked->setRelation('publishedDefinition', $published);
                $definition = $published->definition();
                $this->publisher->assertEngineMatches($definition);
                $result = $operation($locked, $step, $definition);
                $createdIds = $result['step_ids'] ?? $result;
                $details = $result['details'] ?? [];
                $pending = $locked->pendingSteps()->sharedLock()->first(['id']) !== null;
                $status = $finalStatus ?? ($locked->status === 'active' ? ($pending ? 'active' : 'completed') : $locked->status);
                $updated = WorkflowRun::query()->whereKey($locked->getKey())->where('version', $expectedVersion)->update(['version' => $expectedVersion + 1, 'status' => $status, 'finished_at' => $status === 'active' ? null : ($locked->finished_at ?? now()), 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw new WorkflowConflict('The workflow changed while applying this command.');
                }
                $locked = $locked->fresh();
                try {
                    $this->receipt($locked, $key, $fingerprint, $action, $actor, $stepId, $createdIds, $message, $details);
                } catch (UniqueConstraintViolationException $exception) {
                    $collision->expected = true;
                    throw $exception;
                }

                return $locked;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $collision->expected) {
                throw $exception;
            }

            return (new WorkflowRun)->getConnection()->transaction(function () use ($run, $stepId, $action, $scope, $actor, $key, $fingerprint, $exception, $data) {
                $locked = WorkflowRun::query()->inScope($scope)->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
                $step = $stepId === null ? null : FlowInstanceStep::query()->where('flow_instance_id', $locked->flow_instance_id)->whereKey($stepId)->sharedLock()->firstOrFail();
                $this->authorizer->authorize($locked, $step, $action, $actor);
                $existing = $locked->commands()->where('key_hash', hash('sha256', $key))->sharedLock()->first();
                if ($existing === null) {
                    throw $exception;
                }
                $this->assertReplayShape($existing, $data);
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new WorkflowConflict('This request key has already been used for different input.', previous: $exception);
                }

                return $locked;
            }, 3);
        }
    }

    private function receipt(WorkflowRun $run, string $key, string $fingerprint, string $action, string $actor, ?int $stepId, array $createdIds, ?string $message, array $details = []): WorkflowCommand
    {
        $command = WorkflowCommand::query()->create(['run_id' => $run->getKey(), 'key_hash' => hash('sha256', $key), 'fingerprint' => $fingerprint, 'fingerprint_version' => 2, 'action' => $action, 'actor' => $actor, 'step_id' => $stepId, 'result_version' => $run->version, 'result_status' => $run->status, 'step_ids' => $createdIds, 'details' => $details, 'created_at' => now()]);
        Persistence::created($command);
        if ($message !== null && trim($message) !== '') {
            $savedMessage = WorkflowMessage::query()->create(['run_id' => $run->getKey(), 'command_id' => $command->getKey(), 'step_id' => $stepId, 'author' => $actor, 'body' => $message, 'created_at' => now()]);
            Persistence::created($savedMessage);
        }
        Event::dispatch(new WorkflowChanged((int) $run->getKey(), (int) $command->getKey(), $action, $actor, $run->version));

        return $command;
    }

    private function assertReplayShape(WorkflowCommand $command, array $data): void
    {
        if (($command->fingerprint_version ?? 1) < 2 && isset($data['payload']) && CanonicalJson::needsLegacyReview($data['payload'])) {
            throw new WorkflowConflict('This historical receipt requires review before replaying list payloads. The original payload shape was not retained.');
        }
    }

    private function existingStart(string $scope, string $key, string $fingerprint, bool $current = false): ?WorkflowRun
    {
        $query = WorkflowRun::query()->inScope($scope)->where('start_key_hash', hash('sha256', $key));
        if ($current) {
            $query->sharedLock();
        }
        $existing = $query->first();
        if ($existing && ! $current) {
            $existing = WorkflowRun::query()->inScope($scope)->whereKey($existing->getKey())->sharedLock()->firstOrFail();
        }
        if ($existing && ! hash_equals($existing->start_fingerprint, $fingerprint)) {
            throw new WorkflowConflict('This start request key has already been used for different input.');
        }

        return $existing;
    }

    public function assertSameConnection(Model $subject): void
    {
        $connection = (new WorkflowRun)->getConnection()->getName();
        foreach ([$subject, new FlowInstance, new FlowInstanceStep] as $model) {
            if ($model->getConnection()->getName() !== $connection) {
                throw new InvalidArgumentException('Workflow, Flow and subject records must share one database connection.');
            }
        }
    }

    private function context(string $scope, string $actor, string $key): void
    {
        $this->text($scope, 191, 'scope');
        $this->text($actor, 191, 'actor');
        $this->text($key, 255, 'request key');
    }

    private function text(string $value, int $length, string $label): void
    {
        if (trim($value) === '' || mb_strlen($value) > $length || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException("Invalid workflow {$label}.");
        }
    }
}
