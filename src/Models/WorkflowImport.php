<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $run_id
 * @property string $scope_hash
 * @property string $source_hash
 * @property string $snapshot_hash
 * @property string $binding_fingerprint
 * @property array $snapshot
 * @property array $task_mapping
 * @property CarbonImmutable $created_at
 */
class WorkflowImport extends Model
{
    protected $table = 'workflow_imports';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['snapshot' => 'array', 'task_mapping' => 'array', 'created_at' => 'immutable_datetime'];

    public function setSnapshotAttribute(array $snapshot): void
    {
        $this->attributes['snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Reviewed import evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Reviewed import evidence is retained.'));
    }
}
