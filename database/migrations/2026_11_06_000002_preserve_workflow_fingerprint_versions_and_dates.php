<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_commands', function (Blueprint $table) {
            $table->unsignedTinyInteger('fingerprint_version')->default(1);
        });
        Schema::table('workflow_commands', fn (Blueprint $table) => $table->index(['run_id', 'id'], 'workflow_commands_history'));
        Schema::table('workflow_messages', function (Blueprint $table) {
            $table->index(['run_id', 'id'], 'workflow_messages_history');
            $table->index(['command_id', 'id'], 'workflow_messages_command_history');
        });
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        foreach ($this->dates() as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $table) {
                foreach ($columns as $column) {
                    $blueprint->dateTime($column)->nullable(in_array($table, ['workflow_definitions', 'workflow_runs'], true))->change();
                }
            });
        }
    }

    public function down(): void
    {
        // MySQL may discard its implicit FK index when a covering index is added.
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (['run_id', 'command_id'] as $column) {
                if (! Schema::hasIndex('workflow_messages', [$column])) {
                    Schema::table('workflow_messages', fn (Blueprint $table) => $table->index($column, 'workflow_messages_'.$column.'_foreign'));
                }
            }
        }
        // Keep widened dates: narrowing can destroy valid history outside TIMESTAMP's range.
        Schema::table('workflow_messages', function (Blueprint $table) {
            $table->dropIndex('workflow_messages_history');
            $table->dropIndex('workflow_messages_command_history');
        });
        Schema::table('workflow_commands', function (Blueprint $table) {
            $table->dropIndex('workflow_commands_history');
            $table->dropColumn('fingerprint_version');
        });
    }

    private function dates(): array
    {
        return [
            'workflow_definitions' => ['created_at', 'updated_at'],
            'workflow_runs' => ['finished_at', 'created_at', 'updated_at'],
            'workflow_commands' => ['created_at'],
            'workflow_messages' => ['created_at'],
            'workflow_imports' => ['created_at'],
        ];
    }
};
