<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use MrNewport\LaravelFlow\FlowManager;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelWorkflow\Contracts\ActionValidator;
use MrNewport\LaravelWorkflow\Contracts\ImportAuthorizer;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\DefinitionPublisher;
use MrNewport\LaravelWorkflow\Events\WorkflowChanged;
use MrNewport\LaravelWorkflow\Exceptions\WorkflowConflict;
use MrNewport\LaravelWorkflow\Migration\HistoryImporter;
use MrNewport\LaravelWorkflow\Migration\HistorySnapshot;
use MrNewport\LaravelWorkflow\Models\PublishedDefinition;
use MrNewport\LaravelWorkflow\Models\WorkflowCommand;
use MrNewport\LaravelWorkflow\Models\WorkflowImport;
use MrNewport\LaravelWorkflow\Models\WorkflowMessage;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;
use MrNewport\LaravelWorkflow\Support\CanonicalJson;
use MrNewport\LaravelWorkflow\Tests\Subject;
use MrNewport\LaravelWorkflow\Tests\TestCase;
use MrNewport\LaravelWorkflow\WorkflowManager;
use RuntimeException;

class WorkflowRegressionTest extends TestCase
{
    protected function allowImports(): void
    {
        $this->allow();
        $this->app->instance(ImportAuthorizer::class, new class implements ImportAuthorizer
        {
            public function authorizeImport(Definition $definition, Model $subject, HistorySnapshot $snapshot, string $scope, string $actor): void {}
        });
    }

    protected function snapshot(Model $subject, string $finished = '2024-01-02T09:00:00-07:00'): HistorySnapshot
    {
        return HistorySnapshot::fromArray(['source' => 'archive:'.hash('sha256', (string) $subject->getKey().$finished), 'subject' => ['type' => get_class($subject), 'id' => (string) $subject->getKey()], 'status' => 'completed', 'tasks' => [[
            'source_id' => 'one', 'step' => 'draft', 'started_at' => $finished, 'finished_at' => $finished, 'action' => 'submit', 'actor' => 'archived:user', 'assignees' => [],
            'messages' => [['author' => 'archived:user', 'body' => 'Historical note', 'created_at' => $finished]],
        ]], 'evidence' => ['legacy' => ['amount' => 1.0, 'count' => 1]]]);
    }

