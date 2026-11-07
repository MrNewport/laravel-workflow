<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Contracts;

use Illuminate\Database\Eloquent\Model;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;

interface WorkflowAuthorizer
{
    /** Resolve identities, tenancy and policy in the host. Throw to refuse. */
    public function authorizeStart(Definition $definition, Model $subject, string $scope, string $actor): void;

    /** Called inside the run lock, including on retries. Null step means a run operation. */
    public function authorize(WorkflowRun $run, ?FlowInstanceStep $step, string $action, string $actor): void;
}
