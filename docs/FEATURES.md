# Feature coverage index

The [handbook coverage table](HANDBOOK.md#contents-and-feature-coverage) maps every public model, contract, manager/publisher/definition operation, history API, import API and UI feature to its full contract, limits, errors and examples.

- [Fresh installation and configuration](HANDBOOK.md#install) includes the VCS registry fallback, migrations, optional dependencies, connection and subject key requirements.
- [Authorization](HANDBOOK.md#authorization) covers all contract signatures, default deny, actors, exact scopes, replay access and destination membership.
- [Definition grammar](HANDBOOK.md#definition-grammar) and [publication](HANDBOOK.md#publishing) cover every field, budget, fingerprint rule and engine ID method.
- [Command contract](HANDBOOK.md#command-contract) and [all manager methods](HANDBOOK.md#commands) cover arguments, return values, receipts, atomicity, callbacks, replay and errors.
- [Payload validation](HANDBOOK.md#payload-validation) supplies pure Laravel and optional Fields adapters; [runnable procurement](../examples/procurement/README.md) exercises real policies, domain updates and an outbox.
- [Branches](HANDBOOK.md#branches-and-completion), [queries](HANDBOOK.md#queries-and-history), [presentation](HANDBOOK.md#presentation) and [delivery](HANDBOOK.md#transactions-and-delivery) cover parallelism, cancellation, full message pagination, localization, Tailwind, accessibility and event durability.
- [History schema](migration.md#snapshot-format), [mapper callbacks](migration.md#mapper-callback-reference), [import execution](HANDBOOK.md#import-execution) and [upgrade/recovery](HANDBOOK.md#upgrade-and-recovery) cover saved evidence, all guards, wide dates, DST, v1 compatibility and reconciliation.
- [Troubleshooting](HANDBOOK.md#errors-and-troubleshooting) maps each error family to recovery; [verification](verification.md) documents package and deployment-database checks.

[DocumentationExampleTest](../tests/Feature/DocumentationExampleTest.php) executes the shipped procurement source. The existing Definition, Manager, HistoryImporter, query and MySQL regression tests cover additional graph/branch/import/concurrency/adversarial contracts.
