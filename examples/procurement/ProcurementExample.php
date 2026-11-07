<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Examples\Procurement;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelWorkflow\Contracts\ActionValidator;
use MrNewport\LaravelWorkflow\Contracts\AssignmentAuthorizer;
use MrNewport\LaravelWorkflow\Contracts\WorkflowAuthorizer;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\DefinitionPublisher;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;
use MrNewport\LaravelWorkflow\WorkflowManager;

/** Runnable in a migrated, disposable Laravel database; no external services or UI required. */
final class ProcurementExample
{
    public static function installSchema(): void
    {
        Schema::create('example_procurement_members', function (Blueprint $table): void {
            $table->id();
            $table->string('workspace');
            $table->string('actor');
            $table->string('role');
            $table->unique(['workspace', 'actor']);
        });
        Schema::create('example_purchases', function (Blueprint $table): void {
            $table->id();
            $table->string('workspace');
            $table->string('requester');
            $table->string('title');
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('status')->default('draft');
            $table->timestamps();
        });
        Schema::create('example_procurement_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key')->unique();
            $table->unsignedBigInteger('run_id');
            $table->json('payload');
            $table->timestamp('delivered_at')->nullable();
        });
    }

    public static function bind(): void
    {
        app()->bind(WorkflowAuthorizer::class, ProcurementPolicy::class);
        app()->bind(AssignmentAuthorizer::class, ProcurementPolicy::class);
        app()->bind(ActionValidator::class, ProcurementPayloadValidator::class);
    }

    public static function definition(): Definition
    {
        return Definition::fromArray([
            'name' => 'procurement-example', 'version' => 1, 'initial' => 'draft',
            'steps' => [
                ['id' => 'draft', 'name' => 'Prepare purchase', 'transitions' => ['submit' => ['review'], 'cancel' => ['END']]],
                ['id' => 'review', 'name' => 'Review purchase', 'assignment_strategy' => 'single_user',
                    'assignment_params' => ['user_id' => 'reviewer'],
                    'transitions' => ['approve' => ['END'], 'revise' => ['draft'], 'cancel' => ['END']]],
            ],
        ]);
    }

    /** Each invocation creates its own subject and operation keys, preserving earlier example runs. */
    public static function run(): array
    {
        self::bind();
        foreach (['requester' => 'requester', 'reviewer' => 'reviewer', 'backup' => 'reviewer', 'admin' => 'admin'] as $actor => $role) {
            ProcurementMember::query()->firstOrCreate(['workspace' => 'demo', 'actor' => $actor], ['role' => $role]);
        }
        $purchase = Purchase::query()->create(['workspace' => 'demo', 'requester' => 'requester', 'title' => 'Draft purchase', 'status' => 'draft']);
        $prefix = 'purchase:'.$purchase->id.':';
        $manager = app(WorkflowManager::class);
        $run = $manager->start(self::definition(), $purchase, 'demo', 'requester', $prefix.'start');
        $draft = $run->pendingSteps()->sole();
        $run = $manager->act($run, $draft->id, 'submit', 'demo', 'requester', $prefix.'submit-1', 1,
            ['title' => 'Replace lab workstation', 'amount_cents' => 250000], self::persistSubmission(), 'Ready for review');
        $review = $run->pendingSteps()->sole();
        $run = $manager->act($run, $review->id, 'revise', 'demo', 'reviewer', $prefix.'revise', 2,
            withinTransaction: self::setStatus('draft'), message: 'Please attach the revised budget');
        $draft = $run->pendingSteps()->sole();
        $run = $manager->act($run, $draft->id, 'submit', 'demo', 'requester', $prefix.'submit-2', 3,
            ['title' => 'Replace lab workstation', 'amount_cents' => 225000], self::persistSubmission());
        $review = $run->pendingSteps()->sole();
        $run = $manager->reassign($run, $review->id, ['backup'], 'demo', 'admin', $prefix.'assign', 4);
        $run = $manager->comment($run, 'demo', 'backup', $prefix.'note', 5, 'Budget checked', $review->id);
        $approve = function (WorkflowRun $run) use ($prefix): void {
            $purchase = self::lockedPurchase($run);
            if ($purchase->status !== 'in_review' || $purchase->amount_cents > 1000000) {
                throw new \DomainException('Purchase is outside the reviewed approval boundary.');
            }
            $purchase->update(['status' => 'approved']);
            DB::table('example_procurement_outbox')->insert([
                'operation_key' => $prefix.'purchase-approved', 'run_id' => $run->id,
                'payload' => json_encode(['purchase_id' => $purchase->id, 'amount_cents' => $purchase->amount_cents], JSON_THROW_ON_ERROR),
            ]);
        };
        $run = $manager->act($run, $review->id, 'approve', 'demo', 'backup', $prefix.'approve', 6,
            withinTransaction: $approve, message: 'Approved within delegated budget');
        // This exact committed retry must not repeat the callback or the unique outbox insert.
        $run = $manager->act($run, $review->id, 'approve', 'demo', 'backup', $prefix.'approve', 6,
            withinTransaction: $approve, message: 'Approved within delegated budget');
        $run = $manager->comment($run, 'demo', 'requester', $prefix.'archive-note', 7, 'Approval recorded');

        return ['run' => $run, 'purchase' => $purchase->fresh(), 'outbox_count' => DB::table('example_procurement_outbox')->where('run_id', $run->id)->count()];
    }

    public static function persistSubmission(): \Closure
    {
        return function (WorkflowRun $run, FlowInstanceStep $step, array $validated): void {
            $purchase = self::lockedPurchase($run);
            if ($purchase->status !== 'draft') {
                throw new \DomainException('Only a draft purchase may be submitted.');
            }
            $purchase->update($validated + ['status' => 'in_review']);
        };
    }

    public static function setStatus(string $status): \Closure
    {
        return fn (WorkflowRun $run) => self::lockedPurchase($run)->update(['status' => $status]);
    }

    public static function lockedPurchase(WorkflowRun $run): Purchase
    {
        return Purchase::query()->whereKey($run->flowInstance->model_id)->lockForUpdate()->firstOrFail();
    }
}

