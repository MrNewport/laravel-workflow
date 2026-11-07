<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Authorization;

use Illuminate\Auth\Access\AuthorizationException;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelWorkflow\Contracts\AssignmentAuthorizer;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;

final class DenyAllAssignments implements AssignmentAuthorizer
{
    public function authorizeAssignment(WorkflowRun $run, FlowInstanceStep $step, string $actor, array $current, array $proposed): void
    {
        throw new AuthorizationException('Bind an AssignmentAuthorizer to check destination identities.');
    }
}
