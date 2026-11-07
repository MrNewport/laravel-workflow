<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $run_id
 * @property string $key_hash
 * @property string $fingerprint
 * @property int $fingerprint_version
 * @property string $action
 * @property string $actor
 * @property int|null $step_id
 * @property int $result_version
 * @property string $result_status
 * @property array $step_ids
 * @property array $details
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, WorkflowMessage> $messages
 * @property-read int $messages_count
 */
class WorkflowCommand extends Model
{
    protected $table = 'workflow_commands';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['fingerprint_version' => 'integer', 'result_version' => 'integer', 'created_at' => 'immutable_datetime', 'step_ids' => 'array', 'details' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Workflow command receipts are immutable.'));
        static::deleting(fn () => throw new \LogicException('Workflow command receipts are retained for history.'));
    }

    /** @return HasMany<WorkflowMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(WorkflowMessage::class, 'command_id')->orderBy('id');
    }

    /** @return BelongsTo<WorkflowRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_id');
    }
}
