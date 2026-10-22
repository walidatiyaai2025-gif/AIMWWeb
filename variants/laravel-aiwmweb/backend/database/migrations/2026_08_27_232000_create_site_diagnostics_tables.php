<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('classification');
            $table->string('connection_state');
            $table->string('rest_state')->nullable();
            $table->string('database_state')->nullable();
            $table->string('cron_state')->nullable();
            $table->string('cache_state')->nullable();
            $table->json('capability_summary')->nullable();
            $table->json('health')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['tenant_id', 'site_id', 'checked_at'], 'site_diag_history_idx');
        });

        Schema::create('site_operation_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->uuid('correlation_id')->nullable();
            $table->string('operation');
            $table->string('status');
            $table->text('message');
            $table->json('details')->nullable();
            $table->unsignedInteger('affected_records')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->index(['tenant_id', 'site_id', 'started_at'], 'site_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_operation_histories');
        Schema::dropIfExists('site_diagnostics');
    }
};
