<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $run_id
 * @property int $command_id
 * @property int|null $step_id
 * @property string $author
 * @property string $body
 * @property CarbonImmutable $created_at
 */
class WorkflowMessage extends Model
{
    protected $table = 'workflow_messages';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Workflow messages are immutable.'));
        static::deleting(fn () => throw new \LogicException('Workflow messages are retained for history.'));
    }
}
