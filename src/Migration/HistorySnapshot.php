<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Migration;

use DateTimeImmutable;
use InvalidArgumentException;
use MrNewport\LaravelFlow\Assignments\AssignmentValues;
use MrNewport\LaravelWorkflow\Support\CanonicalJson;

/** A reviewed, normalized handoff. Source adapters resolve domain identities explicitly. */
final readonly class HistorySnapshot
{
    private string $hash;

    private function __construct(private array $data, string $encoded)
    {
        $this->hash = hash('sha256', $encoded);
    }

    public static function fromArray(array $data): self
    {
        $data = CanonicalJson::detach($data);
        self::keys($data, ['source', 'subject', 'status', 'tasks', 'evidence']);
        self::text($data['source'] ?? null, 191);
        if (! is_array($data['subject'] ?? null)) {
            throw new InvalidArgumentException('An imported subject binding is required.');
        }
        self::keys($data['subject'], ['type', 'id']);
        self::text($data['subject']['type'] ?? null, 255);
        self::text($data['subject']['id'] ?? null, 255);
        if (! in_array($data['status'] ?? null, ['active', 'completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Invalid imported run status.');
        }
        if (! is_array($data['tasks'] ?? null) || ! array_is_list($data['tasks']) || count($data['tasks']) < 1 || count($data['tasks']) > 10000) {
            throw new InvalidArgumentException('Import 1 to 10000 tasks per reviewed run.');
        }
        $seen = [];
        $pending = 0;
        foreach ($data['tasks'] as $task) {
            if (! is_array($task)) {
                throw new InvalidArgumentException('Imported tasks must be objects.');
            }
            self::keys($task, ['source_id', 'step', 'started_at', 'finished_at', 'action', 'actor', 'assignees', 'messages']);
            self::text($task['source_id'] ?? null, 191);
            self::text($task['step'] ?? null, 64);
            if (isset($seen[$task['source_id']])) {
                throw new InvalidArgumentException('Imported source task ids must be unique.');
            }
            $seen[$task['source_id']] = true;
            $start = self::date($task['started_at'] ?? null);
            $finish = ($task['finished_at'] ?? null) === null ? null : self::date($task['finished_at']);
            if ($finish !== null && $finish < $start) {
                throw new InvalidArgumentException('A task cannot finish before it starts.');
            }
            if ($finish === null) {
                $pending++;
                if (($task['action'] ?? null) !== null || ($task['actor'] ?? null) !== null) {
                    throw new InvalidArgumentException('Pending tasks cannot contain completion attribution.');
                }
            } else {
                if (($task['action'] ?? null) !== null) {
                    self::text($task['action'], 100);
                }
                // Missing historical attribution stays missing; never invent an actor.
                if (($task['actor'] ?? null) !== null) {
                    self::text($task['actor'], 191);
                }
            }
            if (! is_array($task['assignees'] ?? null) || ! array_is_list($task['assignees']) || count($task['assignees']) > 250) {
                throw new InvalidArgumentException('Invalid imported assignments.');
            }
            $identities = [];
            foreach ($task['assignees'] as $assignee) {
                if (! is_array($assignee)) {
                    throw new InvalidArgumentException('Imported assignments must be objects.');
                }
                self::keys($assignee, ['type', 'value']);
                if (! in_array($assignee['type'] ?? null, ['user', 'email'], true)) {
                    throw new InvalidArgumentException('Map imported assignments to user or email identities.');
                }
                if (! is_string($assignee['value'] ?? null)) {
                    throw new InvalidArgumentException('Imported identities must be strings.');
                }
                $assignee['type'] === 'email' ? AssignmentValues::emails([$assignee['value']]) : AssignmentValues::users([$assignee['value']]);
                $identity = CanonicalJson::encode([$assignee['type'], $assignee['value']]);
                if (isset($identities[$identity])) {
                    throw new InvalidArgumentException('Imported assignments cannot repeat an identity.');
                }
                $identities[$identity] = true;
            }
            if (! is_array($task['messages'] ?? null) || ! array_is_list($task['messages']) || count($task['messages']) > 1000) {
                throw new InvalidArgumentException('Invalid imported messages.');
            }
            foreach ($task['messages'] as $message) {
                if (! is_array($message)) {
                    throw new InvalidArgumentException('Imported messages must be objects.');
                }
                self::keys($message, ['author', 'body', 'created_at']);
                self::text($message['author'] ?? null, 191);
                self::text($message['body'] ?? null, 10000);
                self::date($message['created_at'] ?? null);
            }
        }
        if (($data['status'] === 'active' && $pending === 0) || ($data['status'] !== 'active' && $pending !== 0)) {
            throw new InvalidArgumentException('Imported status and pending tasks disagree.');
        }
        $data['evidence'] ??= [];
        if (! is_array($data['evidence'])) {
            throw new InvalidArgumentException('Import evidence must be JSON data.');
        }
        $encoded = CanonicalJson::encode($data);
        if (strlen($encoded) > 16777216) {
            throw new InvalidArgumentException('Import snapshot exceeds 16 MiB.');
        }

        return new self($data, $encoded);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function fingerprint(): string
    {
        return $this->hash;
    }

    public static function date(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))\z/D', $value)) {
            throw new InvalidArgumentException('Import timestamps must be ISO 8601 with an explicit timezone.');
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid import timestamp.', previous: $exception);
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
            throw new InvalidArgumentException('Invalid import timestamp.');
        }
        if ((int) $date->format('Y') < 1000 || (int) $date->format('Y') > 9999) {
            throw new InvalidArgumentException('Import timestamps must be within years 1000 through 9999.');
        }

        return $date;
    }

    private static function text(mixed $value, int $max): void
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Imported strings must be nonempty and within their storage limits.');
        }
    }

    private static function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown import properties; put source-only details in evidence.');
        }
    }
}
