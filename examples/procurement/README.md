# Runnable procurement lifecycle

This example runs real Workflow and Flow models with pure Laravel validation, current workspace membership, assigned reviewer authorization, destination membership, immutable history, domain callbacks and a durable host outbox row. It needs no Fields, Filament, Tools, mail transport or browser.

Use a fresh disposable Laravel 12/13 application with PHP 8.3+, Workflow installed and package migrations applied. Copy `ProcurementExample.php` into `examples/procurement/` at your app root. In `php artisan tinker`:

```php
require base_path('examples/procurement/ProcurementExample.php');
use MrNewport\LaravelWorkflow\Examples\Procurement\ProcurementExample;
ProcurementExample::installSchema(); // Once, creates three new example-only tables.
$result = ProcurementExample::run();
$result['purchase']->only(['id', 'title', 'amount_cents', 'status']);
$result['run']->only(['id', 'version', 'status']);
$result['run']->history(25)->toArray();
$result['outbox_count']; // 1, despite the repeated approval request.
```

The result is an approved purchase for 225000 cents, completed run version 8, eight immutable receipts, four messages before the final archive note (five total), and one outbox row. The lifecycle is requester start → submit → assigned reviewer revision → requester resubmit → administrator reassign to backup reviewer → backup note → backup approval → exact approval replay → requester archive note. The replay preserves the original expected version 6 and key. It does not insert a second outbox row. Re-running `run()` creates another purchase and retains earlier evidence; do not call `installSchema()` twice.

For an actual application, move the models to your normal model namespace and the table definitions into host migrations. Bind the policies/validator in an application provider using `ProcurementExample::bind()` as the reference. Resolve actor keys from authenticated users, and scope from the authorized workspace; the sample's named actors are seeded demo identities. The example defines its own current membership table so its permission checks actually execute. Replace those membership queries with your real workspace relation and policies.

To expose a submit action through your host controller, load the run through `WorkflowRun::query()->inScope($scope)->findOrFail($id)`, resolve the intended pending task and call `act` with a client-retained request key and expected version. The server chooses the callback (`persistSubmission()`), never the request. Return validation errors or a reload/review response on conflicts. A replay of a committed command must still pass current access policy; policy must allow inspection of the original task even though it is finished.

`installSchema()` creates only `example_purchases`, `example_procurement_members`, `example_procurement_outbox`. It does not drop, reset or alter existing application/package tables. The sample's outbox row is durable evidence for a host worker. Implement external delivery, retry/lease state and recipient policy in that worker; running this example sends nothing.

The [documentation test](../../tests/Feature/DocumentationExampleTest.php) executes this exact file, checks current actor/destination rejection, callback rollback, conflict/replay behavior and complete paginated history. The [handbook](../../docs/HANDBOOK.md) separately explains parallel branches, all-branch cancellation, reviewed import and optional Fields/Tools integration. For an interactive composition, use the package playground's connected procurement lab; its engine calls the same public APIs.
