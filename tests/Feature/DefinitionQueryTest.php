<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Tests\Feature;

use Illuminate\Support\Facades\DB;
use MrNewport\LaravelFlow\Models\FlowStep;
use MrNewport\LaravelFlow\Models\FlowTransition;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\DefinitionPublisher;
use MrNewport\LaravelWorkflow\Exceptions\WorkflowConflict;
use MrNewport\LaravelWorkflow\Tests\TestCase;

final class DefinitionQueryTest extends TestCase
{
    private function chain(int $size, string $name): Definition
    {
        $steps = [];
        for ($i = 0; $i < $size; $i++) {
            $steps[] = ['id' => 'step'.$i, 'transitions' => ['advance' => [$i === $size - 1 ? 'END' : 'step'.($i + 1)]]];
        }

        return Definition::fromArray(['name' => $name, 'version' => 1, 'initial' => 'step0', 'steps' => $steps]);
    }

    public function test_engine_verification_uses_two_queries_independent_of_graph_size(): void
    {
        $publisher = new DefinitionPublisher;
        $counts = [];
        foreach ([2, 25] as $size) {
            $definition = $this->chain($size, 'chain'.$size);
            $publisher->publish($definition);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $publisher->assertEngineMatches($definition);
            $counts[$size] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame([2 => 2, 25 => 2], $counts);
    }

    public function test_batched_verification_rejects_missing_extra_and_modified_records(): void
    {
        $publisher = new DefinitionPublisher;
        $definition = $this->chain(3, 'tamper');
        $publisher->publish($definition);
        $id = $publisher->flowStepId($definition, 'step1');
        $mutations = [
            fn () => FlowStep::query()->whereKey($id)->update(['name' => 'Changed']),
            fn () => FlowTransition::query()->where('step_id', $id)->delete(),
            fn () => FlowTransition::query()->create(['step_id' => $id, 'action' => 'extra', 'next_step_id' => 'END']),
        ];
        foreach ($mutations as $mutate) {
            DB::beginTransaction();
            $mutate();
            try {
                $publisher->assertEngineMatches($definition);
                $this->fail('Tampered graph accepted');
            } catch (WorkflowConflict) {
                $this->assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_existing_publication_revalidates_its_snapshot_before_accepting_new_runs(): void
    {
        $publisher = new DefinitionPublisher;
        $definition = $this->chain(3, 'snapshot');
        $published = $publisher->publish($definition);
        $snapshot = $definition->toArray();
        $snapshot['steps'][0]['name'] = 'Changed';
        DB::table('workflow_definitions')->where('id', $published->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $this->expectException(\LogicException::class);
        $publisher->publish($definition);
    }

    public function test_transition_publication_batches_rows_without_changing_graph_semantics(): void
    {
        $definition = $this->chain(250, 'large');
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new DefinitionPublisher)->publish($definition);
        $inserts = array_filter(DB::getQueryLog(), fn ($query) => str_starts_with($query['query'], 'insert into '.DB::getQueryGrammar()->wrapTable('flow_transitions')));
        DB::disableQueryLog();
        $this->assertCount(2, $inserts);
        $this->assertSame(250, FlowTransition::query()->count());
        (new DefinitionPublisher)->assertEngineMatches($definition);
    }
}
