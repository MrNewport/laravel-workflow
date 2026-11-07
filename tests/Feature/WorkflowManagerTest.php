<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MrNewport\LaravelFlow\Events\FlowActionEvent;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelWorkflow\Contracts\ActionValidator;
use MrNewport\LaravelWorkflow\Contracts\AssignmentAuthorizer;
use MrNewport\LaravelWorkflow\Contracts\WorkflowAuthorizer;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\DefinitionPublisher;
use MrNewport\LaravelWorkflow\Events\WorkflowChanged;
use MrNewport\LaravelWorkflow\Exceptions\WorkflowConflict;
use MrNewport\LaravelWorkflow\Models\WorkflowCommand;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;
use MrNewport\LaravelWorkflow\Tests\TestCase;
use MrNewport\LaravelWorkflow\WorkflowManager;

final class WorkflowManagerTest extends TestCase
{
    public function test_starts_are_denied_until_a_host_policy_is_bound(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->app->make(WorkflowManager::class)->start($this->definition(), $this->subject(), 'workspace:1', '1', 'start:1');
    }

    public function test_start_and_action_reuse_actual_flow_models_assignments_and_events(): void
    {
        $this->allow();
        $events = [];
        Event::listen(WorkflowChanged::class, function ($event) use (&$events) {
            $events[] = $event;
        });
        $flowEvents = [];
        Event::listen(FlowActionEvent::class, function ($event) use (&$flowEvents) {
            $flowEvents[] = $event;
        });
        $manager = $this->app->make(WorkflowManager::class);
        $subject = $this->subject();
        $run = $manager->start($this->definition(), $subject, 'workspace:1', '1', 'start:1');
        $this->assertSame(1, FlowInstance::query()->count());
        $this->assertTrue($run->flowInstance->entity->is($subject));
        $draft = $run->pendingSteps()->sole();
        $run = $manager->act($run, $draft->id, 'submit', 'workspace:1', '1', 'submit:1', 1, message: '<script>text</script>');
        $this->assertSame(2, $run->version);
        $this->assertNotNull($draft->fresh()->finished_at);
        $review = $run->pendingSteps()->sole();
        $this->assertSame(['1', '2'], $review->assignees()->pluck('assignee_value')->all());
        $this->assertSame('<script>text</script>', $run->messages()->sole()->body);
        $run = $manager->act($run, $review->id, 'approve', 'workspace:1', '1', 'approve:1', 2);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(3, $run->commands()->count());
        $this->assertCount(3, $events);
        $this->assertCount(2, $flowEvents);
    }

    public function test_identical_retries_return_same_run_and_do_not_repeat_callbacks_or_messages(): void
    {
        $policy = $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $subject = $this->subject();
        $calls = 0;
        $run = $manager->start($this->definition(), $subject, 'w1', '1', 'request-1', function () use (&$calls) {
            $calls++;
        });
        $again = $manager->start($this->definition(), $subject, 'w1', '1', 'request-1', function () use (&$calls) {
            $calls++;
        });
        $this->assertSame($run->id, $again->id);
        $this->assertSame(1, $calls);
        $step = $run->pendingSteps()->sole();
        $callback = function () use (&$calls) {
            $calls++;
        };
        $run = $manager->act($run, $step->id, 'submit', 'w1', '1', 'request-2', 1, withinTransaction: $callback, message: 'Submitted');
        $again = $manager->act($run, $step->id, 'submit', 'w1', '1', 'request-2', 1, withinTransaction: $callback, message: 'Submitted');
        $this->assertSame(2, $again->version);
        $this->assertSame(2, $calls);
        $this->assertSame(2, $run->commands()->count());
        $this->assertSame(1, $run->messages()->count());
        $policy->allowed = false;
        $this->expectException(AuthorizationException::class);
        $manager->act($run, $step->id, 'submit', 'w1', '1', 'request-2', 1, message: 'Submitted');
    }

    public function test_start_request_keys_cannot_be_reused_for_a_different_subject(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $manager->start($this->definition(), $this->subject(), 'w1', '1', 'same');
        $this->expectException(WorkflowConflict::class);
        $manager->start($this->definition(), $this->subject(), 'w1', '1', 'same');
    }

