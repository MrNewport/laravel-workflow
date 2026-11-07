<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Models;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use MrNewport\LaravelFlow\Models\FlowInstance;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;

/**
 * @property int $id
 * @property string $scope_key
 * @property string $scope_hash
 * @property string $definition_id
 * @property int $flow_instance_id
 * @property string $start_key_hash
 * @property string $start_fingerprint
 * @property string $started_by
 * @property string $status
 * @property int $version
 * @property CarbonImmutable|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PublishedDefinition $publishedDefinition
 * @property-read FlowInstance $flowInstance
 */
class WorkflowRun extends Model
{
    protected $table = 'workflow_runs';

    protected $guarded = [];

    protected $casts = ['version' => 'integer', 'finished_at' => 'immutable_datetime'];

    /** @return BelongsTo<PublishedDefinition, $this> */
    public function publishedDefinition(): BelongsTo
    {
        return $this->belongsTo(PublishedDefinition::class, 'definition_id');
    }

    /** @return BelongsTo<FlowInstance, $this> */
    public function flowInstance(): BelongsTo
    {
        return $this->belongsTo(FlowInstance::class, 'flow_instance_id');
    }

    /** @return HasMany<WorkflowCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(WorkflowCommand::class, 'run_id')->orderBy('id');
    }

    /** @return HasMany<WorkflowMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(WorkflowMessage::class, 'run_id')->orderBy('id');
    }

    /** Bounded command page with at most 50 message previews per command. */
    public function history(?int $perPage = null, ?int $page = null): LengthAwarePaginator
    {
        $perPage ??= config('workflow.history_per_page', 50);
        self::assertPageSize($perPage);

        return $this->commands()->withCount('messages')
            ->with(['messages' => fn ($query) => $query->limit(50)])
            ->paginate($perPage, ['*'], 'workflow_page', $page);
    }

    /** Complete query-backed message history, optionally confined to one command. */
    public function historyMessages(?int $commandId = null, int $perPage = 50, ?int $page = null): LengthAwarePaginator
    {
        self::assertPageSize($perPage);
        $query = $this->messages();
        if ($commandId !== null) {
            $query->where('command_id', $commandId);
        }

        return $query->paginate($perPage, ['*'], 'workflow_messages_page', $page);
    }

    private static function assertPageSize(int $perPage): void
    {
        if ($perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('History pages must contain between 1 and 100 entries.');
        }
    }

    public function scopeInScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope_hash', hash('sha256', $scope))->where('scope_key', $scope);
    }

    /** @return HasMany<FlowInstanceStep, $this> */
    public function pendingSteps(): HasMany
    {
        return $this->hasMany(FlowInstanceStep::class, 'flow_instance_id', 'flow_instance_id')->whereNull('finished_at')->orderBy('id');
    }
}
