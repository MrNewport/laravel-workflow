# Laravel Workflow handbook

This guide describes the public Workflow 1.0 API. Workflow coordinates scoped human decisions and durable command evidence; Laravel Flow 2.2 executes transitions and assignments. Workflow works without Laravel Fields, Filament, Livewire, Tools, a frontend build, or HTTP routes. Fields is an optional `ActionValidator` adapter. Tools is an optional console review and publication package.

## Contents and feature coverage

| Feature / public surface | Guide and runnable evidence |
| --- | --- |
| Installation, PHP/framework requirements, database configuration, migrations, container bindings | [Install](#install); [Procurement example](../examples/procurement/README.md) |
| `WorkflowAuthorizer::authorizeStart/authorize`, actor/scope resolution, replay authorization | [Authorization](#authorization); example `ProcurementPolicy` |
| `AssignmentAuthorizer::authorizeAssignment`, destination membership | [Authorization](#authorization), [Reassign](#reassign); example and documentation test |
| `ActionValidator::validate`, default empty input, optional `FieldsActionValidator::register/validate` | [Payload validation](#payload-validation) |
| `Definition::fromArray/fromStoredArray/toArray/fingerprint/step/stepIds` | [Definition grammar](#definition-grammar) |
| `DefinitionPublisher::publish/flowStepId/localStepId/assertEngineMatches` | [Publishing](#publishing) |
| `WorkflowManager::start/act/comment/reassign/cancel/assertSameConnection` | [Commands](#commands); executable procurement lifecycle |
| Locks, retries, expected versions, exact idempotency, rollback, immutable receipts and messages | [Command contract](#command-contract), [Transactions and delivery](#transactions-and-delivery) |
| Branches, cycles, rejection, final run status, cancellation | [Branches and completion](#branches-and-completion) |
| `WorkflowRun::inScope/pendingSteps/commands/messages/history/historyMessages/publishedDefinition/flowInstance` | [Queries and history](#queries-and-history) |
| `PublishedDefinition::definition`, model retention, `WorkflowImport` evidence, command details | [Data and receipts](#data-and-receipts) |
| Blade timeline/messages, pagination, Tailwind, localization, accessibility | [Presentation](#presentation) |
| `WorkflowChanged`, Flow events, outbox and retry behavior | [Transactions and delivery](#transactions-and-delivery) |
| `HistorySnapshot::fromArray/toArray/fingerprint/date`, every snapshot field | [History import](#history-import); [complete import reference](migration.md) |
| `LegacySnapshotMapper::map`, all four callbacks, historical facts and links | [Legacy mapping](#legacy-mapping); [mapper reference](migration.md#mapper-callback-reference) |
| `HistoryImporter::import`, provenance, subject keys, reconciliation, no replay of effects | [Import execution](#import-execution) |
| Fingerprint version 1, numeric objects/lists, wide dates, timezone/DST and safe upgrade | [Upgrade and recovery](#upgrade-and-recovery) |
| Every error family, uncertain completion, database testing | [Errors and troubleshooting](#errors-and-troubleshooting), [verification](verification.md) |

## Install

Use PHP 8.3 or newer with Laravel 12 or 13. Laravel's own platform requirements also apply. Create a fresh Laravel app using your normal Laravel installer, configure its database in `.env`, then install:

```sh
composer require mrnewport/laravel-workflow:^1.0
php artisan vendor:publish --tag=workflow-config
php artisan migrate
```

Composer's normal registry command works once the releases are indexed. If Composer cannot find them, inspect the public GitHub tags and add VCS repositories for **both** dependencies before retrying. A public GitHub release and Packagist indexing are separate states:

```sh
composer config repositories.mrnewport-flow vcs https://github.com/MrNewport/laravel-flow
composer config repositories.mrnewport-workflow vcs https://github.com/MrNewport/laravel-workflow
composer require mrnewport/laravel-workflow:^1.0
```

Use actual stable release tags; a repository URL alone does not make an unpublished version installable. Production Workflow depends on Flow `^2.2` and Illuminate support/database/contracts/validation `^12 || ^13`. Fields is a development dependency of the package's own tests and an optional runtime suggestion. Your production app needs no Fields package unless it uses that adapter. Tools has its own mandatory Workflow dependency; see its handbook.

Both providers discover and load their migrations automatically. Workflow adds `workflow_definitions`, `workflow_runs`, `workflow_commands`, `workflow_messages` and `workflow_imports`; Flow adds its engine tables. Run migrations on a backed-up deployment database before enabling writers. Do not use `migrate:fresh` on saved data. Publish tags are `workflow-config`, `workflow-views`, `workflow-translations`; Flow publishes its own configuration. There is no workflow route, authentication middleware, notification driver or queue worker installed for the host.

All engine, coordination and subject records must resolve to the **same named database connection**, with real transactions. `WorkflowManager::assertSameConnection(Model $subject): void` checks the subject, run, Flow instance and step connection names and throws `InvalidArgumentException` on mismatch. A transaction callback that writes another connection is outside this atomic boundary.

Choose subject storage before first migration: Flow `morph_key_type` supports integer, UUID and ULID keys. It must agree with the real subject key and existing engine schema. Changing config does not convert populated polymorphic columns. Keep the model's `getKeyType()`, incrementing behavior, morph map and key spelling correct. Import uses the same native subject validation, including integer database range. See Flow's migration guide for a reviewed key conversion.

`config/workflow.php` contains:

```php
return [
    'max_definition_edges' => 10000,
    'history_per_page' => 50,
];
```

Edge budgets apply to newly admitted definitions; valid values are integers 1–625,000. History page sizes are integers 1–100. An invalid configured history size fails when queried rather than silently loading an unlimited collection.

## Authorization

All policy contracts are trusted application code. Scope and actor strings provide identity context and attribution; they are not credentials. Derive them from an authenticated session, verified service principal and authorized workspace. Never allow submitted `actor`, `scope`, `run_id` or `step_id` alone to authorize work. The same service also works in console and queue contexts when the host resolves an explicit principal.

Bind policies in your application's provider:

```php
use MrNewport\LaravelWorkflow\Contracts\{ActionValidator, AssignmentAuthorizer, ImportAuthorizer, WorkflowAuthorizer};

$this->app->bind(WorkflowAuthorizer::class, ProcurementPolicy::class);
$this->app->bind(AssignmentAuthorizer::class, ProcurementPolicy::class);
$this->app->bind(ActionValidator::class, ProcurementPayloadValidator::class);
// Bind ImportAuthorizer only when a separately reviewed import flow exists.
```

The default `DenyAllAuthorizer`, `DenyAllAssignments` and `DenyAllImports` throw Laravel `AuthorizationException`. `EmptyPayloadValidator` permits `[]` and refuses any other payload with `ValidationException`. Publishing a graph does not open access to running it.

```php
interface WorkflowAuthorizer {
    public function authorizeStart(Definition $definition, Model $subject, string $scope, string $actor): void;
    public function authorize(WorkflowRun $run, ?FlowInstanceStep $step, string $action, string $actor): void;
}
interface AssignmentAuthorizer {
    public function authorizeAssignment(WorkflowRun $run, FlowInstanceStep $step, string $actor, array $current, array $proposed): void;
}
interface ImportAuthorizer {
    public function authorizeImport(Definition $definition, Model $subject, HistorySnapshot $snapshot, string $scope, string $actor): void;
}
```

The imports use `Illuminate\Database\Eloquent\Model`, `MrNewport\LaravelFlow\Models\FlowInstanceStep` and Workflow's `Definition`, `Models\WorkflowRun`, `Migration\HistorySnapshot`.

`authorizeStart` runs before work and again after locking/reloading the saved subject. Check definition/version entitlement, current membership, subject ownership and permissible domain status. `authorize` runs inside the locked scoped run, including exact retries. Its step is null for run operations; do not assume it is always a task. For `cancel-run`, policy first authorizes the run, then each pending step is authorized with action `cancel`. Distinguish `comment`/`reassign` from graph actions. A replay may reference a finished step: authorize the already committed decision using current access policy without requiring it still be pending; Workflow checks pending state for new mutations itself.

Assignment records are not authentication or automatic action authorization. Your policy must decide whether a member may act on an assigned task, whether an administrator may reassign it, and who can comment after completion. Check ownership via the run's subject and scope even when the caller already supplied a scoped model. Destination authorization receives lists of `['type' => 'user'|'email', 'value' => 'exact-string']`. Validate **every** proposed identity against current workspace/team membership, including email allowlists, before returning. An admin's authority to reassign does not establish destination eligibility. Initial static definition assignments also require a trusted publication/start policy; the reassignment hook does not authorize initial assignments or imports.

The [procurement example](../examples/procurement/README.md) implements current membership, exact actor keys, subject ownership, assigned reviewer checks and destination membership using real query-backed models.

## Definition grammar

A definition is executable **data**, constructed with `Definition::fromArray(array $data): Definition`. It never loads class names, callbacks or expressions from graph data. This complete graph demonstrates a revision cycle and parallel review:

```php
$definition = Definition::fromArray([
    'name' => 'procurement-review', 'version' => 1, 'initial' => 'draft',
    'steps' => [
        ['id' => 'draft', 'name' => 'Prepare purchase', 'transitions' => [
            'submit' => ['finance', 'security'], 'cancel' => ['END'],
        ]],
        ['id' => 'finance', 'name' => 'Finance review',
            'assignment_strategy' => 'single_user', 'assignment_params' => ['user_id' => '2'],
            'transitions' => ['approve' => ['END'], 'revise' => ['draft'], 'cancel' => ['END']]],
        ['id' => 'security', 'name' => 'Security review',
            'assignment_strategy' => 'multi_user', 'assignment_params' => ['user_ids' => ['3', '4']],
            'transitions' => ['approve' => ['END'], 'cancel' => ['END']]],
    ],
]);
```

| Property | Accepted values and limits |
| --- | --- |
| Root | Only `name`, `version`, `initial`, `steps`; unknown properties fail |
| `name` | Lowercase portable identifier, maximum 80 bytes |
| `version` | Actual PHP integer 1–2,147,483,647; a numeric string fails |
| `initial` | Existing local step ID, maximum 64 bytes |
| `steps` | A list of 1–250 step objects |
| Step properties | Only `id`, `name`, `assignment_strategy`, `assignment_params`, `transitions` |
| `id` | Unique case-sensitive local identifier, maximum 64 bytes; `END` is reserved |
| Step `name` | Optional label defaults to ID; nonblank plain text, maximum 255 characters, no ASCII control characters 0–31 |
| Strategy | Null, `single_user`, `multi_user`, `email_list` |
| Null strategy params | Omitted or `[]`; other values fail |
| Single-user params | Only `user_id`, containing one valid integer or string identity |
| Multi-user params | Only `user_ids`, containing a nonempty list of distinct identities |
| Email-list params | Only `emails`, containing a nonempty list of distinct valid email strings |
| Assignment count | 1–250 and native Flow's configured `max_assignments` admission limit |
| `transitions` | 1–50 action keys per step |
| Action | Lowercase portable identifier, maximum 100 bytes; reserved: `start`, `comment`, `reassign`, `cancel-run`, `import` |
| Targets | Nonempty list of up to 50 distinct local IDs or exactly `['END']`; cannot mix `END` and other targets |
| Total graph | Every step reachable from initial; every step has a path to `END`; default 10,000 edges; normalized compact JSON plus newline fits 1 MiB |

A portable identifier starts with an ASCII letter, followed only by ASCII letters, digits, `.`, `_` or `-`. Names/actions must be lowercase, while step IDs retain case. Numeric-only graph IDs therefore fail; identity **values** can be numeric strings. Assignee identities are nonblank integer/string values whose string encoding is at most 255 bytes, without ASCII control/DEL characters. Flow compares identities by exact string after normalization, so `[1, '1']` is a duplicate. Email validation does not check that an account exists.

Cycles are allowed when all nodes retain a path to `END`. `END` is branch completion; cancellation has its own command/policy. There is no implicit join, wait-for-all step, timed scheduler, automatic escalation, quorum or callback dispatch in the graph.

`toArray(): array` returns normalized steps sorted by ID, action keys sorted, target IDs sorted and explicit defaults. List order for assignment identities is retained. `fingerprint(): string` returns canonical SHA-256 including name, version, initial, labels, assignments and transitions. Equivalent permitted object/graph ordering gives the same identity; changing a label or version changes it. `step(string $id): array` throws on unknown ID. `stepIds(): array` returns sorted local IDs.

`fromStoredArray(array $data): Definition` exists for trusted saved snapshots. It preserves structural/identity validation while exempting saved definitions from newly reduced edge/byte/assignment admission budgets. Use `fromArray` for new proposals; do not use stored hydration to bypass admission limits. `PublishedDefinition::definition()` additionally verifies the snapshot's fingerprint and stored name/version.

Finite JSON primitives and arrays are accepted. Objects, resources, uploaded-file objects, infinities, NaN, recursive arrays and nesting beyond 512 fail. PHP references are detached before immutable fingerprints are calculated. Object key order is normalized without treating a list as an object; `1` and `1.0` remain distinct. A reordered numeric-key object whose keys could become a contiguous list is refused at the input boundary. Prefer named keys or a true list and preserve that shape through export/storage. Tools JSON reading also rejects duplicate or escaped-equivalent object keys.

## Publishing

```php
$publisher = app(DefinitionPublisher::class);
$published = $publisher->publish($definition); // PublishedDefinition
$engineId = $publisher->flowStepId($definition, 'finance'); // string
$localId = $publisher->localStepId($definition, $engineId); // 'finance'
$publisher->assertEngineMatches($definition); // void, throws on drift
```

Publication writes the immutable snapshot and native Flow steps/transitions in one transaction. The fingerprint is the definition primary key, and `(name, version)` is unique. An identical publication returns the existing model; different content under the same pair throws `WorkflowConflict`. `start` also publishes automatically. Operators may review/publish beforehand with Workflow Tools. Existing runs retain their selected definition even after later versions are published.

Engine IDs use `w:<64-character fingerprint>:<24-character hash of local ID>`, fit Flow's 100-character limit and preserve local case distinctions on common case-insensitive database collations. Treat them as opaque: use the mapping methods and real `FlowInstanceStep.id` when actioning tasks. A local string ID is not an instance step's integer ID.

`flowStepId` validates local membership and throws `InvalidArgumentException` for an unknown local ID. `localStepId` throws `WorkflowConflict` when an engine ID is outside the definition. `assertEngineMatches` checks expected names, `notify=false`, assignments and all transitions using batched reads; tampered engine rows fail closed. Do not edit namespaced Flow definitions independently or attach generic Flow notifications to them. New behavior requires a new version and an explicit application transition/migration policy for already active work; there is no automatic run rebinding API.

## Commands

The operations below share the [command contract](#command-contract).

## Command contract

All manager operations return a refreshed/current `WorkflowRun`, not a command receipt. Obtain immutable results from `$run->commands()`. Exact retries return the **current run**, which may have advanced beyond the replayed receipt's `result_version`/`result_status`. Retain your submitted key, step, expected version and payload when retrying an uncertain response.

- Scope and actor are nonblank strings, at most 191 characters, without ASCII control/DEL characters. Request keys are nonblank strings at most 255 characters under the same character restriction; import's limit is 191.
- Request keys are SHA-256 hashed at rest. Start keys are unique per exact scope across subjects. Subsequent command keys are unique per run; the start/import receipt consumes its original key too.
- Every new mutation locks the scoped run, verifies positive expected version and, when supplied, a positive instance step ID belonging to that run. Expected version starts at 1 and increments once for every accepted action, note, cancellation or reassignment.
- Authorization happens before replay returns. New writes check current status and step pending state; comments may target finished runs/steps.
- Fingerprints include run, step, action, scope, actor, submitted expected version and command data. Changing payload, message, assignment list or JSON shape under an existing key causes a conflict. Closures are trusted code and are not fingerprinted: a replay does not execute a newly supplied callback.
- Message length is at most 10,000 characters, without NUL. A non-null blank action/cancel message remains part of input identity but creates no message row. Comments require nonblank text.
- Encoded mutation input must be at most 1 MiB. Payloads are captured/detached before policy hooks. Validation returns only normalized values for host persistence; raw payloads are fingerprinted and are not automatically saved.
- A validation, authorization, invariant, persistence, callback or transaction failure rolls back all writes in that transaction. Mandatory Eloquent creates/saves cancelled by an observer fail rather than reporting successful mutation.

### Start

```php
start(Definition $definition, Model $subject, string $scope, string $actor,
    string $idempotencyKey, ?Closure $withinTransaction = null): WorkflowRun
```

The subject must already be saved and pass native Flow's configured subject identity contract. Start locks/reloads it, reauthorizes, publishes, calls native `FlowManager::startFlow`, creates a version-1 active run, invokes the optional callback and writes a `start` receipt with pending step IDs. Callback arguments are `(WorkflowRun $run, Model $lockedSubject)`. Use that locked model for domain initialization.

```php
$run = $manager->start($definition, $purchase, $scope, $actor, $requestKey,
    function ($run, $lockedPurchase) {
        $lockedPurchase->update(['workflow_run_id' => $run->id]);
    });
```

An exact retry checks access and returns the original run without rerunning the callback. A reused scope/start key for another definition, subject or actor fails. There is no general one-active-run-per-subject rule: implement that domain invariant using the locked subject and a unique/domain guard when required.

### Act

```php
act(WorkflowRun $run, int $stepId, string $action, string $scope, string $actor,
    string $idempotencyKey, int $expectedVersion, array $payload = [],
    ?Closure $withinTransaction = null, ?string $message = null): WorkflowRun
```

An action must be defined on the selected local step. The validator runs after authorization and graph drift checking. Callback arguments are `(WorkflowRun $lockedRun, FlowInstanceStep $step, array $validated)`. It runs before Flow finishes the task and creates successors. Lock/reload the domain model if other commands can change it. Native Flow supplies assignments for successor tasks. The receipt records the actual created instance step IDs.

```php
$step = $run->pendingSteps()->sole();
$run = $manager->act($run, $step->id, 'submit', $scope, $actor, $key, $run->version,
    payload: ['title' => 'Replacement hardware'],
    withinTransaction: function ($run, $step, array $validated) {
        $purchase = $run->flowInstance->entity;
        $purchase = $purchase->newQuery()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();
        $purchase->update($validated + ['status' => 'in_review']);
    }, message: 'Budget details attached');
```

### Comment

```php
comment(WorkflowRun $run, string $scope, string $actor, string $idempotencyKey,
    int $expectedVersion, string $message, ?int $stepId = null): WorkflowRun
```

Appends an immutable plain-text note and receipt, increments version and leaves current terminal status/finished time intact. It accepts a run or task note after completion if host policy permits. A task ID must still belong to the run. No domain callback is available. Render notes as escaped text, never trusted HTML or Markdown.

### Reassign

```php
reassign(WorkflowRun $run, int $stepId, array $assignees, string $scope,
    string $actor, string $idempotencyKey, int $expectedVersion): WorkflowRun
```

Requires a pending step in an active run and an existing published assignment strategy. The new list has 1–250 distinct identities (also subject to native Flow's current assignment limit); a single-user strategy requires exactly one. Email strategies require valid emails. Unassigned steps cannot be reassigned through this API. Workflow authorization checks administrative permission, then the separate assignment policy validates destination membership under lock. Flow's strategy replaces assignments atomically. Receipt `details.assignments_before` and `.assignments_after` preserve exact type/value records. Reassignment creates no new task, message or transition; version still increments. All assignees must be supplied; this is replacement, not append.

### Cancel

```php
cancel(WorkflowRun $run, string $scope, string $actor, string $idempotencyKey,
    int $expectedVersion, ?string $message = null): WorkflowRun
```

Requires an active run. Every pending branch must define exactly `cancel => ['END']`, and policy must approve each branch before any branch closes. All steps close in one transaction through Flow; the receipt action is `cancel-run`, result status `cancelled`. If any branch lacks support or permission, none closes. An ordinary `act(..., 'cancel', ...)` finishes only the selected branch and derives normal completion; use `cancel()` when recording run cancellation is intended. Cancellation is not rollback of prior domain decisions and has no domain callback. Implement necessary domain cancellation/outbox updates in a host service wrapping this call in the same database transaction.

## Payload validation

Without Fields, bind an application validator using Laravel's validator. This implementation scopes schemas to the published local step/action and returns only allowed keys:

```php
final class ProcurementPayloadValidator implements ActionValidator
{
    public function validate(WorkflowRun $run, FlowInstanceStep $step, string $action, array $payload): array
    {
        $definition = $run->publishedDefinition->definition();
        $local = app(DefinitionPublisher::class)->localStepId($definition, $step->step_id);
        if ($definition->name === 'procurement-review' && $definition->version === 1
            && $local === 'draft' && $action === 'submit') {
            return validator(['payload' => $payload], [
                'payload' => ['required', 'array:title,amount_cents'],
                'payload.title' => ['required', 'string', 'max:120'],
                'payload.amount_cents' => ['required', 'integer', 'min:1', 'max:10000000'],
            ])->validate()['payload'];
        }
        if ($payload !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['payload' => 'This action takes no input.']);
        }
        return [];
    }
}
```

The code needs the contract/model imports shown above and `DefinitionPublisher`. Rejecting unknown keys explicitly avoids silently accepting authority-bearing properties. Keep pricing, budget authority and business invariants in the domain callback; validation alone cannot authorize spending.

With optional `mrnewport/laravel-fields:^1.0`, register trusted schemas before resolving the manager:

```php
use MrNewport\LaravelFields\{Field, Schema};
use MrNewport\LaravelWorkflow\Validation\FieldsActionValidator;

$schema = Schema::make([Field::text('title')->required()]);
$validator = (new FieldsActionValidator)->register('document-review', 1, 'draft', 'submit', $schema);
$this->app->instance(ActionValidator::class, $validator);
```

`register(string $definitionName, int $version, string $step, string $action, Schema $schema): self` returns the adapter for chaining. `validate(...)` resolves the pinned definition/local step and calls `Schema::validate`. If no schema is registered, `[]` succeeds and nonempty input fails with translated validation text. Register every version still accepting work. The adapter does not parse schema definitions from request data or graph JSON. Fields schemas can independently render Tailwind controls or create optional Filament components; Workflow does not require either UI. Files cannot be fingerprinted: stage authorized uploads in the host and submit a reviewed storage-reference string.

## Branches and completion

Use `$run->pendingSteps()` as the source of active work. Each fan-out target creates a separate instance step. Finishing one branch at `END` may set native Flow's convenience `current_step_id` to `END` while siblings remain pending; Workflow correctly remains `active` until **all** pending tasks finish. It then becomes `completed` and sets `finished_at`. Reject/revise actions are ordinary transitions to new tasks; they preserve previous task rows and messages.

A cycle from one parallel branch back to `draft` does not remove sibling branches. If the domain requires rejection to close sibling review or approvals to converge at one purchase decision, define that rule in an authorized host service; the engine has no implicit join/quorum. The procurement example uses a serial revision cycle so an `approved` purchase has one unambiguous final review; a separate tested graph demonstrates independent parallel completion and all-branch cancellation.

## Data and receipts

| Model | Read surface and retention |
| --- | --- |
| `PublishedDefinition` | `id` fingerprint, `name`, integer `version`, `snapshot`, timestamps; `definition()` reconstructs and verifies. Eloquent update/delete is refused. |
| `WorkflowRun` | Exact `scope_key`, `scope_hash`, definition/Flow instance IDs, hashed start key/fingerprint, `started_by`, status/version, terminal time, timestamps. Use service commands for writes. |
| `WorkflowCommand` | `run_id`, key hash, fingerprint/version, action/actor, optional instance `step_id`, result version/status, created `step_ids`, `details`, immutable creation time. `messages()` and `run()` are relationships. |
| `WorkflowMessage` | Run/command IDs, optional step ID, exact author/body and creation time; Eloquent update/delete is refused. |
| `WorkflowImport` | Run ID, scope/source hashes, snapshot hash, binding fingerprint, exact reviewed `snapshot`, source-to-instance `task_mapping`, creation time; update/delete is refused. JSON encoding preserves `1.0`. |

Reassignment details retain identity changes; import details retain source and snapshot fingerprint. Normal action payload content is not stored automatically. Persist required domain evidence deliberately inside the callback. The DB schema uses restrictive foreign keys to retain referenced history. Model immutability does not prevent privileged raw SQL: protect database write access and keep backups/audit controls. Never delete engine rows under a saved run.

## Queries and history

```php
$run = WorkflowRun::query()->inScope($scope)->findOrFail($runId);
// Authorize host read access here, before querying or rendering related history.
$definition = $run->publishedDefinition->definition();
$subject = $run->flowInstance->entity;
$pending = $run->pendingSteps()->with('assignees')->get();
$receipts = $run->commands()->where('action', 'approve')->get();
$notes = $run->messages()->where('author', $actor)->get();
$history = $run->history(perPage: 25, page: 1);
$messages = $run->historyMessages(commandId: $commandId, perPage: 50, page: 1);
```

`inScope` constrains both SHA-256 and exact scope text so differently cased scopes remain distinct on common database collations. It is a query boundary, not a permission check. Relationship builders `commands()`/`messages()` sort by ID. `pendingSteps()` is a builder restricted to unfinished tasks on the run's Flow instance.

`history(?int $perPage = null, ?int $page = null): LengthAwarePaginator` pages **queries**, counts messages and loads at most 50 message previews per receipt. The default comes from `workflow.history_per_page`; page name is `workflow_page`. Imported messages may all belong to one import receipt, so a preview does not represent the complete transcript.

`historyMessages(?int $commandId = null, int $perPage = 50, ?int $page = null): LengthAwarePaginator` provides the complete run messages, optionally confined to one receipt, using `workflow_messages_page`. Both enforce page sizes 1–100. A foreign command ID yields no messages from another run. Authorize read access and, when a route includes a command, load it through `$run->commands()`; never expose a globally loaded command through an unscoped route. Preserve filter query parameters with Laravel paginator `withQueryString()` as needed.

## Presentation

The package ships optional Blade partials, with no frontend JavaScript requirement:

```blade
@include('laravel-workflow::timeline', [
    'run' => $run,
    'history' => $run->history(25)->withQueryString(),
    'messageHistoryUrl' => fn ($command) => route('workflow.messages', [$run->id, $command->id]),
])

@include('laravel-workflow::messages', ['messages' => $run->historyMessages($command->id)])
```

The host creates both routes, authenticates the caller and authorizes the scoped run before including these views. The timeline can obtain `$run->history()` itself if `history` is omitted. Pass `messageHistoryUrl` to expose a full-message link when previews are truncated. A route closure for the messages page should load the run in scope, authorize read access, load `$run->commands()->findOrFail($commandId)`, then return its message paginator. Do not supply an untrusted arbitrary URL callback.

Compile static classes with Tailwind 4 in the host stylesheet (adjust the relative path to your stylesheet):

```css
@import "tailwindcss";
@source "../../vendor/mrnewport/laravel-workflow/resources/views";
```

There is no Workflow CSS binary to install. Ensure the host also scans overridden views and uses its chosen Laravel pagination view. `vendor:publish --tag=workflow-views` copies partials for customization. Preserve escaped `{{ ... }}` output, semantic ordered lists, status text, native links, visible keyboard focus and `<time datetime>` attributes. Views display UTC explicitly and support Tailwind dark variants. Choose any local-time presentation in your override while retaining the machine-readable instant and visible timezone. Test mobile wrapping, long messages, keyboard pagination and screen-reader names in the host layout.

`vendor:publish --tag=workflow-translations` copies language files to `lang/vendor/laravel-workflow/{locale}/messages.php`. Built-in statuses, action labels, pagination context and validation messages use package translations. Add your domain action keys under `action` to override the fallback title-cased action key. Author/subject labels and user messages remain exact data; do not localize stored audit values. Invariant/configuration errors remain explicit developer diagnostics.

## Transactions and delivery

Manager/publisher operations use real DB transactions with up to three transaction attempts for concurrency recovery. Starts serialize on the subject; mutations serialize on the scoped run and chosen task. Independent runs may proceed concurrently. Callbacks must be database-only, deterministic/retry-safe and use the same connection. A deadlock retry can execute callback code again before any accepted commit, even though exact **committed** replays never rerun it. Throwing after a domain update rolls back that update, Flow tasks, assignments, messages, version and receipt.

```php
DB::transaction(function () use ($manager, $run, $scope, $actor, $key) {
    $run = $manager->cancel($run, $scope, $actor, $key, $run->version, 'Requester withdrew');
    $purchase = $run->flowInstance->entity;
    $purchase = $purchase->newQuery()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();
    $purchase->update(['status' => 'withdrawn']);
});
```

`WorkflowChanged` implements `ShouldDispatchAfterCommit` and contains only `runId`, `commandId`, `action`, `actor`, `version` (all public readonly, IDs/version are ints). Events wait for the outermost transaction; rollback suppresses them. Native Flow action events are also available. Imports deliberately emit neither transition nor Workflow events and run no domain callback.

```php
Event::listen(WorkflowChanged::class, function (WorkflowChanged $event) {
    // A wake-up signal. The durable worker queries receipts and deduplicates delivery.
    ReconcileWorkflowNotifications::dispatch($event->runId);
});
```

An event/listener/job dispatch is not durable exactly-once delivery. For required notifications or external procurement work, write a host outbox row in the same DB transaction as the domain decision, with a unique logical operation key. Alternatively scan committed `WorkflowCommand` receipts and insert one outbox row per `(command_id, channel, recipient)`, with a periodic reconciliation job to catch missed event delivery. Store recipient policy, template/version, attempts, lease/retry state and delivered timestamp in the host. A worker should tolerate repeated delivery attempts and use the external service's idempotency facility. The runnable example commits an outbox row under a unique operation key; sending it is intentionally a separate host worker responsibility.

If an after-commit listener throws, the database may already be committed even though the caller sees an exception. Reload the run/receipt under authorization and retry the identical command key/input to determine its committed result. Do not generate a new request key merely because the response was lost. Use `WorkflowCommand.result_*` for original outcomes; current run status may have advanced. Keep exception details in the host's protected diagnostics rather than placing sensitive payloads in messages.

## History import

History import adopts facts without replaying business effects. It does not infer a valid historical path, replay old callbacks, send notifications, invent actors or convert missing source information into present-day decisions. Review provenance, source consistency, ownership, graph step mapping, identities, timestamps and domain state before accepting it.

`HistorySnapshot::fromArray(array $data): HistorySnapshot`, `toArray(): array`, `fingerprint(): string` and `date(mixed $value): DateTimeImmutable` form the normalized handoff API. The [complete schema and mapping guide](migration.md) describes every field, bound and callback. Construct source evidence with named keys or actual lists and retain an approved exact fingerprint outside the import request.

## Legacy mapping

`LegacySnapshotMapper::map(string $source, Model $subject, string $status, array $tasks, array $messages, array $rejections, Closure $step, Closure $actor, Closure $assignments, Closure $timestamp): HistorySnapshot` converts one consistently exported run in memory. It queries no source database. See [callback reference](migration.md#mapper-callback-reference) for each argument and source field. Callbacks are trusted application code and must throw on unresolved values. Store raw original rows, due dates, deletion state, role/org links and rejection pointers in evidence; the mapper preserves them rather than inventing runtime meaning.

## Import execution

```php
$run = app(HistoryImporter::class)->import(
    $definition, $savedSubject, $snapshot, $scope, $actor,
    $requestKey, $approvedFingerprint,
);
```

`import(Definition, Model, HistorySnapshot, string $scope, string $actor, string $idempotencyKey, string $reviewedFingerprint): WorkflowRun` requires saved same-connection subject, exact subject type/key and approved fingerprint. Both `ImportAuthorizer::authorizeImport` and ordinary `WorkflowAuthorizer::authorizeStart` run before and under the subject lock. Import policy must approve source provenance, all assignments and mappings; reassignment policy is not called for imported assignments. Tasks must map to defined local steps. Dates must round-trip through the application storage timezone before any writes.

The importer publishes, creates the native instance/tasks/assignees/messages directly, writes one `import` receipt and immutable evidence/mapping in one transaction. Imported runs start at version 1, including terminal runs. Historical task completion actors remain in the reviewed snapshot; native task rows retain completion action but have no separate historical actor column. Messages attach to the import receipt and their individual task IDs. Run terminal time is the latest historical task completion. Source rows remain untouched.

Exact reimport of the same `(scope, source)` binding returns its run after reauthorization, independent of a new unused request key; the original key/receipt remains. Changed snapshot, definition, subject or actor conflicts. Source uniqueness is per scope; import/start request keys share the run start-key boundary. Inspect `WorkflowImport.task_mapping` (`source_id`, integer `step_id`) and compare task count, states, assignments, messages, missing attribution and exact evidence before allowing resumed writes. Test an allowed pending action on a disposable copy first.

## Upgrade and recovery

1. Back up Workflow, Flow and subject tables together and capture versions, morph/key configuration and storage timezone. Pause competing import writers while reviewing the exact export.
2. Upgrade native Flow to compatible 2.2, then Workflow. Apply forward migrations, including Flow's date widening and Workflow's `fingerprint_version`/history-index/date migration. They preserve saved rows and existing hashes. Keep an application-compatible rollback plan; reverting code is not a data conversion.
3. Existing receipts are fingerprint version 1; new receipts use version 2. Ordinary saved definition/import hashes and safe scalar/object retries retain identity. Do not rewrite hashes. A v1 payload containing a multi-item list anywhere cannot prove its original numeric-object/list shape and requires explicit review before replay. Inspect the committed domain result/receipt, retain evidence and use a reviewed host recovery operation instead of guessing or rerunning a callback.
4. Existing raw import evidence with a numeric-key object that could be cast into a contiguous list requires evidence review on reimport. The stored raw JSON and hash remain intact; the importer refuses reinterpretation. Other saved imports round-trip normally.
5. Lowering new edge, byte or assignment admission budgets does not invalidate saved definitions or unrelated comments. New definitions/replacement assignments still obey current limits. Keep old validators/policies available for active versions.
6. Historical operational timestamps support years 1000–9999. MySQL forward migrations widen dates to `DATETIME`; down migrations deliberately retain wider dates rather than destroy pre-1970/post-2038 history. Rolling back the fingerprint migration removes version metadata/indexes and needs its own reviewed compatibility plan before later upgrade.
7. Set both `app.timezone` and PHP's default timezone consistently before normal writes/imports; UTC is the most portable historical storage contract. Source timestamps need explicit offsets. Naive local storage cannot distinguish both instants in a daylight-saving fold; the importer verifies the round trip and refuses an unrepresentable instant. Do not temporarily change timezone around an import into populated tables. Convert existing storage through a reviewed migration if changing the contract.
8. Operational historical dates have second precision. Preserve source offsets, microseconds, due dates and deletion state in immutable evidence. A timestamp crossing the supported year range after storage-zone conversion fails even if the source's year was valid.

For existing independent Flow consumers, install Workflow alongside their retained native history or explicitly map reviewed snapshots into new Workflow runs. Installing Workflow does not discover/adopt all Flow instances automatically. Keep ownership and domain state in the host. There is no application-specific adapter installed merely by adding Composer dependencies.

## Errors and troubleshooting

| Symptom / exception | Meaning and recovery |
| --- | --- |
| `AuthorizationException` | Default deny or host policy refused. Bind required contract, verify authenticated actor/scope, subject ownership, action/assignment policy and destination membership; do not bypass it with a broad allow-all policy. |
| `ValidationException` | Nonempty unregistered input, schema/rule failure or invalid domain payload. Return field errors, preserve safe input and submit a newly reviewed decision. |
| `InvalidArgumentException` | Invalid graph/identity/context/JSON/date/connection/message/size or unsupported action/assignment. Correct the input/configuration before retrying. |
| `ModelNotFoundException` | Run not in supplied scope, task not in that run, or required saved row missing. Return the host's scoped not-found response. |
| `WorkflowConflict` | Stale version, changed request input, finished work, name/version collision, engine drift, ambiguous legacy replay or conflicting import. Reload authorized current evidence and make an explicit new decision; an identical uncertain retry keeps its key and original version. |
| `LogicException` | Attempted model update/delete of immutable evidence or persisted definition fingerprint mismatch. Preserve evidence and investigate privileged writes. |
| Database/persistence exception | Constraint, connection, cancelled mandatory write or exhausted concurrency recovery. Inspect committed receipt before retry if an after-commit failure is possible. Do not treat every unique error as an accepted replay; unrelated callback/database errors propagate. |
| Run is active but Flow pointer says `END` | A sibling branch remains pending. Query `pendingSteps()` and use Workflow status. |
| Full imported messages appear truncated | Timeline intentionally previews 50 per receipt. Add an authorized message route with `historyMessages()`. |
| Timeline has no styles | Add package view path to Tailwind sources and rebuild host CSS; scan published overrides too. |
| Import fails on otherwise valid DST timestamp | App/PHP timezone mismatch or naive local fold cannot round-trip. Review storage contract and preserve source offset; use UTC through a reviewed migration. |

Map these exceptions to the host HTTP/console UX; the package defines no HTTP status policy. Typical applications use validation responses for bad fields, authorization/not-found responses for access boundaries and a reload/review response for conflicts. Keep identifiers and raw sensitive evidence out of public exception details. [Verification](verification.md) covers package checks, deployment-database concurrency tests and development dependency setup. [DocumentationExampleTest](../tests/Feature/DocumentationExampleTest.php) executes the linked procurement source rather than duplicating an unconnected pseudocode engine.
