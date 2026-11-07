<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow;

use InvalidArgumentException;
use MrNewport\LaravelFlow\Assignments\AssignmentValues;
use MrNewport\LaravelWorkflow\Support\CanonicalJson;

/** A versioned, executable-data-only graph. No class names or callbacks are loaded from it. */
final readonly class Definition
{
    private string $hash;

    private function __construct(public string $name, public int $version, public string $initial, private array $steps)
    {
        $this->hash = hash('sha256', CanonicalJson::encode($this->toArray()));
    }

    public static function fromArray(array $data): self
    {
        return self::build($data, true);
    }

    /** Hydrate immutable saved definitions without imposing new admission budgets on saved data. */
    public static function fromStoredArray(array $data): self
    {
        return self::build($data, false);
    }

    private static function build(array $data, bool $admission): self
    {
        $data = CanonicalJson::detach($data);
        self::keys($data, ['name', 'version', 'initial', 'steps']);
        $name = self::identifier($data['name'] ?? null, 80, 'definition name');
        if (strtolower($name) !== $name) {
            throw new InvalidArgumentException('Definition names must be lowercase for portable database uniqueness.');
        }
        $initial = self::identifier($data['initial'] ?? null, 64, 'initial step');
        if (! is_int($data['version'] ?? null) || $data['version'] < 1 || $data['version'] > 2147483647) {
            throw new InvalidArgumentException('Definition version must be a positive integer.');
        }
        if (! is_array($data['steps'] ?? null) || ! array_is_list($data['steps']) || count($data['steps']) < 1 || count($data['steps']) > 250) {
            throw new InvalidArgumentException('A definition needs between 1 and 250 steps.');
        }

        $edges = 0;
        $maxEdges = function_exists('config') && app()->bound('config') ? config('workflow.max_definition_edges', 10000) : 10000;
        if (! is_int($maxEdges) || $maxEdges < 1 || $maxEdges > 625000) {
            throw new InvalidArgumentException('workflow.max_definition_edges must be an integer between 1 and 625000.');
        }
        $steps = [];
        foreach ($data['steps'] as $step) {
            if (! is_array($step)) {
                throw new InvalidArgumentException('Each step must be an object.');
            }
            self::keys($step, ['id', 'name', 'assignment_strategy', 'assignment_params', 'transitions']);
            $id = self::identifier($step['id'] ?? null, 64, 'step id');
            if ($id === 'END' || isset($steps[$id])) {
                throw new InvalidArgumentException('Step ids must be unique and cannot be END.');
            }
            $label = $step['name'] ?? $id;
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 255 || preg_match('/[\x00-\x1f]/', $label)) {
                throw new InvalidArgumentException('Step labels must be plain, nonempty text up to 255 characters.');
            }
            $strategy = $step['assignment_strategy'] ?? null;
            $params = $step['assignment_params'] ?? [];
            self::assignments($strategy, $params, $admission);
            $transitions = $step['transitions'] ?? null;
            if (! is_array($transitions) || $transitions === [] || count($transitions) > 50) {
                throw new InvalidArgumentException('Every step needs between 1 and 50 named actions.');
            }
            foreach ($transitions as $action => $targets) {
                self::identifier($action, 100, 'action');
                if (strtolower($action) !== $action) {
                    throw new InvalidArgumentException('Action names must be lowercase for portable transition matching.');
                }
                if (in_array($action, ['start', 'comment', 'reassign', 'cancel-run', 'import'], true)) {
                    throw new InvalidArgumentException('This action name is reserved for workflow coordination operations.');
                }
                if (! is_array($targets) || ! array_is_list($targets) || $targets === [] || count($targets) > 50) {
                    throw new InvalidArgumentException('Action targets must be a nonempty list of up to 50 step ids.');
                }
                $edges += count($targets);
                if ($admission && $edges > $maxEdges) {
                    throw new InvalidArgumentException('The definition exceeds the configured total transition budget.');
                }
                foreach ($targets as $target) {
                    self::identifier($target, 64, 'target');
                }
                if (count(array_unique($targets)) !== count($targets) || (in_array('END', $targets, true) && count($targets) > 1)) {
                    throw new InvalidArgumentException('An action cannot repeat a target or mix END with other targets.');
                }
                sort($targets, SORT_STRING);
                $transitions[$action] = $targets;
            }
            ksort($transitions, SORT_STRING);
            $steps[$id] = ['id' => $id, 'name' => $label, 'assignment_strategy' => $strategy, 'assignment_params' => $params, 'transitions' => $transitions];
        }

        if (! isset($steps[$initial])) {
            throw new InvalidArgumentException('The initial step must exist.');
        }
        $successors = $predecessors = [];
        foreach ($steps as $step) {
            foreach ($step['transitions'] as $targets) {
                foreach ($targets as $target) {
                    if ($target !== 'END' && ! isset($steps[$target])) {
                        throw new InvalidArgumentException('Every action target must reference a defined step or END.');
                    }
                    if ($target !== 'END') {
                        $successors[$step['id']][$target] = true;
                    }
                    $predecessors[$target][$step['id']] = true;
                }
            }
        }

        $reachable = self::walk($initial, $successors);
        if (count($reachable) !== count($steps)) {
            throw new InvalidArgumentException('All steps must be reachable from the initial step.');
        }
        if (count(self::walk('END', $predecessors)) !== count($steps) + 1) {
            throw new InvalidArgumentException('Every step must have a path to END.');
        }
        ksort($steps, SORT_STRING);
        $definition = new self($name, $data['version'], $initial, $steps);
        if ($admission && strlen(CanonicalJson::encode($definition->toArray())) >= 1048576) {
            throw new InvalidArgumentException('The normalized definition must fit within 1 MiB including its trailing newline.');
        }

        return $definition;
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'version' => $this->version, 'initial' => $this->initial, 'steps' => array_values($this->steps)];
    }

    public function fingerprint(): string
    {
        return $this->hash;
    }

    public function step(string $id): array
    {
        return $this->steps[$id] ?? throw new InvalidArgumentException('This step is not part of the definition.');
    }

    public function stepIds(): array
    {
        return array_keys($this->steps);
    }

    private static function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown definition properties are not allowed.');
        }
    }

    private static function identifier(mixed $value, int $max, string $label): string
    {
        if (! is_string($value) || strlen($value) > $max || ! preg_match('/\A[A-Za-z][A-Za-z0-9_.-]*\z/D', $value)) {
            throw new InvalidArgumentException("Invalid {$label}; use a letter followed by letters, numbers, dots, underscores or hyphens (maximum {$max}).");
        }

        return $value;
    }

    private static function assignments(mixed $strategy, mixed $params, bool $admission): void
    {
        if (! in_array($strategy, [null, 'single_user', 'multi_user', 'email_list'], true) || ! is_array($params)) {
            throw new InvalidArgumentException('Unsupported assignment strategy or parameters.');
        }
        $key = match ($strategy) {
            'single_user' => 'user_id', 'multi_user' => 'user_ids', 'email_list' => 'emails', default => null
        };
        if ($key === null) {
            if ($params !== []) {
                throw new InvalidArgumentException('Unassigned steps cannot contain assignment parameters.');
            }

            return;
        }
        self::keys($params, [$key]);
        if (! array_key_exists($key, $params)) {
            throw new InvalidArgumentException('Assignment parameters are required.');
        }
        $values = $strategy === 'single_user' ? [$params[$key]] : $params[$key];
        if (! is_array($values) || ! array_is_list($values) || count($values) < 1 || count($values) > 250) {
            throw new InvalidArgumentException('Assignments must contain between 1 and 250 identities.');
        }
        if ($admission) {
            $strategy === 'email_list' ? AssignmentValues::emails($values) : AssignmentValues::users($values);
        } else {
            $strategy === 'email_list' ? AssignmentValues::emailsForStored($values) : AssignmentValues::usersForStored($values);
        }
    }

    private static function walk(string $initial, array $neighbors): array
    {
        $seen = [];
        $queue = [$initial];
        while ($queue !== []) {
            $id = array_pop($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            array_push($queue, ...array_keys($neighbors[$id] ?? []));
        }

        return $seen;
    }
}
