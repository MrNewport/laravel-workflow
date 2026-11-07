<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Tests\Unit;

use InvalidArgumentException;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\DefinitionPublisher;
use MrNewport\LaravelWorkflow\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefinitionTest extends TestCase
{
    private static function valid(): array
    {
        return ['name' => 'review', 'version' => 1, 'initial' => 'draft', 'steps' => [
            ['id' => 'draft', 'transitions' => ['submit' => ['review']]],
            ['id' => 'review', 'transitions' => ['approve' => ['END'], 'reject' => ['draft']]],
        ]];
    }

    public function test_normalization_roundtrips_and_is_independent_of_object_and_step_order(): void
    {
        $a = self::valid();
        $b = $a;
        $b['steps'] = array_reverse($b['steps']);
        $a = Definition::fromArray($a);
        $b = Definition::fromArray($b);
        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertSame($a->toArray(), Definition::fromArray($a->toArray())->toArray());
        $this->assertSame(hash('sha256', CanonicalJson::encode($a->toArray())), $a->fingerprint());
    }

    public function test_case_distinct_local_ids_have_distinct_portable_engine_ids(): void
    {
        $definition = Definition::fromArray(['name' => 'case', 'version' => 1, 'initial' => 'draft', 'steps' => [
            ['id' => 'draft', 'transitions' => ['go' => ['Review', 'review']]],
            ['id' => 'Review', 'transitions' => ['done' => ['END']]],
            ['id' => 'review', 'transitions' => ['done' => ['END']]],
        ]]);
        $publisher = new DefinitionPublisher;
        $a = $publisher->flowStepId($definition, 'Review');
        $b = $publisher->flowStepId($definition, 'review');
        $this->assertNotSame(strtolower($a), strtolower($b));
        $this->assertLessThanOrEqual(100, strlen($a));
        $this->assertSame('Review', $publisher->localStepId($definition, $a));
    }

    public function test_definition_detaches_reference_values_before_caching_its_identity(): void
    {
        $target = 'review';
        $label = 'Review';
        $ids = [1, 2];
        $params = ['user_ids' => &$ids];
        $data = self::valid();
        $data['steps'][0]['transitions']['submit'] = [&$target];
        $data['steps'][1]['name'] = &$label;
        $data['steps'][1]['assignment_strategy'] = 'multi_user';
        $data['steps'][1]['assignment_params'] = &$params;
        $definition = Definition::fromArray($data);
        $snapshot = $definition->toArray();
        $hash = $definition->fingerprint();
        $target = 'END';
        $label = 'Changed';
        $ids[0] = 999;
        $this->assertSame($snapshot, $definition->toArray());
        $this->assertSame($hash, hash('sha256', CanonicalJson::encode($definition->toArray())));
        $this->assertSame($hash, $definition->fingerprint());
    }

    #[DataProvider('invalidDefinitions')]
    public function test_invalid_definitions_are_rejected(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        Definition::fromArray($data);
    }

    public static function invalidDefinitions(): iterable
    {
        $base = self::valid();
        foreach ([0, -1, '1', 2147483648] as $version) {
            yield 'version '.json_encode($version) => [array_replace($base, ['version' => $version])];
        }
        foreach (['Review', '../name', '', 'review name', str_repeat('a', 81)] as $name) {
            yield 'name '.json_encode($name) => [array_replace($base, ['name' => $name])];
        }
        yield 'unknown property' => [$base + ['callback' => 'system']];
        yield 'missing initial' => [array_replace($base, ['initial' => 'missing'])];
        yield 'no steps' => [array_replace($base, ['steps' => []])];
        $a = $base;
        $a['steps'][] = $a['steps'][0];
        yield 'duplicate step' => [$a];
        $a = $base;
        $a['steps'][0]['id'] = 'END';
        yield 'END step' => [$a];
        $a = $base;
        $a['steps'][0]['callback'] = 'system';
        yield 'step callback' => [$a];
        $a = $base;
        $a['steps'][0]['name'] = "<label>\n";
        yield 'control label' => [$a];
        $a = $base;
        $a['steps'][0]['assignment_strategy'] = 'role';
        yield 'unknown strategy' => [$a];
        $a = $base;
        $a['steps'][0]['assignment_params'] = ['user_id' => 1];
        yield 'unassigned params' => [$a];
        $a = $base;
        $a['steps'][0]['assignment_strategy'] = 'single_user';
        yield 'missing assignment' => [$a];
        $a = $base;
        $a['steps'][0]['assignment_strategy'] = 'multi_user';
        $a['steps'][0]['assignment_params'] = ['user_ids' => [1, '1']];
        yield 'duplicate assignment' => [$a];
        $a = $base;
        $a['steps'][0]['assignment_strategy'] = 'email_list';
        $a['steps'][0]['assignment_params'] = ['emails' => ['invalid']];
        yield 'email assignment' => [$a];
        $a = $base;
        $a['steps'][0]['transitions'] = [];
        yield 'no actions' => [$a];
        $a = $base;
        $a['steps'][0]['transitions'] = ['Submit' => ['review']];
        yield 'case-sensitive action ambiguity' => [$a];
        foreach (['start', 'comment', 'reassign', 'cancel-run', 'import'] as $action) {
            $a = $base;
            $a['steps'][0]['transitions'] = [$action => ['review']];
            yield 'reserved '.$action => [$a];
        }
        $a = $base;
        $a['steps'][0]['transitions'] = ['go' => ['missing']];
        yield 'unknown target' => [$a];
        $a = $base;
        $a['steps'][0]['transitions'] = ['go' => []];
        yield 'no targets' => [$a];
        $a = $base;
        $a['steps'][0]['transitions'] = ['go' => ['review', 'review']];
        yield 'repeated target' => [$a];
        $a = $base;
        $a['steps'][0]['transitions'] = ['go' => ['END', 'review']];
        yield 'END branch' => [$a];
        $a = $base;
        $a['steps'][0]['transitions'] = ['go' => ['END']];
        yield 'unreachable' => [$a];
        $a = $base;
        $a['steps'][1]['transitions'] = ['go' => ['draft']];
        yield 'never ends' => [$a];
    }
}