final class Purchase extends Model
{
    protected $table = 'example_purchases';

    protected $guarded = [];

    protected $casts = ['amount_cents' => 'integer'];
}

final class ProcurementMember extends Model
{
    protected $table = 'example_procurement_members';

    public $timestamps = false;

    protected $guarded = [];
}

final class ProcurementPolicy implements AssignmentAuthorizer, WorkflowAuthorizer
{
    public function authorizeStart(Definition $definition, Model $subject, string $scope, string $actor): void
    {
        $member = $this->member($scope, $actor);
        if (! $subject instanceof Purchase || $subject->workspace !== $scope
            || $subject->requester !== $actor || $member->role !== 'requester'
            || $subject->status !== 'draft' || $definition->name !== 'procurement-example' || $definition->version !== 1) {
            throw new AuthorizationException('Purchase start is not permitted.');
        }
    }

    public function authorize(WorkflowRun $run, ?FlowInstanceStep $step, string $action, string $actor): void
    {
        $member = $this->member($run->scope_key, $actor);
        $purchase = $run->flowInstance->entity;
        if (! $purchase instanceof Purchase || $purchase->workspace !== $run->scope_key) {
            throw new AuthorizationException('Purchase ownership changed.');
        }
        if ($action === 'comment') {
            return;
        }
        if (in_array($action, ['cancel-run', 'cancel', 'reassign'], true)) {
            if ($member->role !== 'admin') {
                throw new AuthorizationException('Administrator permission required.');
            }

            return;
        }
        if ($action === 'submit' && $actor === $purchase->requester) {
            return;
        }
        if (in_array($action, ['approve', 'revise'], true) && $member->role === 'reviewer'
            && $step?->assignees()->where('assignee_type', 'user')->where('assignee_value', $actor)->exists()) {
            return;
        }
        throw new AuthorizationException('The actor cannot perform this purchase action.');
    }

    public function authorizeAssignment(WorkflowRun $run, FlowInstanceStep $step, string $actor, array $current, array $proposed): void
    {
        if ($this->member($run->scope_key, $actor)->role !== 'admin') {
            throw new AuthorizationException('Administrator permission required.');
        }
        foreach ($proposed as $identity) {
            if ($identity['type'] !== 'user' || $this->member($run->scope_key, $identity['value'])->role !== 'reviewer') {
                throw new AuthorizationException('Destination must be a current reviewer in this workspace.');
            }
        }
    }

    private function member(string $scope, string $actor): ProcurementMember
    {
        return ProcurementMember::query()->where('workspace', $scope)->where('actor', $actor)->first()
            ?? throw new AuthorizationException('Current workspace membership required.');
    }
}

final class ProcurementPayloadValidator implements ActionValidator
{
    public function validate(WorkflowRun $run, FlowInstanceStep $step, string $action, array $payload): array
    {
        $definition = $run->publishedDefinition->definition();
        $local = app(DefinitionPublisher::class)->localStepId($definition, $step->step_id);
        if ($definition->name === 'procurement-example' && $definition->version === 1 && $local === 'draft' && $action === 'submit') {
            return validator(['payload' => $payload], [
                'payload' => ['required', 'array:title,amount_cents'],
                'payload.title' => ['required', 'string', 'max:120'],
                'payload.amount_cents' => ['required', 'integer', 'min:1', 'max:10000000'],
            ])->validate()['payload'];
        }
        if ($payload !== []) {
            throw ValidationException::withMessages(['payload' => 'This action takes no payload.']);
        }

        return [];
    }
}
