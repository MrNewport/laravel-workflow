<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Authorization;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelWorkflow\Contracts\WorkflowAuthorizer;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;

final class DenyAllAuthorizer implements WorkflowAuthorizer
{
    public function authorizeStart(Definition $definition, Model $subject, string $scope, string $actor): void
    {
        throw new AuthorizationException('Bind a host WorkflowAuthorizer before starting workflows.');
    }

    public function authorize(WorkflowRun $run, ?FlowInstanceStep $step, string $action, string $actor): void
    {
        throw new AuthorizationException('Bind a host WorkflowAuthorizer before changing workflows.');
    }
}
