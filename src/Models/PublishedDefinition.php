<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use MrNewport\LaravelWorkflow\Definition;

/**
 * @property string $id
 * @property string $name
 * @property int $version
 * @property array $snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PublishedDefinition extends Model
{
    protected $table = 'workflow_definitions';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['snapshot' => 'array', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Published definitions are immutable; publish a new version.'));
        static::deleting(fn () => throw new \LogicException('Published definitions are retained for workflow history.'));
    }

    public function definition(): Definition
    {
        $definition = Definition::fromStoredArray($this->snapshot);
        if (! hash_equals($this->getKey(), $definition->fingerprint()) || $this->name !== $definition->name || $this->version !== $definition->version) {
            throw new \LogicException('The published definition does not match its fingerprint.');
        }

        return $definition;
    }
}
