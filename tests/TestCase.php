<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Providers\FlowServiceProvider;
use MrNewport\LaravelWorkflow\Contracts\WorkflowAuthorizer;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\Models\WorkflowRun;
use MrNewport\LaravelWorkflow\WorkflowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FlowServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        if (getenv('WORKFLOW_ALL_MYSQL_TEST_DATABASE') === 'workflow_all_test') {
            $app['config']->set('database.connections.testing', [
                'driver' => 'mysql', 'database' => 'workflow_all_test',
                'host' => getenv('MYSQL_TEST_HOST') ?: '127.0.0.1', 'port' => getenv('MYSQL_TEST_PORT') ?: 3306,
                'unix_socket' => getenv('MYSQL_TEST_SOCKET') ?: '',
                'username' => getenv('MYSQL_TEST_USERNAME') ?: 'root', 'password' => getenv('MYSQL_TEST_PASSWORD') ?: '',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
            ]);
        }

    }

    protected function defineDatabaseMigrations(): void
    {
        if (DB::connection()->getDriverName() === 'mysql' && getenv('WORKFLOW_ALL_MYSQL_TEST_DATABASE') === 'workflow_all_test' && config('database.default') === 'testing') {
            Schema::dropAllTables();
            DB::statement("SET SESSION time_zone = '+00:00'");
        }

        $this->loadMigrationsFrom(dirname((new \ReflectionClass(FlowServiceProvider::class))->getFileName(), 2).'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('draft');
        });
    }

    protected function allow(): AllowAuthorizer
    {
        $policy = new AllowAuthorizer;
        $this->app->instance(WorkflowAuthorizer::class, $policy);

        return $policy;
    }

    protected function subject(): Subject
    {
        return Subject::query()->create(['status' => 'draft']);
    }

    protected function definition(array $overrides = []): Definition
    {
        return Definition::fromArray(array_replace(['name' => 'review', 'version' => 1, 'initial' => 'draft', 'steps' => [
            ['id' => 'draft', 'transitions' => ['submit' => ['review'], 'cancel' => ['END']]],
            ['id' => 'review', 'assignment_strategy' => 'multi_user', 'assignment_params' => ['user_ids' => [1, 2]], 'transitions' => ['approve' => ['END'], 'reject' => ['draft'], 'cancel' => ['END']]],
        ]], $overrides));
    }
}

class Subject extends Model
{
    protected $table = 'subjects';

    public $timestamps = false;

    protected $guarded = [];
}

class AllowAuthorizer implements WorkflowAuthorizer
{
    public array $calls = [];

    public bool $allowed = true;

    public function authorizeStart(Definition $definition, Model $subject, string $scope, string $actor): void
    {
        $this->calls[] = ['start', $scope, $actor];
        if (! $this->allowed) {
            throw new AuthorizationException;
        }
    }

    public function authorize(WorkflowRun $run, ?FlowInstanceStep $step, string $action, string $actor): void
    {
        $this->calls[] = [$action, $run->getKey(), $actor];
        if (! $this->allowed) {
            throw new AuthorizationException;
        }
    }
}
