<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Validation;

use Illuminate\Validation\ValidationException;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelWorkflow\Contracts\ActionValidator;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;

final class EmptyPayloadValidator implements ActionValidator
{
    public function validate(WorkflowRun $run, FlowInstanceStep $step, string $action, array $payload): array
    {
        if ($payload !== []) {
            throw ValidationException::withMessages(['payload' => __('laravel-workflow::messages.validation.unregistered')]);
        }

        return [];
    }
}
