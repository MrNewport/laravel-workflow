<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Run only against an explicitly selected disposable workflow test database. */
final class MySqlWorkflowRegressionTest extends WorkflowRegressionTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $database = getenv('WORKFLOW_MYSQL_TEST_DATABASE');
        if (! is_string($database) || ! preg_match('/\Aworkflow_(?:fix_)?test\z/', $database)) {
            $this->markTestSkipped('Set WORKFLOW_MYSQL_TEST_DATABASE to workflow_test or workflow_fix_test for disposable MySQL verification.');
        }
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql', 'database' => $database,
            'host' => getenv('MYSQL_TEST_HOST') ?: '127.0.0.1', 'port' => getenv('MYSQL_TEST_PORT') ?: 3306,
            'unix_socket' => getenv('MYSQL_TEST_SOCKET') ?: '',
            'username' => getenv('MYSQL_TEST_USERNAME') ?: 'root', 'password' => getenv('MYSQL_TEST_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::dropAllTables();
        DB::statement("SET SESSION time_zone = '+00:00'");
        parent::defineDatabaseMigrations();
    }
}
