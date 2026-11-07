# History import

Import saved workflow records through a reviewed `Migration\HistorySnapshot`. The importer preserves task history and evidence, establishes the saved subject relation and resumes active work through the published definition.

## Snapshot format

```php
use MrNewport\LaravelWorkflow\Migration\HistorySnapshot;

$snapshot = HistorySnapshot::fromArray([
    'source' => 'archive:123',
    'subject' => ['type' => $document->getMorphClass(), 'id' => (string) $document->getKey()],
    'status' => 'active',
    'tasks' => [
        [
            'source_id' => '10',
            'step' => 'draft',
            'started_at' => '2026-10-01T09:00:00-07:00',
            'finished_at' => '2026-10-02T09:00:00-07:00',
            'action' => 'submit',
            'actor' => 'user:12',
            'assignees' => [['type' => 'user', 'value' => '12']],
            'messages' => [
                ['author' => 'user:12', 'body' => 'Ready for review', 'created_at' => '2026-10-02T09:00:00-07:00'],
            ],
        ],
        [
            'source_id' => '11',
            'step' => 'review',
            'started_at' => '2026-10-02T09:00:00-07:00',
            'finished_at' => null,
            'action' => null,
            'actor' => null,
            'assignees' => [['type' => 'user', 'value' => '15']],
            'messages' => [],
        ],
    ],
    'evidence' => $sourceEvidence,
]);
```

`source` identifies one exported run. Source task ids are unique strings, and every `step` maps to a local step in the selected `Definition`. Subject type must match the saved model's class or morph alias; its id must match exactly. The locked saved model is validated through native Flow's subject identity contract, including the configured integer, UUID or ULID storage type and database range.

Status is `active`, `completed` or `cancelled`. Active snapshots need at least one pending task; completed/cancelled snapshots cannot contain pending tasks. A pending task has null completion time, action and actor. A completed task may retain null action or actor when that information is unavailable. Preserve missing attribution as missing.

Assignments contain `type` (`user` or `email`) and a string `value`. Duplicate identities and invalid email addresses are rejected. Messages contain a nonempty author/body and an explicit timestamp. There may be 1–10,000 tasks per snapshot, up to 250 assignments and 1,000 messages per task. The encoded snapshot limit is 16 MiB.

Timestamps require ISO 8601 with an explicit timezone. Operational rows use the same application storage timezone as normal native Flow writes, at **second precision**; their hydrated instants render correctly in UTC. Set `app.timezone` and PHP's default timezone consistently. The importer checks that each timestamp can round-trip through that storage timezone before writing. A daylight-saving fold instant that the naive local storage cannot represent is rejected; use UTC storage for such historical instants. Choose that storage contract before initial writes; changing the timezone of populated native tables requires a reviewed conversion and must not be done temporarily around one import. Supported years are 1000–9999. The forward MySQL migration widens workflow historical date columns to `DATETIME`, including messages and run completion. It preserves existing data and keeps the widened columns on rollback rather than narrowing valid history back into the 1970–2038 `TIMESTAMP` range. Coordinate this migration with the native Flow date migration.

Exact source strings, offsets, microseconds, due dates and deletion state can be retained in immutable `evidence`. Keep a consistent source export alongside the normalized snapshot.

Snapshots retain JSON number types, including the distinction between `1` and `1.0`, when stored as evidence. PHP references are detached during construction, so changes to the caller's arrays cannot change the reviewed snapshot or fingerprint.

## Review and import

1. Export each run's subject binding, tasks, assignments, messages, rejection records and associated evidence consistently. Include deletion state when it affects whether work is active.
2. Resolve source entity aliases and ids to the saved subject model, confirm workspace ownership and map assignments to permitted current user/email identities. Stop an active import when responsible identities cannot be established.
3. Select the versioned `Definition` and explicitly map every task to its local step. Resolve source timezone and daylight-saving ambiguities before producing timestamp strings.
4. Inspect `HistorySnapshot::toArray()` and retain the approved `fingerprint()`. Bind `Contracts\ImportAuthorizer` to validate source provenance, ownership and the reviewed mappings. Normal `WorkflowAuthorizer::authorizeStart()` authorization is also required. Both defaults refuse access.
5. Import the exact approved snapshot:

```php
$run = $importer->import(
    $definition, $savedSubject, $snapshot, $scope, $actor,
    $requestKey, $reviewedFingerprint,
);
```

Run, Flow and subject records must share one database connection. The importer locks and reauthorizes the saved subject, publishes the definition, writes historical Flow rows and stores evidence atomically. Source rows remain unchanged. Import executes no domain callback and emits no transition or notification events.

Compare task counts, source ids, completion states, assignments, timestamps, actor/action nulls, messages, rejection links and source hashes. `workflow_imports.task_mapping` records each source task id and its Flow instance-step id. Verify the subject relation with the configured morph map, then exercise an allowed resumed action and the target database's conflict/rollback behavior before enabling writes through the imported run.

