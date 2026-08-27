<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['tenant_id', 'email', 'status']);
        });

        Schema::create('scoped_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('site_key')->nullable();
            $table->string('scope');
            $table->string('key');
            $table->json('value')->nullable();
            $table->longText('encrypted_value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'scope', 'key']);
            $table->index(['tenant_id', 'site_key', 'key']);
            $table->index(['tenant_id', 'user_id', 'key']);
        });

        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('task_type');
            $table->string('schedule');
            $table->string('timezone')->default('UTC');
            $table->boolean('enabled')->default(true);
            $table->json('payload')->nullable();
            $table->json('retry_policy')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status')->nullable();
            $table->json('last_result')->nullable();
            $table->text('last_failure')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'enabled', 'next_run_at']);
        });

        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('trigger');
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('approval_required')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->index(['tenant_id', 'trigger', 'status']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->uuid('correlation_id');
            $table->string('status');
            $table->json('trigger_payload')->nullable();
            $table->json('result')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'correlation_id']);
        });

        Schema::create('operation_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->uuid('correlation_id');
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(1);
            $table->boolean('safe_to_cancel')->default(true);
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'correlation_id']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'type', 'created_at']);
        });

        Schema::create('operation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operation_execution_id')->nullable()->constrained('operation_executions')->nullOnDelete();
            $table->uuid('correlation_id');
            $table->string('level')->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'correlation_id', 'occurred_at']);
            $table->index(['tenant_id', 'level', 'occurred_at']);
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('site_key')->nullable();
            $table->string('level');
            $table->json('manifest')->nullable();
            $table->string('status')->default('requested');
            $table->string('risk_level')->default('low');
            $table->boolean('approval_required')->default(false);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('operation_execution_id')->nullable()->constrained('operation_executions')->nullOnDelete();
            $table->json('verification')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'site_key', 'created_at']);
        });

        Schema::create('restore_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_id')->constrained('backups')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('requested');
            $table->string('risk_level')->default('high');
            $table->foreignId('operation_execution_id')->nullable()->constrained('operation_executions')->nullOnDelete();
            $table->json('verification')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('operation_execution_id')->nullable()->constrained('operation_executions')->nullOnDelete();
            $table->string('report_type');
            $table->json('filters')->nullable();
            $table->string('format')->default('csv');
            $table->string('status')->default('queued');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('row_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('restore_requests');
        Schema::dropIfExists('backups');
        Schema::dropIfExists('operation_logs');
        Schema::dropIfExists('operation_executions');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('scheduled_tasks');
        Schema::dropIfExists('scoped_settings');
        Schema::dropIfExists('tenant_invitations');
    }
};