    public function test_scopes_are_case_sensitive_and_run_step_lookup_is_scoped(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'Workspace', '1', 'same');
        $other = $manager->start($this->definition(), $this->subject(), 'workspace', '1', 'same');
        $this->assertNotSame($run->id, $other->id);
        try {
            $manager->act($run, $run->pendingSteps()->sole()->id, 'submit', 'workspace', '1', 'go', 1);
            $this->fail('Wrong scope accepted');
        } catch (ModelNotFoundException) {
        }
        $this->expectException(ModelNotFoundException::class);
        $manager->act($run, $other->pendingSteps()->sole()->id, 'submit', 'Workspace', '1', 'go', 1);
    }

    public function test_stale_version_and_changed_retry_inputs_leave_steps_pending(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', '1', 'start');
        $step = $run->pendingSteps()->sole();
        $run = $manager->comment($run, 'w1', '1', 'note', 1, 'Waiting');
        try {
            $manager->act($run, $step->id, 'submit', 'w1', '1', 'go', 1);
            $this->fail('Stale version accepted');
        } catch (WorkflowConflict) {
        }
        $this->assertNull($step->fresh()->finished_at);
        try {
            $manager->comment($run, 'w1', '1', 'note', 1, 'Changed');
            $this->fail('Changed key accepted');
        } catch (WorkflowConflict) {
        }
        $this->assertSame('Waiting', $run->messages()->sole()->body);
        $this->assertSame(2, $run->fresh()->version);
    }

    public function test_undefined_actions_and_unvalidated_payloads_fail_without_side_effects(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', '1', 'start');
        $step = $run->pendingSteps()->sole();
        try {
            $manager->act($run, $step->id, 'typo', 'w1', '1', 'go', 1);
            $this->fail('Invalid action accepted');
        } catch (\InvalidArgumentException) {
        }
        try {
            $manager->act($run, $step->id, 'submit', 'w1', '1', 'go', 1, ['untrusted' => 'input']);
            $this->fail('Unvalidated input accepted');
        } catch (ValidationException) {
        }
        $this->assertNull($step->fresh()->finished_at);
        $this->assertSame(1, $run->commands()->count());
    }

    public function test_failed_host_callback_rolls_back_subject_step_receipt_and_after_commit_events(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $subject = $this->subject();
        $run = $manager->start($this->definition(), $subject, 'w1', '1', 'start');
        $step = $run->pendingSteps()->sole();
        $events = [];
        Event::listen(WorkflowChanged::class, function ($event) use (&$events) {
            $events[] = $event;
        });
        try {
            $manager->act($run, $step->id, 'submit', 'w1', '1', 'go', 1, withinTransaction: function () use ($subject) {
                $subject->update(['status' => 'changed']);
                throw new \RuntimeException('failure');
            });
            $this->fail('Callback failure ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('failure', $e->getMessage());
        }
        $this->assertSame('draft', $subject->fresh()->status);
        $this->assertNull($step->fresh()->finished_at);
        $this->assertSame(1, $run->commands()->count());
        $this->assertSame([], $events);
    }

    public function test_outer_transaction_rollback_discards_engine_and_coordination_events(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', '1', 'start');
        $step = $run->pendingSteps()->sole();
        $events = [];
        Event::listen(WorkflowChanged::class, function ($event) use (&$events) {
            $events[] = $event;
        });
        $flowEvents = [];
        Event::listen(FlowActionEvent::class, function ($event) use (&$flowEvents) {
            $flowEvents[] = $event;
        });
        DB::beginTransaction();
        $manager->act($run, $step->id, 'submit', 'w1', '1', 'go', 1);
        $this->assertSame([], $events);
        $this->assertSame([], $flowEvents);
        DB::rollBack();
        $this->assertNull($step->fresh()->finished_at);
        $this->assertSame(1, $run->fresh()->version);
        $this->assertSame([], $events);
        $this->assertSame([], $flowEvents);
    }

    public function test_one_branch_ending_does_not_complete_a_run_with_pending_siblings(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $definition = Definition::fromArray(['name' => 'parallel', 'version' => 1, 'initial' => 'draft', 'steps' => [
            ['id' => 'draft', 'transitions' => ['split' => ['a', 'b']]],
            ['id' => 'a', 'transitions' => ['done' => ['END']]],
            ['id' => 'b', 'transitions' => ['done' => ['END']]],
        ]]);
        $run = $manager->start($definition, $this->subject(), 'w1', '1', 'start');
        $run = $manager->act($run, $run->pendingSteps()->sole()->id, 'split', 'w1', '1', 'split', 1);
        $this->assertSame(2, $run->pendingSteps()->count());
        $run = $manager->act($run, $run->pendingSteps()->first()->id, 'done', 'w1', '1', 'a', 2);
        $this->assertSame('END', $run->flowInstance->current_step_id);
        $this->assertSame('active', $run->status);
        $this->assertNull($run->finished_at);
        $run = $manager->act($run, $run->pendingSteps()->sole()->id, 'done', 'w1', '1', 'b', 3);
        $this->assertSame('completed', $run->status);
    }

    public function test_rejection_creates_new_history_and_cancellation_closes_all_branches(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', '1', 'start');
        $run = $manager->act($run, $run->pendingSteps()->sole()->id, 'submit', 'w1', '1', 'go', 1);
        $run = $manager->act($run, $run->pendingSteps()->sole()->id, 'reject', 'w1', '1', 'reject', 2, message: 'Please revise');
        $this->assertSame(3, $run->flowInstance->steps()->count());
        $run = $manager->cancel($run, 'w1', '1', 'cancel', 3, 'Withdrawn');
        $this->assertSame('cancelled', $run->status);
        $this->assertSame(0, $run->pendingSteps()->count());
        $this->assertSame(2, $run->messages()->count());
        $run = $manager->comment($run, 'w1', '1', 'note', 4, 'Retained after cancellation');
        $this->assertSame('cancelled', $run->status);
        $this->assertSame(5, $run->version);
    }

    public function test_cancellation_requires_explicit_support_on_every_branch(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $definition = Definition::fromArray(['name' => 'parallel', 'version' => 1, 'initial' => 'draft', 'steps' => [
            ['id' => 'draft', 'transitions' => ['split' => ['a', 'b']]],
            ['id' => 'a', 'transitions' => ['cancel' => ['END']]],
            ['id' => 'b', 'transitions' => ['done' => ['END']]],
        ]]);
        $run = $manager->start($definition, $this->subject(), 'w1', '1', 'start');
        $run = $manager->act($run, $run->pendingSteps()->sole()->id, 'split', 'w1', '1', 'split', 1);
        try {
            $manager->cancel($run, 'w1', '1', 'cancel', 2);
            $this->fail('Missing cancellation accepted');
        } catch (WorkflowConflict) {
        }
        $this->assertSame(2, $run->pendingSteps()->count());
        $this->assertSame(2, $run->fresh()->version);
    }

    public function test_reassignment_requires_destination_policy_and_audits_before_and_after(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', '1', 'start');
        $run = $manager->act($run, $run->pendingSteps()->sole()->id, 'submit', 'w1', '1', 'submit', 1);
        $step = $run->pendingSteps()->sole();
        try {
            $manager->reassign($run, $step->id, [3], 'w1', '1', 'assign', 2);
            $this->fail('Default destination policy accepted');
        } catch (AuthorizationException) {
        }
        $policy = new class implements AssignmentAuthorizer
        {
            public function authorizeAssignment(WorkflowRun $run, FlowInstanceStep $step, string $actor, array $current, array $proposed): void
            {
                if ($proposed !== [['type' => 'user', 'value' => '3']]) {
                    throw new AuthorizationException;
                }
            }
        };
        $this->app->instance(AssignmentAuthorizer::class, $policy);
        $manager = $this->app->make(WorkflowManager::class);
        try {
            $manager->reassign($run, $step->id, [999], 'w1', '1', 'assign', 2);
            $this->fail('Foreign destination accepted');
        } catch (AuthorizationException) {
        }
        $run = $manager->reassign($run, $step->id, [3], 'w1', '1', 'assign', 2);
        $this->assertSame(['3'], $step->assignees()->pluck('assignee_value')->all());
        $this->assertSame([['type' => 'user', 'value' => '3']], $run->commands()->where('action', 'reassign')->sole()->details['assignments_after']);
        $this->assertSame(3, $run->version);
    }

    public function test_definitions_are_versioned_immutable_and_detect_raw_database_tampering(): void
    {
        $this->allow();
        $publisher = new DefinitionPublisher;
        $published = $publisher->publish($this->definition());
        $this->assertSame($published->id, $publisher->publish($this->definition())->id);
        $changed = $this->definition()->toArray();
        $changed['steps'][0]['name'] = 'Changed';
        try {
            $publisher->publish(Definition::fromArray($changed));
            $this->fail('Changed published version accepted');
        } catch (WorkflowConflict) {
        }
        try {
            $published->update(['name' => 'other']);
            $this->fail('Immutable definition changed');
        } catch (\LogicException) {
        }
        DB::table('workflow_definitions')->where('id', $published->id)->update(['name' => 'tampered']);
        $this->expectException(\LogicException::class);
        $published->fresh()->definition();
    }

    public function test_external_engine_edits_fail_closed_before_a_host_callback_runs(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', '1', 'start');
        $step = $run->pendingSteps()->sole();
        FlowTransition::query()->where('step_id', $step->step_id)->where('action', 'submit')->update(['next_step_id' => 'END']);
        $called = false;
        try {
            $manager->act($run, $step->id, 'submit', 'w1', '1', 'go', 1, withinTransaction: function () use (&$called) {
                $called = true;
            });
            $this->fail('Engine tampering accepted');
        } catch (WorkflowConflict) {
        }
        $this->assertFalse($called);
        $this->assertNull($step->fresh()->finished_at);
    }

    public function test_cross_connection_subjects_and_non_json_payloads_are_rejected(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $subject = $this->subject();
        $subject->setConnection('other');
        try {
            $manager->start($this->definition(), $subject, 'w1', '1', 'start');
            $this->fail('Cross connection subject accepted');
        } catch (\InvalidArgumentException) {
        }
        $run = $manager->start($this->definition(), $this->subject(), 'w1', '1', 'start');
        $this->expectException(\InvalidArgumentException::class);
        $manager->act($run, $run->pendingSteps()->sole()->id, 'submit', 'w1', '1', 'go', 1, ['object' => new \stdClass]);
    }

    public function test_unique_constraint_failure_in_a_start_callback_is_not_treated_as_replay(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $subject = $this->subject();
        try {
            $manager->start($this->definition(), $subject, 'w1', '1', 'start', fn () => DB::table('subjects')->insert(['id' => $subject->id, 'status' => 'duplicate']));
            $this->fail('Callback database error was suppressed');
        } catch (UniqueConstraintViolationException) {
        }
        $this->assertSame(0, WorkflowRun::query()->count());
        $this->assertSame(0, FlowInstance::query()->count());
    }

    public function test_after_commit_database_failure_is_reported_for_a_committed_start(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $subject = $this->subject();
        Event::listen(WorkflowChanged::class, fn () => DB::table('subjects')->insert(['id' => $subject->id, 'status' => 'duplicate']));
        try {
            $manager->start($this->definition(), $subject, 'w1', '1', 'start');
            $this->fail('After-commit database error was suppressed');
        } catch (UniqueConstraintViolationException) {
        }
        $this->assertSame(1, WorkflowRun::query()->count());
        $this->assertSame(1, WorkflowCommand::query()->count());
    }

    public function test_after_commit_database_failure_is_reported_for_a_committed_action(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $subject = $this->subject();
        $run = $manager->start($this->definition(), $subject, 'w1', '1', 'start');
        $step = $run->pendingSteps()->sole();
        Event::listen(WorkflowChanged::class, fn () => DB::table('subjects')->insert(['id' => $subject->id, 'status' => 'duplicate']));
        try {
            $manager->act($run, $step->id, 'submit', 'w1', '1', 'submit', 1);
            $this->fail('After-commit database error was suppressed');
        } catch (UniqueConstraintViolationException) {
        }
        $this->assertSame(2, $run->fresh()->version);
        $this->assertSame(2, $run->commands()->count());
        $this->assertNotNull($step->fresh()->finished_at);
    }

    public function test_action_payload_is_detached_before_policy_hooks_can_mutate_referenced_values(): void
    {
        $this->allow();
        $manager = $this->app->make(WorkflowManager::class);
        $run = $manager->start($this->definition(), $this->subject(), 'w1', '1', 'start');
        $value = 'reviewed';
        $payload = ['value' => &$value];
        $hook = function () use (&$value) {
            $value = 'changed';
        };
        $this->app->instance(WorkflowAuthorizer::class, new class($hook) implements WorkflowAuthorizer
        {
            public function __construct(private \Closure $hook) {}

            public function authorizeStart(Definition $definition, Model $subject, string $scope, string $actor): void {}

            public function authorize(WorkflowRun $run, ?FlowInstanceStep $step, string $action, string $actor): void
            {
                ($this->hook)();
            }
        });
        $this->app->instance(ActionValidator::class, new class implements ActionValidator
        {
            public function validate(WorkflowRun $run, FlowInstanceStep $step, string $action, array $payload): array
            {
                return $payload;
            }
        });
        $validated = null;
        $this->app->make(WorkflowManager::class)->act($run, $run->pendingSteps()->sole()->id, 'submit', 'w1', '1', 'submit', 1, $payload, function ($run, $step, array $input) use (&$validated) {
            $validated = $input;
        });
        $this->assertSame('changed', $value);
        $this->assertSame(['value' => 'reviewed'], $validated);
    }
}
