# Laravel Workflow

Scoped, auditable human workflows powered by **`mrnewport/laravel-flow`**. This package coordinates ownership checks, immutable definitions, form validation, command replay and history. Flow performs the actual transitions and assignments.

[**Interactive workshop →**](https://matthewnewport.com/packages/laravel-workflow/lab) — Try the connected procurement review with editable commands, validation and history.

**Compatibility:** PHP 8.3+, Laravel 12 or 13, and Laravel Flow 2.2. Stable-version installation requires the corresponding public release tags; see the handbook for the VCS fallback when registry indexing is pending.

Install the matching stable versions (see the handbook for the VCS fallback when registry indexing is pending):

```sh
composer require mrnewport/laravel-workflow
php artisan migrate
```

The service provider loads the workflow tables and an optional timeline view. The host application supplies routes, authorization and notification delivery.

## Definition

```php
use MrNewport\LaravelWorkflow\Definition;

$definition = Definition::fromArray([
    'name' => 'document-review',
    'version' => 1,
    'initial' => 'draft',
    'steps' => [
        ['id' => 'draft', 'transitions' => ['submit' => ['review'], 'cancel' => ['END']]],
        [
            'id' => 'review',
            'name' => 'Review document',
            'assignment_strategy' => 'multi_user',
            'assignment_params' => ['user_ids' => [12, 15]],
            'transitions' => ['approve' => ['END'], 'reject' => ['draft'], 'cancel' => ['END']],
        ],
    ],
]);
```

Names and actions are lowercase portable identifiers; versions are positive integers. Local step ids are case-sensitive and may contain letters, digits, dots, underscores and hyphens. `END` is terminal. Every step must be reachable and have a path to `END`. Unknown properties, undefined targets, duplicate targets, unsupported strategies and actions mixing `END` with another branch are rejected. Internal action names `start`, `comment`, `reassign`, `cancel-run` and `import` are reserved.

`DefinitionPublisher::publish($definition)` persists a normalized JSON snapshot and namespaced Flow rows. Repeating the same publication is safe. Changing a name/version pair is refused: publish a new version. Opaque engine ids fit Flow's 100-character column and preserve case-distinct local ids even on MySQL's default collation. Independent edits to these Flow rows are unsupported and detected before new commands run.

Definitions and history snapshots detach supplied PHP array references before calculating their immutable identity. Command payloads and reassignment inputs are captured before authorization hooks run. JSON input must be finite and fit within 512 nesting levels. Numeric-key objects whose keys would become a contiguous list are refused at input boundaries: use named object keys or an actual list. Object/list shape and numeric types are part of the fingerprint.

New definitions are limited to 10,000 total transitions by default and a normalized compact representation below 1 MiB, including its trailing newline. Publish `workflow-config` to adjust `max_definition_edges` within 1–625,000 for a measured workload. Existing immutable definitions are hydrated without applying these new admission budgets, so a smaller budget does not invalidate saved graphs. Lowering `flow.max_assignments` likewise preserves saved identity validation and unrelated operations such as comments; new definitions, replacement assignments and native assignment writes still obey the current limit.

Actions may fork into several steps. Each branch runs independently; there is no implicit join or quorum. Run status is derived from all pending steps, even if Flow's `current_step_id` already says `END` because one branch finished. Use `$run->pendingSteps()`, rather than that convenience pointer, for active work.

## Host authorization

Bind `Contracts\WorkflowAuthorizer` in an application service provider. The default refuses every operation.

```php
interface WorkflowAuthorizer
{
    public function authorizeStart(Definition $definition, Model $subject, string $scope, string $actor): void;
    public function authorize(WorkflowRun $run, ?FlowInstanceStep $step, string $action, string $actor): void;
}
```

The host resolves the authenticated identity and workspace, verifies that the subject belongs to that workspace, checks action/assignee policy and throws when access is refused. Scope and actor strings are attribution inputs, not authentication credentials; do not take them directly from a request. Authorization runs again on replay. Step lookup is constrained to the scoped run. Scope hashes preserve exact case on databases with case-insensitive text comparison.

Reassignment additionally requires `Contracts\AssignmentAuthorizer::authorizeAssignment($run, $step, $actor, $current, $proposed)`. It runs under the run lock and must verify every destination belongs to the permitted workspace/group. Its default also refuses access. `current` and `proposed` contain `['type' => 'user'|'email', 'value' => '...']` records. Flow strategy abstractions perform the assignment writes.

## Start and act

```php
use MrNewport\LaravelWorkflow\WorkflowManager;

$manager = app(WorkflowManager::class);
$run = $manager->start($definition, $document, $workspaceKey, $actorKey, $startRequestKey);

$step = $run->pendingSteps()->sole();
$run = $manager->act(
    $run, $step->id, 'submit', $workspaceKey, $actorKey, $actionRequestKey,
    expectedVersion: $run->version,
    payload: $input,
    withinTransaction: function ($lockedRun, $step, array $validated) use ($document) {
        // Lock/revalidate this domain record if other runs or application actions also change it.
        $current = $document->newQuery()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
        $current->update($validated);
    },
    message: 'Ready for review',
);
```

Run, Flow and subject records must use the same database connection. The start method locks/reloads its saved subject. Every subsequent mutation locks the scoped run, checks the expected version and locks its chosen step. Callbacks are database-only and retry-safe: deadlock recovery may run a callback again. The host must lock any domain records its callback changes, retain its invariants and use the same connection. Send mail, call APIs and write files through durable host outboxes or after-commit workers.

Choose request keys once per logical command and retain them when retrying. Each key is hashed before storage. Reusing a key with a different actor, action, step, expected version, input or message fails. Exact retries reauthorize and return the current run without repeating writes or callbacks. Start keys are unique per scope, including across different subjects. A run's start key is also used by its start receipt; use a different key for subsequent commands.

Version conflicts throw `Exceptions\WorkflowConflict`; reload before making a new decision. A retry with the same key/input can succeed after an uncertain response. The returned run reflects its latest state, while immutable `commands()` receipts record each command's result version/status, actor and created step ids. Payloads are hashed, not automatically persisted. Reassignment receipts retain approved before/after identities.

`comment($run, $scope, $actor, $key, $expectedVersion, $message, $stepId = null)` appends a plain-text note, including after completion. `cancel($run, $scope, $actor, $key, $expectedVersion, $message = null)` closes all pending branches only when each explicitly defines `cancel => ['END']` and policy authorizes each branch. `reassign($run, $stepId, $assignees, $scope, $actor, $key, $expectedVersion)` retains the published strategy and records before/after assignment evidence. All three use the same version/replay/transaction checks. A model observer that cancels a mandatory write causes the whole operation to fail and roll back; no receipt event is emitted for an unsaved command or requested message.

## Fields and Filament

Without a validator, nonempty payloads are refused. The optional Fields adapter accepts only trusted application schemas:

```php
use MrNewport\LaravelFields\{Field, Schema};
use MrNewport\LaravelWorkflow\Contracts\ActionValidator;
use MrNewport\LaravelWorkflow\Validation\FieldsActionValidator;

$schema = Schema::make([Field::text('title')->required()]);
$validator = (new FieldsActionValidator)->register('document-review', 1, 'draft', 'submit', $schema);
$this->app->instance(ActionValidator::class, $validator);
```

`Schema::validate()` supplies only allowed, normalized values to the transaction callback. The same schema renders Tailwind 4 forms or supplies Filament 5 components in a host page. Payloads must be finite JSON data; uploaded-file objects are refused before fingerprinting. The host stages authorized uploads and submits an approved storage reference.

After authorizing a run, the optional history component is:

```blade
@include('laravel-workflow::timeline', ['run' => $run])
```

Compile its static Tailwind 4 classes in your host stylesheet:

```css
@import "tailwindcss";
@source "../../vendor/mrnewport/laravel-workflow/resources/views";
```

The view renders escaped text, explicit UTC times and an accessible ordered history. It pages commands through `$run->history()` (50 entries by default, maximum 100), loads at most 50 message previews per command, and renders pagination links. It never loads the run's complete `commands` or `messages` collection.

Use `$run->historyMessages($commandId, perPage: 50)` for the complete, separately paginated messages of an imported command; omit `$commandId` for all run messages. The optional `laravel-workflow::messages` view renders that paginator. A host that provides a message-history route can pass `messageHistoryUrl: fn ($command) => route('workflow.messages', [$run, $command])` to the timeline to show a “View all messages” link beside truncated previews. Authorize the run before querying either projection. The package creates no public routes.

The host chooses its navigation and local display timezone. Publish `workflow-translations` to override built-in view and validation text, or provide Laravel package translation overrides under `lang/vendor/laravel-workflow`. Developer configuration and invariant exceptions remain explicit diagnostic messages.

## Events, notifications and recovery

`Events\WorkflowChanged` is dispatched after the outermost transaction commits, with run id, command id, action, actor and version. Native Flow transition events remain available. Rollbacks and history imports emit neither event.

An after-commit event is not a durable delivery guarantee. Use command receipts as the durable checkpoint for a host notification outbox, with a unique command id and explicit attempt/retry records. Notification recipient policy, templates, channels and external delivery belong to the application. Email addresses, form contents and exception payloads are never written to package logs.

See [history import](docs/migration.md) for saved workflow data and [testing](docs/verification.md) for development checks.

## Saved fingerprints and replay

Run the forward migration before using this version. It adds `workflow_commands.fingerprint_version`: existing receipts are version 1 and new receipts are version 2. Ordinary saved definition/import hashes and safe scalar/object payload retries keep their existing identity; hashes are never rewritten automatically.

A version-1 action receipt with a multi-item list anywhere in its payload requires an explicit review before replay. That receipt retained only a hash, so it cannot prove whether the original input was a list or a differently ordered numeric-key object. The package fails closed rather than guessing or repeating a domain callback. Recover through the host's reviewed operation/recovery process after checking the already-committed result. New version-2 list receipts replay normally.

Existing import evidence is retained. A saved import containing an ambiguous numeric-key JSON object requires evidence review before reimport; database object-to-list reinterpretation is refused. Its raw evidence and original fingerprints remain available for that review.

## Full documentation

Read the [complete handbook](docs/HANDBOOK.md), [feature coverage index](docs/FEATURES.md) and executable examples for installation, every public API/command, host policies, data compatibility, end-to-end review and recovery.
