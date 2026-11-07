<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use MrNewport\LaravelFlow\Events\FlowActionEvent;
use MrNewport\LaravelWorkflow\Contracts\ImportAuthorizer;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\Events\WorkflowChanged;
use MrNewport\LaravelWorkflow\Exceptions\WorkflowConflict;
use MrNewport\LaravelWorkflow\Migration\HistoryImporter;
use MrNewport\LaravelWorkflow\Migration\HistorySnapshot;
use MrNewport\LaravelWorkflow\Migration\LegacySnapshotMapper;
use MrNewport\LaravelWorkflow\Models\WorkflowImport;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;
use MrNewport\LaravelWorkflow\Support\CanonicalJson;
use MrNewport\LaravelWorkflow\Tests\TestCase;
use MrNewport\LaravelWorkflow\WorkflowManager;

final class HistoryImporterTest extends TestCase
{
    private function snapshot(Model $subject): HistorySnapshot
    {
        return HistorySnapshot::fromArray(['source' => 'archive:123', 'subject' => ['type' => get_class($subject), 'id' => (string) $subject->getKey()], 'status' => 'active', 'tasks' => [
            ['source_id' => '10', 'step' => 'draft', 'started_at' => '2024-01-01T09:00:00-07:00', 'finished_at' => '2024-01-02T09:00:00-07:00', 'action' => 'submit', 'actor' => 'old-user:1', 'assignees' => [], 'messages' => [['body' => '<b>Historical note</b>', 'author' => 'old-user:1', 'created_at' => '2024-01-02T09:00:00-07:00']]],
            ['source_id' => '11', 'step' => 'review', 'started_at' => '2024-01-02T09:00:00-07:00', 'finished_at' => null, 'action' => null, 'actor' => null, 'assignees' => [['type' => 'user', 'value' => '2']], 'messages' => []],
        ], 'evidence' => ['rejections' => [['source_task' => '10', 'reason' => 'Kept as evidence']], 'legacy_due_at' => '2024-01-05T09:00:00-07:00']]);
    }

    private function allowImports(): void
    {
        $this->allow();
        $this->app->instance(ImportAuthorizer::class, new class implements ImportAuthorizer
        {
            public function authorizeImport(Definition $definition, Model $subject, HistorySnapshot $snapshot, string $scope, string $actor): void {}
        });
    }

    public function test_import_requires_separate_review_authorization(): void
    {
        $this->allow();
        $subject = $this->subject();
        $snapshot = $this->snapshot($subject);
        $this->expectException(AuthorizationException::class);
        $this->app->make(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, 'w1', 'operator', 'import:1', $snapshot->fingerprint());
    }

