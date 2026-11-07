<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Contracts;

use Illuminate\Database\Eloquent\Model;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\Migration\HistorySnapshot;

interface ImportAuthorizer
{
    /** Check reviewed mapping, source provenance and ownership. Separate from ordinary starts. */
    public function authorizeImport(Definition $definition, Model $subject, HistorySnapshot $snapshot, string $scope, string $actor): void;
}
