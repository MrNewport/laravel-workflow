# Testing

The package supports PHP 8.3+, Laravel 12/13 and Laravel Flow 2.2. Install the compatible dependencies (use public VCS release tags if registry indexing is pending), then run:

```sh
composer install
composer check
```

The Testbench suite covers Flow transitions and assignments, scoped host/import policy, replay authorization, request fingerprints, immutable definitions, version conflicts, callback rollback, after-commit events, parallel branches, rejection/cancellation, destination-authorized reassignment, database connection boundaries, finite JSON payloads, reviewed history import and escaped Tailwind history.

The CI matrix runs PHP 8.3, 8.4 and 8.5 against Laravel 12 and 13, with highest and lowest compatible dependency resolution and strict Composer validation. A separate job runs formatting and Larastan level 5; the MySQL job exercises the full package contracts on both Laravel majors. No static-analysis baseline is used.

Standalone development dependency resolution requires Laravel Flow 2.2 and Laravel Fields 1.0 through the registry or configured VCS repositories. Fields is a development dependency and optional runtime adapter, not a mandatory production dependency. The release sequence for standalone checks is Fields, Flow, Workflow, then Workflow Tools.

Use disposable databases. SQLite covers service behavior and rollback tests; database row-lock guarantees require separate tests on the deployment database. For MySQL, use independent processes to test duplicate starts, duplicate actions, opposing actions at the same expected version and competing subjects using the same start key. Verify that only the accepted command commits its callback and receipt.

Also test independent runs using the same definition: their transaction callbacks must be able to proceed concurrently. Open a repeatable-read transaction before another connection commits a command, then verify that replay returns the committed result without repeating the callback.

The definition benchmark measures a 250-step graph with 6,500 edges across five samples:

```sh
php benchmarks/definitions.php
```

An optional first argument selects a Composer bootstrap file. Query regression tests check that complete engine verification uses two batched reads for both small and large graphs. Performance measurements depend on the PHP runtime, database and host workload.

## Database regression checks

The dedicated MySQL regression fixture accepts only an explicitly selected disposable `workflow_test` or `workflow_fix_test` database and recreates its package tables. For example:

```sh
WORKFLOW_MYSQL_TEST_DATABASE=workflow_test MYSQL_TEST_PASSWORD=package-test-only \
    vendor/bin/phpunit --filter=MySqlWorkflowRegressionTest
```

`MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`, `MYSQL_TEST_SOCKET` and `MYSQL_TEST_USERNAME` select the connection. The regression suite covers saved receipt-version upgrades and migration rollback, exact fingerprint shape, safe legacy replay, subject validation, cancelled-write rollback, non-UTC/DST timestamp handling, dates outside the TIMESTAMP range, newly published graph checks, bounded history queries and translated output. It uses real package tables rather than a replacement workflow engine.

For the full service/feature suite on MySQL, create a separate disposable `workflow_all_test` database and set `WORKFLOW_ALL_MYSQL_TEST_DATABASE=workflow_all_test` before `vendor/bin/phpunit`. The shared Testbench fixture then uses real MySQL tables for every feature test, including ownership, callbacks, replay and history. This is the CI MySQL mode; the dedicated regression class skips unless its separate fixture is selected.