Exact reimports with the same scope, source, definition, subject, actor and snapshot return the existing run. Changed mappings or evidence for an imported source fail. A source has at most one imported run in each scope; different scopes remain distinct. Import request keys share the normal start-key uniqueness boundary.

## Tabular export mapping

`Migration\LegacySnapshotMapper::map()` converts task/message/rejection arrays using explicit mapping closures:

```php
$snapshot = $mapper->map(
    $sourceKey, $savedSubject, $reviewedStatus,
    $exportedTasks, $exportedMessages, $exportedRejections,
    step: fn ($sourceStep, array $row) => $reviewedStepMap[$sourceStep],
    actor: fn ($id, string $kind, array $row) => $reviewedActorMap[$kind][$id],
    assignments: fn (array $row) => $reviewedAssignmentMap[(string) $row['id']],
    timestamp: fn ($value, string $column, array $row) => $reviewedTimestampMap[$column][$value],
);
```

Task rows use `id`, `task`, `date_queued`, `date_done`, `action_done` and `user_done`. Message rows use `workflow_task`, `author_role`, `message_text` and `create_date`; rejection rows reference `current_task_id` and optional `next_task_id`. The helper rejects links outside the supplied run and retains complete source rows in `evidence`.

Mapping closures are trusted application code. Throw when an identity, time or step cannot be resolved. Supply string ids, user/email assignment records and explicit timestamp strings. The host obtains the consistent export under its own access rules; the mapper performs no database queries.

## Saved evidence compatibility

Numeric-key object arrays that could become a contiguous list are refused for new evidence. JSON object/list shape is significant to review identity. Saved raw JSON evidence remains unchanged; a reimport that encounters such a historical object fails with an explicit review requirement instead of reinterpreting it as a list. Ordinary saved snapshots and their fingerprints continue to round-trip.

## Mapper callback reference

Exact signature:

```php
LegacySnapshotMapper::map(
    string $source, Model $subject, string $status,
    array $tasks, array $messages, array $rejections,
    Closure $step, Closure $actor, Closure $assignments, Closure $timestamp,
): HistorySnapshot
```

| Callback | Every invocation | Required return / failure policy |
| --- | --- | --- |
| `step($sourceStep, array $taskRow)` | Once per task; `$sourceStep` is `task` | Local step ID string in the selected reviewed definition. Throw when unknown; retain original step key in evidence. |
| `actor($sourceIdentity, string $kind, array $row)` | Completed task with non-null `user_done` uses kind `user_done`; every message uses kind `author_role` with `author_role` or null | Mapped nonempty actor string (max 191 characters). Distinguish role IDs from user IDs; never invent an actor for missing completion attribution. Pending task completion actor remains null. Message author must be resolved or import fails. |
| `assignments(array $taskRow)` | Once per task, including completed tasks | List of `['type'=>'user'|'email','value'=>'exact-string']`; up to 250, duplicates fail. Host verifies current permitted identities for active work and records historic meanings explicitly. Return `[]` only when reviewed absence is valid. |
| `timestamp($sourceValue, string $column, array $row)` | Every `date_queued`; nonempty `date_done`; every message `create_date` | ISO 8601 string with explicit offset/Z, years 1000–9999. Resolve source timezone/DST and missing/invalid dates explicitly; throw when ambiguous rather than using current time. |

The mapper reads `action_done` directly without a separate callback. Convert old action codes to reviewed modern action text in the exported task rows before mapping, while retaining the unmodified source export in your external review evidence. A pending task with an old completion action must be corrected through a reviewed mapping; it cannot silently carry completion attribution. `date_done` uses PHP `empty()` to decide pending state; normalize source sentinel dates such as empty strings/zero/null before invoking the mapper. The callback receives the complete source row so it can use source organization/timezone metadata safely.

| Source object | Columns read by mapper | Preserved additional evidence |
| --- | --- | --- |
| Task | `id` integer/string, `task`, `date_queued`, `date_done`, `action_done`, `user_done` | Full task row, including due dates, soft deletion, roles/org links, predecessor/next pointers and original precision |
| Message | `workflow_task`, `author_role`, `message_text`, `create_date` | Full message row |
| Rejection | `current_task_id`, nullable/omitted `next_task_id` | Full rejection row and rejection codes |

Every task ID is converted to string and must be unique. Every message/rejection link must reference a task supplied in this exact run; a next rejection task may be null. An `id` of integer 10 and string `'10'` is the same source ID. Unknown source-only properties are allowed in raw mapper evidence; unknown properties at normalized snapshot/task/message/assignee levels are refused. The mapper validates all normalized fields through `HistorySnapshot::fromArray`; the importer separately validates graph membership, subject identity, storage timezone and policies.

