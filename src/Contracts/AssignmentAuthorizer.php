<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Contracts;

use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;

interface AssignmentAuthorizer
{
    /** Validate destination membership/ownership under the run lock, including admin changes. */
    public function authorizeAssignment(WorkflowRun $run, FlowInstanceStep $step, string $actor, array $current, array $proposed): void;
}
