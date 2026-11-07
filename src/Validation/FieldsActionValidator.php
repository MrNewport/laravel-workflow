<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Validation;

use Illuminate\Validation\ValidationException;
use MrNewport\LaravelFields\Schema;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelWorkflow\Contracts\ActionValidator;
use MrNewport\LaravelWorkflow\DefinitionPublisher;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;

/** Optional bridge. Schemas come from reviewed application code, never from request data. */
final class FieldsActionValidator implements ActionValidator
{
    private array $schemas = [];

    public function register(string $definitionName, int $version, string $step, string $action, Schema $schema): self
    {
        $this->schemas[$definitionName][$version][$step][$action] = $schema;

        return $this;
    }

    public function validate(WorkflowRun $run, FlowInstanceStep $step, string $action, array $payload): array
    {
        $definition = $run->publishedDefinition->definition();
        $local = (new DefinitionPublisher)->localStepId($definition, $step->step_id);
        $schema = $this->schemas[$definition->name][$definition->version][$local][$action] ?? null;
        if ($schema === null) {
            if ($payload !== []) {
                throw ValidationException::withMessages(['payload' => __('laravel-workflow::messages.validation.no_schema')]);
            }

            return [];
        }

        return $schema->validate($payload);
    }
}
