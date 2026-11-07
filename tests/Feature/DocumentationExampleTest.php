<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use MrNewport\LaravelWorkflow\Examples\Procurement\ProcurementExample;
use MrNewport\LaravelWorkflow\Examples\Procurement\Purchase;
use MrNewport\LaravelWorkflow\Exceptions\WorkflowConflict;
use MrNewport\LaravelWorkflow\Tests\TestCase;
use MrNewport\LaravelWorkflow\WorkflowManager;

require_once __DIR__.'/../../examples/procurement/ProcurementExample.php';

final class DocumentationExampleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProcurementExample::installSchema();
    }

    public function test_documented_procurement_source_executes_domain_review_and_exact_replay(): void
    {
        $result = ProcurementExample::run();
        $run = $result['run'];
        $this->assertSame('approved', $result['purchase']->status);
        $this->assertSame(225000, $result['purchase']->amount_cents);
        $this->assertSame('completed', $run->status);
        $this->assertSame(8, $run->version);
        $this->assertSame(1, $result['outbox_count']);
        $this->assertSame(8, $run->commands()->count());
        $this->assertSame(5, $run->messages()->count());
        $this->assertSame(4, $run->history(2)->lastPage());
        $this->assertSame(3, $run->historyMessages(perPage: 2)->lastPage());
        $this->assertSame('backup', $run->commands()->where('action', 'reassign')->sole()->details['assignments_after'][0]['value']);
        $this->expectException(WorkflowConflict::class);
        app(WorkflowManager::class)->comment($run, 'demo', 'requester', 'new-note', 7, 'Stale decision');
    }

    public function test_current_membership_is_required_even_for_a_replay(): void
    {
        $result = ProcurementExample::run();
        DB::table('example_procurement_members')->where('actor', 'backup')->delete();
        $receipt = $result['run']->commands()->where('action', 'approve')->sole();
        $this->expectException(AuthorizationException::class);
        app(WorkflowManager::class)->act($result['run'], $receipt->step_id, 'approve', 'demo', 'backup',
            'purchase:'.$result['purchase']->id.':approve', 6, message: 'Approved within delegated budget');
    }

    public function test_example_policies_refuse_foreign_destinations_and_callbacks_roll_back(): void
    {
        ProcurementExample::run();
        $purchase = Purchase::query()->create([
            'workspace' => 'demo', 'requester' => 'requester', 'title' => 'Draft', 'status' => 'draft',
        ]);
        $manager = app(WorkflowManager::class);
        $run = $manager->start(ProcurementExample::definition(), $purchase, 'demo', 'requester', 'rollback:start');
        $run = $manager->act($run, $run->pendingSteps()->sole()->id, 'submit', 'demo', 'requester', 'rollback:submit', 1,
            ['title' => 'Equipment', 'amount_cents' => 50000], ProcurementExample::persistSubmission());
        $step = $run->pendingSteps()->sole();
        try {
            $manager->reassign($run, $step->id, ['outside-workspace'], 'demo', 'admin', 'foreign', 2);
            $this->fail('Foreign assignment accepted.');
        } catch (AuthorizationException) {
            $this->assertSame(['reviewer'], $step->assignees()->pluck('assignee_value')->all());
        }
        try {
            $manager->act($run, $step->id, 'approve', 'demo', 'reviewer', 'rollback:approve', 2,
                withinTransaction: function ($run): void {
                    ProcurementExample::lockedPurchase($run)->update(['status' => 'approved']);
                    throw new \RuntimeException('Example domain failure');
                });
            $this->fail('Callback failure ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Example domain failure', $exception->getMessage());
        }
        $this->assertSame('in_review', $purchase->fresh()->status);
        $this->assertSame(2, $run->fresh()->version);
        $this->assertSame(2, $run->commands()->count());
        $this->assertNull($step->fresh()->finished_at);
        $run = DB::transaction(function () use ($manager, $run) {
            $run = $manager->cancel($run, 'demo', 'admin', 'cancel', 2, 'Withdrawn');
            ProcurementExample::lockedPurchase($run)->update(['status' => 'withdrawn']);

            return $run;
        });
        $this->assertSame('cancelled', $run->status);
        $this->assertSame('withdrawn', $purchase->fresh()->status);
    }
}
