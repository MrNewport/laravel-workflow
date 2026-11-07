<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow;

use Illuminate\Support\ServiceProvider;
use MrNewport\LaravelWorkflow\Authorization\DenyAllAssignments;
use MrNewport\LaravelWorkflow\Authorization\DenyAllAuthorizer;
use MrNewport\LaravelWorkflow\Authorization\DenyAllImports;
use MrNewport\LaravelWorkflow\Contracts\ActionValidator;
use MrNewport\LaravelWorkflow\Contracts\AssignmentAuthorizer;
use MrNewport\LaravelWorkflow\Contracts\ImportAuthorizer;
use MrNewport\LaravelWorkflow\Contracts\WorkflowAuthorizer;
use MrNewport\LaravelWorkflow\Validation\EmptyPayloadValidator;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workflow.php', 'workflow');
        $this->app->bindIf(WorkflowAuthorizer::class, DenyAllAuthorizer::class);
        $this->app->bindIf(ActionValidator::class, EmptyPayloadValidator::class);
        $this->app->bindIf(ImportAuthorizer::class, DenyAllImports::class);
        $this->app->bindIf(AssignmentAuthorizer::class, DenyAllAssignments::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'laravel-workflow');
        $this->publishes([__DIR__.'/../lang' => lang_path('vendor/laravel-workflow')], 'workflow-translations');
        $this->publishes([__DIR__.'/../config/workflow.php' => config_path('workflow.php')], 'workflow-config');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-workflow');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/laravel-workflow')], 'workflow-views');
    }
}
