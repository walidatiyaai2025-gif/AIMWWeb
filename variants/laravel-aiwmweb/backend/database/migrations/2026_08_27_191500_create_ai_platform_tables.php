<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider_key', 80);
            $table->string('adapter_key', 80);
            $table->string('display_name', 160);
            $table->string('endpoint')->nullable();
            $table->string('default_model')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('priority')->default(10);
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->unsignedTinyInteger('max_attempts')->default(2);
            $table->boolean('automatic_failover')->default(true);
            $table->json('limits')->nullable();
            $table->json('settings')->nullable();
            $table->string('readiness_state', 40)->default('NOT_CONFIGURED');
            $table->timestamp('readiness_checked_at')->nullable();
            $table->string('readiness_error', 1000)->nullable();
            $table->timestamp('last_rate_limited_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider_key']);
            $table->index(['tenant_id', 'enabled', 'priority']);
        });

        Schema::create('ai_model_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_provider_profile_id')->constrained()->cascadeOnDelete();
            $table->string('model_key', 160);
            $table->string('display_name', 200)->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('capabilities');
            $table->unsignedBigInteger('context_window')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->decimal('input_cost_per_million', 14, 6)->nullable();
            $table->decimal('output_cost_per_million', 14, 6)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'ai_provider_profile_id', 'model_key'], 'ai_models_tenant_provider_model_unique');
            $table->index(['tenant_id', 'enabled']);
        });

        Schema::create('ai_prompt_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stable_key', 80);
            $table->string('domain', 80);
            $table->string('title', 200);
            $table->text('system_template')->nullable();
            $table->longText('user_template');
            $table->json('variables');
            $table->json('output_schema')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('is_builtin')->default(false);
            $table->boolean('allow_tenant_override')->default(true);
            $table->unsignedInteger('current_version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'stable_key']);
            $table->index(['tenant_id', 'domain', 'enabled']);
        });

        Schema::create('ai_prompt_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_prompt_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('change_type', 80);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['ai_prompt_template_id', 'version']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('ai_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_provider_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_key', 80);
            $table->string('model_key', 160);
            $table->string('workflow', 160);
            $table->unsignedBigInteger('input_units')->default(0);
            $table->unsignedBigInteger('output_units')->default(0);
            $table->decimal('estimated_cost', 14, 6)->default(0);
            $table->decimal('actual_cost', 14, 6)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('status', 40);
            $table->string('failure_kind', 80)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->uuid('correlation_id');
            $table->string('provider_request_id', 200)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'provider_key']);
            $table->index(['tenant_id', 'workflow']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'correlation_id']);
        });

        Schema::create('ai_generation_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_prompt_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('provider_key', 80)->nullable();
            $table->string('model_key', 160)->nullable();
            $table->string('workflow', 160);
            $table->char('request_hash', 64);
            $table->uuid('correlation_id');
            $table->string('status', 40);
            $table->string('failure_kind', 80)->nullable();
            $table->json('structured_output')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'correlation_id']);
        });

        Schema::create('ai_planner_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('title', 300);
            $table->longText('idea')->nullable();
            $table->json('keywords');
            $table->json('topics');
            $table->json('brief')->nullable();
            $table->json('outline')->nullable();
            $table->longText('draft_content')->nullable();
            $table->string('status', 40)->default('idea');
            $table->timestamp('scheduled_at')->nullable();
            $table->string('approval_reference', 200)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
            $table->index(['tenant_id', 'site_id']);
        });

        Schema::create('ai_planner_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_planner_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 80);
            $table->json('snapshot');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['ai_planner_item_id', 'version', 'action'], 'ai_planner_history_item_version_action_unique');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_planner_histories');
        Schema::dropIfExists('ai_planner_items');
        Schema::dropIfExists('ai_generation_records');
        Schema::dropIfExists('ai_usage_records');
        Schema::dropIfExists('ai_prompt_revisions');
        Schema::dropIfExists('ai_prompt_templates');
        Schema::dropIfExists('ai_model_profiles');
        Schema::dropIfExists('ai_provider_profiles');
    }
};