A reviewed mapper recipe with explicit failure for unresolved values:

```php
$required = static function (array $map, string $key) {
    if (! array_key_exists($key, $map)) {
        throw new \InvalidArgumentException('Unresolved reviewed source value: '.$key);
    }
    return $map[$key];
};
$snapshot = app(LegacySnapshotMapper::class)->map(
    'purchases:42', $purchase, 'active', $tasks, $messages, $rejections,
    step: fn ($value, array $row) => $required($stepMap, (string) $value),
    actor: fn ($value, string $kind, array $row) => $required($actorMaps[$kind], (string) $value),
    assignments: fn (array $row) => $required($assignmentMap, (string) $row['id']),
    timestamp: fn ($value, string $column, array $row) => $required($timestampMaps[$column], (string) $value),
);
// Retain this fingerprint in the external review record; import only the approved exact value.
$reviewFingerprint = $snapshot->fingerprint();
```

Maps are reviewed application data created from one consistent source export, not values accepted blindly from an HTTP request. Retain the export hash and approval outside the mutable request. The mapper's `evidence` contains the provided rows verbatim as finite JSON data. It preserves facts without deciding whether a historical deletion, rejection or due date should change the current domain workflow.

## Complete normalized bounds and guards

All normalized objects reject unknown keys. `source` and task `source_id` are nonblank strings up to 191 characters; subject `type` and `id` up to 255; step text up to 64; optional completed action up to 100; optional completed actor and message author up to 191; message body up to 10,000. Snapshot strings reject NUL and blank values. Task lists contain 1–10,000 items, assignments at most 250 and messages at most 1,000 per task. Evidence must be an array of finite JSON data and the canonical whole snapshot must fit 16 MiB. Referenced PHP arrays detach before fingerprinting.

A finish time cannot precede start. ISO timestamps require seconds, allow 1–6 fractional digits, require `Z` or an offset up to ±14:00, and reject invalid calendar dates. Active status requires at least one unfinished task. Completed/cancelled status requires all tasks finished. Missing completed action/actor remains null. Empty historical assignments are allowed; pending-task membership and whether an unassigned task may resume belong to import policy. The snapshot does not prove that tasks form an executable graph history; review that semantic fact separately.

The importer requires scope, actor and request key nonblank, at most 191 characters and no ASCII controls/DEL. The saved subject must exist, be on the same named connection as Workflow/Flow and match snapshot class/morph alias plus exact string key. Native subject identity validates configured integer/UUID/ULID schema and range. It checks every task's local step and all dates before writes. Both import and start policy run before and under the saved subject lock, including reimport. All imported assignments need explicit import-policy approval; `AssignmentAuthorizer` is a reassignment hook and is not called here.

The imported run begins at version 1 with one immutable `import` receipt. Completion actors remain in the reviewed snapshot; task rows preserve completion action, while messages attach to the import receipt and their real instance task IDs. Evidence stores a source-to-step mapping. Import changes no source row, executes no domain callback and dispatches no transition/Workflow notification event. A failure rolls back all imported rows. A new key does not override the per-scope source uniqueness guard; changed definition/actor/evidence/mapping conflicts.

## Upgrade recipes

For a fresh import, choose a consistent UTC storage contract before migrations/writes, install Flow/Workflow migrations, bind restrictive import/start policies, export one source run consistently, review maps and exact fingerprint, import into a disposable copy, reconcile every count/identity/time/evidence hash and test one permitted resumed action. Then use the approved same binding in the destination database and enable only authorized application writers.

For an existing Workflow database, back up all coordination/engine/subject tables together, retain morph/key/timezone settings, apply forward migrations before new writes and preserve old immutable hashes. Existing command receipts default to fingerprint version 1; new ones use 2. A version-1 payload containing a multi-item list anywhere needs explicit evidence review before replay, because the old hash cannot prove its original list/object shape. Never rerun a domain callback to discover whether it already committed. Inspect its receipt/current domain result and use a reviewed recovery service. Ordinary saved scalar/object retries retain compatibility.

For saved imports, preserve raw JSON evidence and original hash. If an old numeric-key JSON object would become a contiguous list under Eloquent's associative cast, reimport fails closed for explicit evidence review. Normal unambiguous snapshots remain reusable. New evidence must use named keys or true lists.

For timezone or subject-key changes, use an explicit host migration and reconciliation plan across populated tables; config changes are not a conversion. Naive local DST fold storage may not represent both instants. The importer refuses a failed round trip; it does not temporarily switch timezone. Source microseconds/offsets and dates outside the operational precision remain in evidence. Apply both native Flow and Workflow MySQL date-widening migrations for history outside the TIMESTAMP range. Down migrations intentionally keep wider dates; do not narrow historical values during rollback. See the [handbook recovery section](HANDBOOK.md#upgrade-and-recovery) for the complete operational sequence.
