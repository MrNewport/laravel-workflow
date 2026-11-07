<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow;

use Illuminate\Database\UniqueConstraintViolationException;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelFlow\Support\Persistence;
use MrNewport\LaravelWorkflow\Exceptions\WorkflowConflict;
use MrNewport\LaravelWorkflow\Models\PublishedDefinition;
use MrNewport\LaravelWorkflow\Support\CanonicalJson;
use MrNewport\LaravelWorkflow\Support\WriteCollision;

final class DefinitionPublisher
{
    public function publish(Definition $definition): PublishedDefinition
    {
        $collision = new WriteCollision;
        try {
            return (new PublishedDefinition)->getConnection()->transaction(function () use ($definition, $collision) {
                $published = PublishedDefinition::query()->where('name', $definition->name)->where('version', $definition->version)->first();
                if ($published) {
                    $published = PublishedDefinition::query()->whereKey($published->getKey())->sharedLock()->firstOrFail();
                    if (! hash_equals($published->getKey(), $definition->fingerprint())) {
                        throw new WorkflowConflict('A different definition already uses this name and version.');
                    }
                    $published->definition();
                    $this->assertEngineMatches($definition);

                    return $published;
                }

                try {
                    $published = PublishedDefinition::query()->create(['id' => $definition->fingerprint(), 'name' => $definition->name, 'version' => $definition->version, 'snapshot' => $definition->toArray()]);
                } catch (UniqueConstraintViolationException $exception) {
                    $collision->expected = true;
                    throw $exception;
                }
                Persistence::created($published);
                $transitions = [];
                foreach ($definition->toArray()['steps'] as $step) {
                    $savedStep = FlowStep::query()->create(['id' => $this->flowStepId($definition, $step['id']), 'name' => $step['name'], 'notify' => false, 'assignment_strategy' => $step['assignment_strategy'], 'assignment_params' => $step['assignment_params']]);
                    Persistence::created($savedStep);
                    foreach ($step['transitions'] as $action => $targets) {
                        foreach ($targets as $target) {
                            $transitions[] = ['step_id' => $this->flowStepId($definition, $step['id']), 'action' => $action, 'next_step_id' => $target === 'END' ? 'END' : $this->flowStepId($definition, $target)];
                            if (count($transitions) === 200) {
                                FlowTransition::query()->insert($transitions);
                                $transitions = [];
                            }
                        }
                    }
                }
                if ($transitions !== []) {
                    FlowTransition::query()->insert($transitions);
                }
                $this->assertEngineMatches($definition);

                return $published;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $collision->expected) {
                throw $exception;
            }
            // A competing publisher may win the name/version unique constraint.
            $published = PublishedDefinition::query()->where('name', $definition->name)->where('version', $definition->version)->sharedLock()->first();
            if (! $published) {
                throw $exception;
            }
            if (! hash_equals($published->getKey(), $definition->fingerprint())) {
                throw new WorkflowConflict('A different definition already uses this name and version.', previous: $exception);
            }
            $published->definition();
            $this->assertEngineMatches($definition);

            return $published;
        }
    }

    public function flowStepId(Definition $definition, string $localStep): string
    {
        $definition->step($localStep);

        // Hex identifiers also preserve case-distinct local ids on MySQL's default collation.
        return 'w:'.$definition->fingerprint().':'.substr(hash('sha256', $localStep), 0, 24);
    }

    public function localStepId(Definition $definition, string $flowStep): string
    {
        foreach ($definition->stepIds() as $local) {
            if (hash_equals($this->flowStepId($definition, $local), $flowStep)) {
                return $local;
            }
        }
        throw new WorkflowConflict('The engine step is outside the published definition.');
    }

    /** Fail closed if someone edits the Flow tables independently of this publisher. */
    public function assertEngineMatches(Definition $definition): void
    {
        $ids = array_map(fn ($local) => $this->flowStepId($definition, $local), $definition->stepIds());
        sort($ids, SORT_STRING);
        $storedSteps = FlowStep::query()->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id', 'name', 'notify', 'assignment_strategy', 'assignment_params'])->keyBy('id');
        $storedTransitions = FlowTransition::query()->whereIn('step_id', $ids)->orderBy('step_id')->orderBy('action')->orderBy('next_step_id')->sharedLock()->get(['step_id', 'action', 'next_step_id'])->groupBy('step_id');
        foreach ($definition->toArray()['steps'] as $step) {
            $id = $this->flowStepId($definition, $step['id']);
            $stored = $storedSteps->get($id);
            if (! $stored || $stored->name !== $step['name'] || (bool) $stored->notify || $stored->assignment_strategy !== $step['assignment_strategy'] || CanonicalJson::encode($stored->assignment_params ?? []) !== CanonicalJson::encode($step['assignment_params'])) {
                throw new WorkflowConflict('Published engine definitions have changed. Restore the reviewed definition.');
            }
            $expected = [];
            foreach ($step['transitions'] as $action => $targets) {
                foreach ($targets as $target) {
                    $expected[] = [$action, $target === 'END' ? 'END' : $this->flowStepId($definition, $target)];
                }
            }
            $actual = ($storedTransitions->get($id) ?? collect())->map(fn ($row) => [$row->action, $row->next_step_id])->all();
            sort($expected);
            sort($actual);
            if ($actual !== $expected) {
                throw new WorkflowConflict('Published engine transitions have changed. Restore the reviewed definition.');
            }
        }
    }
}
