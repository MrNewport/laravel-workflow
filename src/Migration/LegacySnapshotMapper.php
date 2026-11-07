<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Migration;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Converts a single exported legacy run; identity and timezone policies remain explicit. */
final class LegacySnapshotMapper
{
    public function map(string $source, Model $subject, string $status, array $tasks, array $messages, array $rejections, Closure $step, Closure $actor, Closure $assignments, Closure $timestamp): HistorySnapshot
    {
        $known = [];
        $normalized = [];
        foreach ($tasks as $task) {
            if (! is_array($task) || ! isset($task['id'], $task['task']) || (! is_int($task['id']) && ! is_string($task['id']))) {
                throw new InvalidArgumentException('Legacy tasks need explicit source ids and step keys.');
            }
            $id = (string) $task['id'];
            if (isset($known[$id])) {
                throw new InvalidArgumentException('Duplicate legacy task id.');
            }
            $known[$id] = true;
            $normalized[$id] = [
                'source_id' => $id, 'step' => $step($task['task'], $task),
                'started_at' => $timestamp($task['date_queued'] ?? null, 'date_queued', $task),
                'finished_at' => empty($task['date_done']) ? null : $timestamp($task['date_done'], 'date_done', $task),
                'action' => $task['action_done'] ?? null,
                'actor' => empty($task['date_done']) || ($task['user_done'] ?? null) === null ? null : $actor($task['user_done'], 'user_done', $task),
                'assignees' => $assignments($task), 'messages' => [],
            ];
        }
        foreach ($messages as $message) {
            if (! is_array($message) || ! isset($message['workflow_task']) || ! isset($known[(string) $message['workflow_task']])) {
                throw new InvalidArgumentException('Every legacy message must belong to a task in this run.');
            }
            $normalized[(string) $message['workflow_task']]['messages'][] = ['author' => $actor($message['author_role'] ?? null, 'author_role', $message), 'body' => $message['message_text'] ?? null, 'created_at' => $timestamp($message['create_date'] ?? null, 'create_date', $message)];
        }
        foreach ($rejections as $rejection) {
            if (! is_array($rejection) || ! isset($known[(string) ($rejection['current_task_id'] ?? '')]) || (($rejection['next_task_id'] ?? null) !== null && ! isset($known[(string) $rejection['next_task_id']]))) {
                throw new InvalidArgumentException('Every legacy rejection must reference tasks in this run.');
            }
        }

        return HistorySnapshot::fromArray([
            'source' => $source, 'subject' => ['type' => $subject->getMorphClass(), 'id' => (string) $subject->getKey()], 'status' => $status, 'tasks' => array_values($normalized),
            // Retain fields without invented modern meaning: due dates, soft deletion, role/org links,
            // predecessor/next pointers, rejection codes, and original timestamp precision.
            'evidence' => ['tasks' => $tasks, 'messages' => $messages, 'rejections' => $rejections],
        ]);
    }
}