    public function test_import_retains_history_assignments_messages_and_evidence_then_resumes_flow_without_replaying_notifications(): void
    {
        $this->allowImports();
        $subject = $this->subject();
        $snapshot = $this->snapshot($subject);
        $events = [];
        Event::listen(WorkflowChanged::class, function ($event) use (&$events) {
            $events[] = $event;
        });
        $flowEvents = [];
        Event::listen(FlowActionEvent::class, function ($event) use (&$flowEvents) {
            $flowEvents[] = $event;
        });
        $importer = $this->app->make(HistoryImporter::class);
        $run = $importer->import($this->definition(), $subject, $snapshot, 'w1', 'operator', 'import:1', $snapshot->fingerprint());
        $this->assertSame('active', $run->status);
        $this->assertTrue($run->flowInstance->entity->is($subject));
        $this->assertSame('2024-01-01 16:00:00', $run->flowInstance->steps()->first()->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('submit', $run->flowInstance->steps()->first()->action_taken);
        $this->assertSame(['2'], $run->pendingSteps()->sole()->assignees()->pluck('assignee_value')->all());
        $this->assertSame('<b>Historical note</b>', $run->messages()->sole()->body);
        $this->assertSame(CanonicalJson::encode($snapshot->toArray()), CanonicalJson::encode(WorkflowImport::query()->sole()->snapshot));
        $this->assertSame([], $events);
        $this->assertSame([], $flowEvents);
        $again = $importer->import($this->definition(), $subject, $snapshot, 'w1', 'operator', 'import:1', $snapshot->fingerprint());
        $this->assertSame($run->id, $again->id);
        $this->assertSame(1, WorkflowRun::query()->count());
        $this->assertSame(2, $run->flowInstance->steps()->count());
        $run = $this->app->make(WorkflowManager::class)->act($run, $run->pendingSteps()->sole()->id, 'approve', 'w1', '2', 'approve', 1);
        $this->assertSame('completed', $run->status);
        $this->assertCount(1, $events);
        $this->assertCount(1, $flowEvents);
    }

    public function test_exact_snapshot_review_and_subject_binding_are_required(): void
    {
        $this->allowImports();
        $subject = $this->subject();
        $snapshot = $this->snapshot($subject);
        $importer = $this->app->make(HistoryImporter::class);
        try {
            $importer->import($this->definition(), $subject, $snapshot, 'w1', 'operator', 'import:1', str_repeat('0', 64));
            $this->fail('Unreviewed snapshot accepted');
        } catch (WorkflowConflict) {
        }
        try {
            $importer->import($this->definition(), $this->subject(), $snapshot, 'w1', 'operator', 'import:1', $snapshot->fingerprint());
            $this->fail('Wrong subject accepted');
        } catch (WorkflowConflict) {
        }
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function test_reimport_of_changed_source_or_actor_fails_without_overwriting_history(): void
    {
        $this->allowImports();
        $subject = $this->subject();
        $snapshot = $this->snapshot($subject);
        $importer = $this->app->make(HistoryImporter::class);
        $run = $importer->import($this->definition(), $subject, $snapshot, 'w1', 'operator', 'import:1', $snapshot->fingerprint());
        $changed = $snapshot->toArray();
        $changed['evidence']['new'] = 'different';
        $changed = HistorySnapshot::fromArray($changed);
        try {
            $importer->import($this->definition(), $subject, $changed, 'w1', 'operator', 'import:2', $changed->fingerprint());
            $this->fail('Changed import accepted');
        } catch (WorkflowConflict) {
        }
        try {
            $importer->import($this->definition(), $subject, $snapshot, 'w1', 'other', 'import:1', $snapshot->fingerprint());
            $this->fail('Changed actor accepted');
        } catch (WorkflowConflict) {
        }
        $this->assertSame(CanonicalJson::encode($snapshot->toArray()), CanonicalJson::encode(WorkflowImport::query()->sole()->snapshot));
        $this->assertSame(2, $run->flowInstance->steps()->count());
    }

    public function test_completed_import_keeps_completion_time_and_missing_attribution(): void
    {
        $this->allowImports();
        $subject = $this->subject();
        $data = $this->snapshot($subject)->toArray();
        $data['status'] = 'completed';
        $data['tasks'][1]['finished_at'] = '2024-01-03T10:00:00-07:00';
        $data['tasks'][1]['action'] = null;
        $snapshot = HistorySnapshot::fromArray($data);
        $run = $this->app->make(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, 'w1', 'operator', 'import:1', $snapshot->fingerprint());
        $this->assertSame('completed', $run->status);
        $this->assertSame('2024-01-03 17:00:00', $run->finished_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $run->pendingSteps()->count());
        $this->assertNull(WorkflowImport::query()->sole()->snapshot['tasks'][1]['actor']);
        $this->assertNull($run->flowInstance->steps()->orderByDesc('id')->first()->action_taken);
    }

    public function test_snapshot_validation_rejects_ambiguous_state_and_invalid_timestamps(): void
    {
        $subject = $this->subject();
        $data = $this->snapshot($subject)->toArray();
        foreach (['2024-01-01', '2024-02-30T09:00:00Z', '2024-01-01T09:00:00', '2024-01-01T09:00:00+99:00'] as $date) {
            $changed = $data;
            $changed['tasks'][0]['started_at'] = $date;
            try {
                HistorySnapshot::fromArray($changed);
                $this->fail('Invalid timestamp accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        $data['status'] = 'completed';
        $this->expectException(\InvalidArgumentException::class);
        HistorySnapshot::fromArray($data);
    }

    public function test_legacy_mapper_keeps_all_source_rows_and_requires_explicit_identity_and_time_mappings(): void
    {
        $this->allowImports();
        $subject = $this->subject();
        $tasks = [
            ['id' => 10, 'task' => 'old-draft', 'date_queued' => '2024-01-01 09:00:00.123456', 'date_done' => '2024-01-02 09:00:00', 'action_done' => null, 'user_done' => null, 'role' => 7, 'date_due' => '2024-01-03 09:00:00'],
            ['id' => 11, 'task' => 'old-review', 'date_queued' => '2024-01-02 09:00:00', 'date_done' => null, 'action_done' => null, 'user_done' => null, 'role' => 8, 'role_class' => 'reviewer', 'org' => 5],
        ];
        $messages = [['workflow_task' => 11, 'author_role' => 7, 'message_text' => 'Historical comment', 'create_date' => '2024-01-02 09:00:00']];
        $rejections = [['current_task_id' => 10, 'next_task_id' => 11, 'reject_id' => 'missing', 'reject_text' => 'Original reason']];
        $snapshot = (new LegacySnapshotMapper)->map('legacy:123', $subject, 'active', $tasks, $messages, $rejections,
            fn ($step) => ['old-draft' => 'draft', 'old-review' => 'review'][$step],
            fn ($identity, $kind) => $kind === 'author_role' ? 'legacy-role:'.$identity : 'user:'.$identity,
            fn ($task) => [['type' => 'user', 'value' => $task['role'] === 7 ? '1' : '2']],
            fn ($date) => (new \DateTimeImmutable($date, new \DateTimeZone('America/Phoenix')))->format('Y-m-d\TH:i:s.uP'),
        );
        $run = $this->app->make(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, 'w1', 'operator', 'import:1', $snapshot->fingerprint());
        $record = WorkflowImport::query()->sole();
        $this->assertSame(CanonicalJson::encode($tasks), CanonicalJson::encode($record->snapshot['evidence']['tasks']));
        $this->assertSame(CanonicalJson::encode($rejections), CanonicalJson::encode($record->snapshot['evidence']['rejections']));
        $this->assertSame($snapshot->fingerprint(), $record->snapshot_hash);
        $this->assertSame('10', $record->task_mapping[0]['source_id']);
        $this->assertSame($run->flowInstance->steps()->first()->id, $record->task_mapping[0]['step_id']);
        $this->assertSame('legacy-role:7', $run->messages()->sole()->author);
        // The engine uses second precision; exact source fractions remain in immutable evidence.
        $this->assertSame('2024-01-01 16:00:00', $run->flowInstance->steps()->first()->started_at->format('Y-m-d H:i:s'));
    }

    public function test_duplicate_imported_assignments_are_rejected(): void
    {
        $data = $this->snapshot($this->subject())->toArray();
        $data['tasks'][1]['assignees'][] = $data['tasks'][1]['assignees'][0];
        $this->expectException(\InvalidArgumentException::class);
        HistorySnapshot::fromArray($data);
    }

    public function test_import_evidence_retains_numeric_types_matching_the_reviewed_fingerprint(): void
    {
        $this->allowImports();
        $subject = $this->subject();
        $data = $this->snapshot($subject)->toArray();
        $data['evidence']['numbers'] = ['decimal' => 1.0, 'integer' => 1, 'negative_zero' => -0.0];
        $snapshot = HistorySnapshot::fromArray($data);
        $this->app->make(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, 'w1', 'operator', 'import:1', $snapshot->fingerprint());
        $record = WorkflowImport::query()->sole();
        $this->assertSame(CanonicalJson::encode($data), CanonicalJson::encode($record->snapshot));
        $this->assertSame(hash('sha256', CanonicalJson::encode($record->snapshot)), $record->snapshot_hash);
    }

    public function test_history_snapshot_detaches_nested_references_before_review(): void
    {
        $evidence = ['amount' => 1.0];
        $body = 'Reviewed message';
        $data = $this->snapshot($this->subject())->toArray();
        $data['evidence']['reference'] = &$evidence;
        $data['tasks'][0]['messages'][0]['body'] = &$body;
        $snapshot = HistorySnapshot::fromArray($data);
        $reviewed = $snapshot->toArray();
        $hash = $snapshot->fingerprint();
        $evidence['amount'] = 99;
        $body = 'Changed';
        $this->assertSame($reviewed, $snapshot->toArray());
        $this->assertSame($hash, hash('sha256', CanonicalJson::encode($snapshot->toArray())));
    }

    public function test_import_batches_child_records_and_retains_ids_dates_and_order(): void
    {
        $this->allowImports();
        $subject = $this->subject();
        $data = $this->snapshot($subject)->toArray();
        $data['tasks'][0]['messages'] = [];
        for ($i = 0; $i < 205; $i++) {
            $data['tasks'][0]['assignees'][] = ['type' => 'user', 'value' => 'user:'.$i];
            $data['tasks'][0]['messages'][] = ['author' => 'user:'.$i, 'body' => 'Message '.$i, 'created_at' => '2024-01-02T09:00:00-07:00'];
        }
        $snapshot = HistorySnapshot::fromArray($data);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run = $this->app->make(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, 'w1', 'operator', 'import:1', $snapshot->fingerprint());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $messageInserts = array_filter($queries, fn ($query) => str_starts_with($query['query'], 'insert into '.DB::getQueryGrammar()->wrapTable('workflow_messages')));
        $assignmentInserts = array_filter($queries, fn ($query) => str_starts_with($query['query'], 'insert into '.DB::getQueryGrammar()->wrapTable('flow_step_assignees')));
        $this->assertCount(3, $messageInserts);
        $this->assertCount(3, $assignmentInserts);
        $this->assertSame(205, $run->messages()->count());
        $this->assertSame(205, $run->flowInstance->steps()->first()->assignees()->count());
        $this->assertSame('Message 0', $run->messages()->first()->body);
        $this->assertSame('Message 204', $run->messages()->orderByDesc('id')->reorder('id', 'desc')->first()->body);
        $this->assertSame('2024-01-02 16:00:00', $run->messages()->first()->created_at->format('Y-m-d H:i:s'));
    }

    public function test_timeline_escapes_actions_actors_and_messages(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', '<b>actor</b>', 'start');
        $run = $manager->comment($run, 'w1', '<b>actor</b>', 'note', 1, '<script>alert(1)</script>');
        $html = view('laravel-workflow::timeline', ['run' => $run])->render();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;actor&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('aria-label="Workflow history"', $html);
    }
}