    public function test_object_list_shape_is_distinct_and_ambiguous_input_is_refused_before_writes(): void
    {
        $object = ['values' => [1 => 'one', 0 => 'zero']];
        $list = ['values' => ['zero', 'one']];
        $this->assertNotSame(CanonicalJson::encode($object), CanonicalJson::encode($list));
        $this->allow();
        $manager = app(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', 'actor', 'start');
        try {
            $manager->act($run, $run->pendingSteps()->sole()->id, 'submit', 'w1', 'actor', 'action', 1, $object);
            $this->fail('Ambiguous object accepted');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(1, $run->fresh()->version);
        $this->assertSame(1, $run->commands()->count());
        $data = $this->snapshot($this->subject())->toArray();
        $data['evidence'] = $object;
        $this->expectException(InvalidArgumentException::class);
        HistorySnapshot::fromArray($data);
    }

    public function test_normal_saved_definition_import_and_safe_legacy_receipt_keep_fingerprints(): void
    {
        $this->allowImports();
        $definition = $this->definition();
        $subject = $this->subject();
        $snapshot = $this->snapshot($subject);
        $this->assertSame(hash('sha256', '{"a":{"amount":1.0,"count":1},"z":[3,1]}'), hash('sha256', CanonicalJson::encode(['z' => [3, 1], 'a' => ['count' => 1, 'amount' => 1.0]])));
        $run = app(HistoryImporter::class)->import($definition, $subject, $snapshot, 'w1', 'actor', 'import', $snapshot->fingerprint());
        $saved = WorkflowImport::query()->sole();
        $this->assertSame($snapshot->fingerprint(), hash('sha256', CanonicalJson::encode($saved->snapshot)));
        $this->assertSame($definition->fingerprint(), PublishedDefinition::query()->sole()->definition()->fingerprint());
        $manager = app(WorkflowManager::class);
        $commented = $manager->comment($run, 'w1', 'actor', 'note', 1, 'Saved comment');
        DB::table('workflow_commands')->where('action', 'comment')->update(['fingerprint_version' => 1]);
        $this->assertSame($commented->id, $manager->comment($run, 'w1', 'actor', 'note', 1, 'Saved comment')->id);
    }

    public function test_new_list_receipts_replay_but_ambiguous_legacy_list_receipts_require_review(): void
    {
        $this->allow();
        app()->instance(ActionValidator::class, new class implements ActionValidator
        {
            public function validate(WorkflowRun $run, FlowInstanceStep $step, string $action, array $payload): array
            {
                return $payload;
            }
        });
        $manager = app(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', 'actor', 'start');
        $step = $run->pendingSteps()->sole();
        $payload = ['choices' => ['a', 'b']];
        $done = $manager->act($run, $step->id, 'submit', 'w1', 'actor', 'action', 1, $payload);
        $this->assertSame(2, $manager->act($run, $step->id, 'submit', 'w1', 'actor', 'action', 1, $payload)->version);
        DB::table('workflow_commands')->where('action', 'submit')->update(['fingerprint_version' => 1]);
        try {
            $manager->act($run, $step->id, 'submit', 'w1', 'actor', 'action', 1, $payload);
            $this->fail('Ambiguous historical receipt accepted');
        } catch (WorkflowConflict $exception) {
            $this->assertStringContainsString('requires review', $exception->getMessage());
        }
        $this->assertSame(2, $done->fresh()->version);
    }

    public function test_non_utc_and_dst_imports_preserve_instants_and_wide_dates(): void
    {
        $this->allowImports();
        $old = date_default_timezone_get();
        try {
            foreach (['America/Phoenix', 'America/New_York'] as $zone) {
                config(['app.timezone' => $zone]);
                date_default_timezone_set($zone);
                foreach (['1969-01-02T09:00:00-07:00', '2039-01-02T09:00:00-07:00', '2024-11-03T01:30:00-04:00'] as $date) {
                    $snapshot = $this->snapshot($this->subject(), $date);
                    $subject = Subject::query()->latest('id')->firstOrFail();
                    $run = app(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, $zone, 'actor', $date, $snapshot->fingerprint());
                    $expected = (new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                    $this->assertSame($expected, $run->finished_at->utc()->format('Y-m-d H:i:s'));
                    $this->assertSame($expected, $run->messages()->sole()->created_at->utc()->format('Y-m-d H:i:s'));
                    $this->assertSame($expected, $run->flowInstance->steps()->sole()->finished_at->utc()->format('Y-m-d H:i:s'));
                }
            }
        } finally {
            date_default_timezone_set($old);
        }
    }

    public function test_zero_label_completes_and_new_graph_verification_rolls_back_modified_rows(): void
    {
        $this->allow();
        $manager = app(WorkflowManager::class);
        $definition = Definition::fromArray(['name' => 'zero', 'version' => 1, 'initial' => 'draft', 'steps' => [['id' => 'draft', 'name' => '0', 'transitions' => ['done' => ['END']]]]]);
        $run = $manager->start($definition, $this->subject(), 'w1', 'actor', 'start');
        $this->assertSame('completed', $manager->act($run, $run->pendingSteps()->sole()->id, 'done', 'w1', 'actor', 'done', 1)->status);
        FlowStep::creating(function ($step) {
            $step->name = 'Changed';
        });
        try {
            app(DefinitionPublisher::class)->publish($this->definition(['name' => 'tampered']));
            $this->fail('Changed engine rows accepted');
        } catch (WorkflowConflict) {
        }
        $this->assertSame(1, PublishedDefinition::query()->count());
    }

    public function test_import_reuses_native_subject_and_assignment_validation(): void
    {
        $this->allowImports();
        Schema::create('string_subjects', function (Blueprint $table) {
            $table->string('id')->primary();
        });
        $subject = RegressionStringSubject::query()->create(['id' => '0001']);
        $snapshot = $this->snapshot($subject);
        try {
            app(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, 'w1', 'actor', 'import', $snapshot->fingerprint());
            $this->fail('Coerced key accepted');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(0, WorkflowRun::query()->count());
        $data = $this->snapshot($this->subject())->toArray();
        $data['tasks'][0]['assignees'] = [['type' => 'user', 'value' => "invalid\nuser"]];
        $this->expectException(InvalidArgumentException::class);
        HistorySnapshot::fromArray($data);
    }

    public function test_cancelled_receipt_and_message_writes_roll_back_callbacks_progress_and_events(): void
    {
        foreach (['receipt', 'action-message', 'comment-message'] as $case) {
            $this->allow();
            $manager = app(WorkflowManager::class);
            $subject = $this->subject();
            $run = $manager->start($this->definition(), $subject, $case, 'actor', 'start');
            $step = $run->pendingSteps()->sole();
            $events = [];
            Event::listen(WorkflowChanged::class, function ($event) use (&$events) {
                $events[] = $event;
            });
            $model = $case === 'receipt' ? WorkflowCommand::class : WorkflowMessage::class;
            $dispatcher = $model::getEventDispatcher();
            $model::setEventDispatcher(clone $dispatcher);
            $model::creating(fn () => false);
            try {
                if ($case === 'comment-message') {
                    $manager->comment($run, $case, 'actor', 'action', 1, 'Required note');
                } else {
                    $manager->act($run, $step->id, 'submit', $case, 'actor', 'action', 1, withinTransaction: fn () => $subject->update(['status' => 'published']), message: 'Required note');
                }
                $this->fail('Cancelled write accepted');
            } catch (RuntimeException) {
            } finally {
                $model::setEventDispatcher($dispatcher);
            }
            $this->assertSame('draft', $subject->fresh()->status);
            $this->assertSame(1, $run->fresh()->version);
            $this->assertNull($step->fresh()->finished_at);
            $this->assertSame(1, $run->commands()->count());
            $this->assertSame(0, $run->messages()->count());
            $this->assertSame([], $events);
            $retried = $manager->comment($run, $case, 'actor', 'action', 1, 'Required note');
            $this->assertSame(2, $retried->version);
        }
    }

    public function test_saving_veto_on_a_receipt_rolls_back_a_public_comment(): void
    {
        $this->allow();
        $manager = app(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', 'actor', 'start');
        WorkflowCommand::saving(fn () => false);
        try {
            $manager->comment($run, 'w1', 'actor', 'note', 1, 'Required note');
            $this->fail('Cancelled save accepted');
        } catch (RuntimeException) {
        }
        $this->assertSame(1, $run->fresh()->version);
        $this->assertSame(1, $run->commands()->count());
        $this->assertSame(0, $run->messages()->count());
    }

    public function test_history_bounds_commands_and_message_previews_and_exposes_all_message_pages(): void
    {
        $this->allow();
        $manager = app(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', 'actor', 'start');
        for ($i = 0; $i < 7; $i++) {
            $run = $manager->comment($run, 'w1', 'actor', 'note:'.$i, $run->version, 'Note '.$i);
        }
        $firstCommand = $run->commands()->firstOrFail();
        for ($i = 0; $i < 70; $i++) {
            WorkflowMessage::query()->create(['run_id' => $run->id, 'command_id' => $firstCommand->id, 'author' => 'actor', 'body' => 'Imported '.$i, 'created_at' => now()]);
        }
        DB::enableQueryLog();
        $history = $run->history(3, 1);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $history);
        $this->assertSame(8, $history->total());
        $this->assertCount(50, $history[0]->messages);
        $this->assertSame(70, $history[0]->messages_count);
        $this->assertLessThanOrEqual(3, count($queries));
        $this->assertFalse($run->relationLoaded('messages'));
        $this->assertFalse($run->relationLoaded('commands'));
        $messages = $run->historyMessages($firstCommand->id, 50, 2);
        $this->assertCount(20, $messages);
        $this->assertSame(70, $messages->total());
        $html = view('laravel-workflow::timeline', ['run' => $run, 'history' => $history])->render();
        $this->assertStringContainsString('Showing 50 of 70 messages', $html);
        $this->assertStringContainsString('workflow_page=', $html);
        app('translator')->addLines(['messages.history' => 'Historique'], 'fr', 'laravel-workflow');
        app()->setLocale('fr');
        $this->assertStringContainsString('aria-label="Historique"', view('laravel-workflow::timeline', ['run' => $run, 'history' => $history])->render());
    }

    public function test_ambiguous_dst_instants_fail_before_writing_and_utc_can_import_them(): void
    {
        $this->allowImports();
        $old = date_default_timezone_get();
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');
        try {
            $subject = $this->subject();
            $snapshot = $this->snapshot($subject, '2024-11-03T01:30:00-05:00');
            try {
                app(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, 'w1', 'actor', 'import', $snapshot->fingerprint());
                $this->fail('Ambiguous local instant accepted');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('cannot round-trip', $exception->getMessage());
            }
            $this->assertSame(0, WorkflowRun::query()->count());
            config(['app.timezone' => 'UTC']);
            date_default_timezone_set('UTC');
            $run = app(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, 'w1', 'actor', 'import', $snapshot->fingerprint());
            $this->assertSame('2024-11-03 06:30:00', $run->finished_at->utc()->format('Y-m-d H:i:s'));
        } finally {
            date_default_timezone_set($old);
        }
    }

    public function test_forward_migration_keeps_existing_data_and_marks_saved_receipts_version_one(): void
    {
        $this->allow();
        $manager = app(WorkflowManager::class);
        $subject = $this->subject();
        $run = $manager->start($this->definition(), $subject, 'w1', 'actor', 'start');
        $run = $manager->comment($run, 'w1', 'actor', 'note', 1, 'Saved note');
        $before = $run->commands()->get()->map(fn ($row) => ['id' => $row->id, 'fingerprint' => $row->fingerprint])->all();
        $migration = require __DIR__.'/../../database/migrations/2026_11_06_000002_preserve_workflow_fingerprint_versions_and_dates.php';
        $migration->down();
        $migration->up();
        $after = $run->commands()->get()->map(fn ($row) => ['id' => $row->id, 'fingerprint' => $row->fingerprint])->all();
        $this->assertSame($before, $after);
        $this->assertSame([1, 1], $run->commands()->pluck('fingerprint_version')->all());
        $this->assertSame('Saved note', $run->messages()->sole()->body);
        $this->assertSame(2, $manager->comment($run, 'w1', 'actor', 'note', 1, 'Saved note')->version);
        $this->assertTrue(Schema::hasIndex('workflow_commands', 'workflow_commands_history'));
    }

    public function test_saved_numeric_object_import_requires_review_instead_of_list_reinterpretation(): void
    {
        $this->allowImports();
        $subject = $this->subject();
        $snapshot = $this->snapshot($subject);
        $run = app(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, 'w1', 'actor', 'import', $snapshot->fingerprint());
        $stored = $snapshot->toArray();
        $stored['evidence']['values'] = [1 => 'one', 0 => 'zero'];
        DB::table('workflow_imports')->where('run_id', $run->id)->update(['snapshot' => json_encode($stored, JSON_THROW_ON_ERROR)]);
        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('numeric-key object');
        app(HistoryImporter::class)->import($this->definition(), $subject, $snapshot, 'w1', 'actor', 'import', $snapshot->fingerprint());
    }

    public function test_normalized_definition_byte_budget_is_enforced_separately_from_edge_budget(): void
    {
        config(['workflow.max_definition_edges' => 20000]);
        $steps = [];
        for ($i = 0; $i < 250; $i++) {
            $actions = [];
            for ($j = 0; $j < 50; $j++) {
                $actions['action'.str_repeat('a', 90).$j] = [$i === 249 || $j > 0 ? 'END' : 'step'.($i + 1)];
            }
            $steps[] = ['id' => 'step'.$i, 'transitions' => $actions];
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('normalized definition');
        Definition::fromArray(['name' => 'byte-budget', 'version' => 1, 'initial' => 'step0', 'steps' => $steps]);
    }

    public function test_lowered_assignment_admission_budget_does_not_invalidate_saved_snapshots_or_comments(): void
    {
        $this->allow();
        $manager = app(WorkflowManager::class);
        foreach (['multi_user' => ['user_ids' => [1, 2]], 'email_list' => ['emails' => ['one@example.com', 'two@example.com']]] as $strategy => $params) {
            config(['flow.max_assignments' => 2]);
            $definition = Definition::fromArray(['name' => 'saved-'.$strategy, 'version' => 1, 'initial' => 'review', 'steps' => [['id' => 'review', 'assignment_strategy' => $strategy, 'assignment_params' => $params, 'transitions' => ['approve' => ['END']]]]]);
            $run = $manager->start($definition, $this->subject(), $strategy, 'actor', 'start');
            $step = $run->pendingSteps()->sole();
            config(['flow.max_assignments' => 1]);
            $this->assertSame($definition->fingerprint(), $run->publishedDefinition->fresh()->definition()->fingerprint());
            $this->assertSame(2, $manager->comment($run, $strategy, 'actor', 'note', 1, 'Saved definitions remain readable.')->version);
            try {
                Definition::fromArray($definition->toArray());
                $this->fail('New over-budget definition admitted');
            } catch (InvalidArgumentException) {
            }
            $proposed = $strategy === 'email_list' ? ['three@example.com', 'four@example.com'] : [3, 4];
            try {
                $manager->reassign($run, $step->id, $proposed, $strategy, 'actor', 'reassign', 2);
                $this->fail('Over-budget replacement admitted');
            } catch (InvalidArgumentException) {
            }
            $this->assertSame(2, $step->assignees()->count());
            $instances = FlowInstance::query()->count();
            try {
                FlowManager::startFlow($step->step_id, $this->subject());
                $this->fail('Over-budget native write admitted');
            } catch (InvalidArgumentException) {
            }
            $this->assertSame($instances, FlowInstance::query()->count());
            $this->assertSame(2, $run->fresh()->version);
        }
    }

    public function test_admission_budgets_do_not_invalidate_saved_definitions(): void
    {
        $definition = $this->definition();
        $publisher = app(DefinitionPublisher::class);
        $saved = $publisher->publish($definition);
        config(['workflow.max_definition_edges' => 1]);
        $this->assertSame($definition->fingerprint(), $saved->fresh()->definition()->fingerprint());
        $this->expectException(InvalidArgumentException::class);
        Definition::fromArray($definition->toArray());
    }
}

class RegressionStringSubject extends Model
{
    protected $table = 'string_subjects';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;
}
