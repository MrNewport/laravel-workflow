<?php

declare(strict_types=1);

require $argv[1] ?? dirname(__DIR__).'/vendor/autoload.php';

use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\DefinitionPublisher;

$steps = [];
for ($i = 0; $i < 250; $i++) {
    $targets = [];
    for ($j = 1; $j <= 25; $j++) {
        $targets[] = 'step'.(($i + $j) % 250);
    }
    $steps[] = ['id' => 'step'.$i, 'transitions' => ['advance' => $targets, 'finish' => ['END']]];
}
$input = ['name' => 'benchmark', 'version' => 1, 'initial' => 'step0', 'steps' => $steps];
$measurements = [];
for ($sample = 0; $sample < 5; $sample++) {
    $start = hrtime(true);
    $definition = Definition::fromArray($input);
    $parsed = hrtime(true);
    $publisher = new DefinitionPublisher;
    foreach ($steps as $step) {
        foreach ($step['transitions']['advance'] as $target) {
            $publisher->flowStepId($definition, $target);
        }
    }
    $end = hrtime(true);
    $measurements[] = ['validation_ms' => ($parsed - $start) / 1e6, '6250_step_ids_ms' => ($end - $parsed) / 1e6];
}
echo json_encode(['php' => PHP_VERSION, 'steps' => 250, 'edges' => 6500, 'samples' => $measurements], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
