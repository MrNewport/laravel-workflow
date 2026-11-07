<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Small identifiers only. Consumers load authorized data and own delivery/retry policy. */
final readonly class WorkflowChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public int $runId, public int $commandId, public string $action, public string $actor, public int $version) {}
}
