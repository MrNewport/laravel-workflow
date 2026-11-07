<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Contracts;

use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;

interface ActionValidator
{
    /** Return only reviewed, normalized input that the host may persist. */
    public function validate(WorkflowRun $run, FlowInstanceStep $step, string $action, array $payload): array;
}
