<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->string('status')->default('active');
            $table->string('connection_status')->default('unpaired');
            $table->string('health_state')->default('unknown');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'url']);
        });

        Schema::create('connector_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'site_id']);
        });

        Schema::create('connectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->uuid('identity')->unique();
            $table->longText('encrypted_secret');
            $table->string('protocol_version')->default('1');
            $table->json('capabilities');
            $table->json('enabled_scopes');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id']);
        });

        Schema::create('connector_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->constrained()->cascadeOnDelete();
            $table->string('nonce', 64);
            $table->timestamp('expires_at');
            $table->unique(['connector_id', 'nonce']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            $table->unsignedInteger('processed')->default(0);
            $table->text('failure')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'site_id', 'status']);
        });

        Schema::create('synced_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type');
            $table->unsignedBigInteger('remote_id');
            $table->string('slug');
            $table->text('title')->nullable();
            $table->longText('content')->nullable();
            $table->text('excerpt')->nullable();
            $table->json('headings')->nullable();
            $table->json('taxonomy')->nullable();
            $table->json('media')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->timestamp('remote_modified_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'resource_type', 'remote_id'], 'content_remote_unique');
        });

        Schema::create('seo_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('queued');
            $table->text('failure')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('synced_content_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('severity');
            $table->text('recommendation');
            $table->string('status')->default('open');
            $table->timestamps();
            $table->unique(['seo_audit_id', 'synced_content_id', 'code'], 'audit_finding_unique');
        });

        Schema::create('ai_provider_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('endpoint');
            $table->string('model');
            $table->longText('encrypted_api_key');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'provider']);
        });

        Schema::create('suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('synced_content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('queued');
            $table->json('before_state');
            $table->json('proposed_state')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('suggestion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('PENDING');
            $table->json('before_state');
            $table->json('proposed_state');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'suggestion_id']);
        });

        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->uuid('request_id')->unique();
            $table->uuid('correlation_id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'approval_id'], 'execution_approval_unique');
        });

        Schema::create('evidence_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id');
            $table->uuid('request_id');
            $table->uuid('correlation_id');
            $table->json('before_state');
            $table->json('proposed_state');
            $table->json('actual_after_state')->nullable();
            $table->boolean('verified')->default(false);
            $table->text('failure')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'execution_id']);
        });
    }

    public function down(): void
    {
        foreach (['evidence_receipts', 'executions', 'approvals', 'suggestions', 'ai_provider_configs', 'seo_findings', 'seo_audits', 'synced_contents', 'sync_runs', 'connector_nonces', 'connectors', 'connector_pairings', 'sites'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
