<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_center_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('site_name');
            $table->string('type', 32);
            $table->string('frequency', 16);
            $table->unsignedSmallInteger('interval_value');
            $table->string('time_of_day', 5);
            $table->boolean('enabled')->default(true);
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->string('last_status', 24)->default('Scheduled');
            $table->timestamp('next_run_at');
            $table->unsignedBigInteger('version')->default(1);
            $table->string('idempotency_key', 120)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'owner_user_id', 'idempotency_key'], 'automation_center_idempotency_unique');
            $table->index(['tenant_id', 'owner_user_id', 'site_id'], 'automation_center_owner_site_idx');
            $table->index(['tenant_id', 'enabled', 'next_run_at'], 'automation_center_due_idx');
        });

        Schema::create('automation_center_job_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_center_job_id')->constrained('automation_center_jobs')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->unsignedBigInteger('version');
            $table->json('configuration');
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'automation_center_job_id', 'occurred_at'], 'automation_center_audit_job_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_center_job_audits');
        Schema::dropIfExists('automation_center_jobs');
    }
};
