<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name', 80);
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['name', 'version']);
        });
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 191);
            $table->string('scope_hash', 64)->index();
            $table->string('definition_id', 64);
            $table->foreign('definition_id')->references('id')->on('workflow_definitions')->restrictOnDelete();
            $table->unsignedBigInteger('flow_instance_id')->unique();
            $table->foreign('flow_instance_id')->references('id')->on('flow_instances')->restrictOnDelete();
            $table->string('start_key_hash', 64);
            $table->string('start_fingerprint', 64);
            $table->string('started_by', 191);
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['scope_hash', 'start_key_hash']);
        });
        Schema::create('workflow_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('workflow_runs')->restrictOnDelete();
            $table->string('key_hash', 64);
            $table->string('fingerprint', 64);
            $table->string('action', 100);
            $table->string('actor', 191);
            $table->unsignedBigInteger('step_id')->nullable();
            $table->unsignedInteger('result_version');
            $table->string('result_status', 20);
            $table->json('step_ids');
            $table->json('details');
            $table->timestamp('created_at');
            $table->unique(['run_id', 'key_hash']);
        });
        Schema::create('workflow_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('workflow_runs')->restrictOnDelete();
            $table->foreignId('command_id')->constrained('workflow_commands')->restrictOnDelete();
            $table->unsignedBigInteger('step_id')->nullable();
            $table->string('author', 191);
            $table->text('body');
            $table->timestamp('created_at');
        });
        Schema::create('workflow_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('workflow_runs')->restrictOnDelete();
            $table->string('scope_hash', 64);
            $table->string('source_hash', 64);
            $table->string('snapshot_hash', 64);
            $table->string('binding_fingerprint', 64);
            $table->json('snapshot');
            $table->json('task_mapping');
            $table->timestamp('created_at');
            $table->unique(['scope_hash', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_imports');
        Schema::dropIfExists('workflow_messages');
        Schema::dropIfExists('workflow_commands');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_definitions');
    }
};
