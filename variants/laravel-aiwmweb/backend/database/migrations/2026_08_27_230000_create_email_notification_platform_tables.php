<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('configuration_key', 160);
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('transport', 40)->default('smtp');
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('encryption', 20)->nullable();
            $table->string('username')->nullable();
            $table->string('from_address');
            $table->string('from_name');
            $table->string('reply_to')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('timeout_seconds')->default(20);
            $table->unsignedTinyInteger('max_attempts')->default(4);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'configuration_key']);
            $table->index(['tenant_id', 'site_id', 'enabled']);
        });

        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stable_id', 120);
            $table->string('locale', 8);
            $table->unsignedInteger('version');
            $table->string('subject_template', 500);
            $table->longText('html_template');
            $table->text('text_template')->nullable();
            $table->json('variables');
            $table->boolean('active')->default(true);
            $table->boolean('builtin')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'stable_id', 'locale', 'version'], 'email_template_version_unique');
            $table->index(['tenant_id', 'stable_id', 'locale', 'active']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('scope_key', 80);
            $table->string('category', 80);
            $table->string('channel', 40)->default('email');
            $table->string('mode', 20)->default('immediate');
            $table->string('locale', 8)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'scope_key', 'category', 'channel'], 'notification_pref_scope_unique');
        });

        Schema::create('notification_event_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('event_type', 120);
            $table->string('source', 80);
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'event_id']);
        });

        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('notification_id');
            $table->uuid('event_id');
            $table->string('category', 80);
            $table->string('severity', 20);
            $table->string('source', 80);
            $table->string('title', 300);
            $table->text('message');
            $table->string('deep_link', 1000)->nullable();
            $table->boolean('mandatory')->default(false);
            $table->string('locale', 8)->default('en');
            $table->string('delivery_mode', 20)->default('immediate');
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'notification_id']);
            $table->index(['tenant_id', 'user_id', 'read_at', 'created_at']);
            $table->index(['tenant_id', 'event_id']);
        });

        Schema::create('email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('in_app_notification_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mail_configuration_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('event_id');
            $table->uuid('delivery_id');
            $table->string('idempotency_key', 191);
            $table->text('recipient');
            $table->string('recipient_hash', 64);
            $table->string('template_stable_id', 120);
            $table->string('locale', 8)->default('en');
            $table->string('status', 20)->default('QUEUED');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(4);
            $table->string('provider_message_id', 300)->nullable();
            $table->string('failure_category', 80)->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->json('variables');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sending_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'delivery_id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'recipient_hash']);
        });

        Schema::create('email_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('name', 160);
            $table->string('template_stable_id', 120);
            $table->text('recipient');
            $table->string('locale', 8)->default('en');
            $table->json('variables');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('interval_minutes')->default(1440);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'enabled', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_schedules');
        Schema::dropIfExists('email_deliveries');
        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('notification_event_receipts');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('mail_configurations');
    }
};
