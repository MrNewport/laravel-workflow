<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Authorization;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use MrNewport\LaravelWorkflow\Contracts\ImportAuthorizer;
use MrNewport\LaravelWorkflow\Definition;
use MrNewport\LaravelWorkflow\Migration\HistorySnapshot;

final class DenyAllImports implements ImportAuthorizer
{
    public function authorizeImport(Definition $definition, Model $subject, HistorySnapshot $snapshot, string $scope, string $actor): void
    {
        throw new AuthorizationException('Bind an ImportAuthorizer after reviewing source identity mappings.');
    }
}
