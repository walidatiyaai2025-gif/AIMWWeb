<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('platform_admin')->default(false)->after('password');
        });
        Schema::create('billing_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->unique();
            $table->longText('encrypted_credentials');
            $table->timestamps();
        });
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->json('localized_name')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('billing_interval', 24)->default('month');
            $table->unsignedInteger('trial_period_days')->default(0);
            $table->unsignedInteger('grace_period_days')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamp('retired_at')->nullable();
            $table->integer('display_order')->default(0);
            $table->string('provider', 32)->nullable();
            $table->string('provider_product_id')->nullable();
            $table->string('provider_plan_id')->nullable();
            $table->json('limits');
            $table->json('entitlements');
            $table->timestamps();
            $table->index(['enabled', 'retired_at', 'display_order']);
        });
        Schema::create('billing_plan_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['billing_plan_id', 'occurred_at']);
        });
        Schema::create('tenant_billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('trial_used_at')->nullable();
            $table->string('provider_customer_hash', 64)->nullable()->index();
            $table->longText('encrypted_provider_customer_id')->nullable();
            $table->timestamps();
        });
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->foreignId('pending_billing_plan_id')->nullable()->constrained('billing_plans')->nullOnDelete();
            $table->string('state', 24);
            $table->string('provider', 32)->nullable();
            $table->string('provider_subscription_hash', 64)->nullable()->unique();
            $table->longText('encrypted_provider_subscription_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_expires_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('plan_change_effective_at')->nullable();
            $table->timestamp('last_provider_event_at')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();
            $table->index(['state', 'trial_expires_at']);
            $table->index(['state', 'grace_ends_at']);
        });
        Schema::create('tenant_usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('metric');
            $table->string('period_key', 32);
            $table->unsignedBigInteger('amount_used')->default(0);
            $table->unsignedBigInteger('limit_snapshot')->nullable();
            $table->timestamp('period_started_at');
            $table->timestamp('period_ends_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'metric', 'period_key']);
            $table->index(['tenant_id', 'period_ends_at']);
        });
        Schema::create('billing_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('metadata');
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'occurred_at']);
        });
        Schema::create('billing_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_hash', 64)->unique();
            $table->string('event_type');
            $table->string('payload_hash', 64);
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained('tenant_subscriptions')->nullOnDelete();
            $table->timestamp('verified_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 32)->default('received');
            $table->string('failure_class')->nullable();
            $table->timestamps();
            $table->index(['provider', 'event_type', 'created_at']);
        });
        Schema::create('billing_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->constrained('tenant_subscriptions')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_transaction_hash', 64)->nullable()->index();
            $table->longText('encrypted_provider_transaction_id')->nullable();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider', 'provider_transaction_hash'], 'billing_tx_provider_ref_unique');
        });
        Schema::create('billing_subscription_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->constrained('tenant_subscriptions')->cascadeOnDelete();
            $table->foreignId('from_billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->foreignId('to_billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('status', 32);
            $table->timestamp('effective_at')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->timestamp('provider_requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'effective_at']);
        });
        $plans = [
            ['code' => 'free-trial', 'name' => 'Free Trial', 'trial' => 14, 'grace' => 0, 'limits' => ['sites.max' => 1, 'ai.requests.month' => 50, 'ai.tokens.month' => 50000, 'automation.rules.max' => 0, 'members.max' => 1], 'entitlements' => ['automation.enabled' => false, 'seo.audit.enabled' => true, 'backup.enabled' => false, 'backup.restore' => false, 'reports.export' => false, 'connector.advanced' => false]],
            ['code' => 'starter', 'name' => 'Starter', 'trial' => 0, 'grace' => 3, 'limits' => ['sites.max' => 3, 'ai.requests.month' => 500, 'ai.tokens.month' => 500000, 'automation.rules.max' => 10, 'members.max' => 3], 'entitlements' => ['automation.enabled' => true, 'seo.audit.enabled' => true, 'backup.enabled' => true, 'backup.restore' => false, 'reports.export' => true, 'connector.advanced' => false]],
            ['code' => 'pro', 'name' => 'Pro', 'trial' => 0, 'grace' => 5, 'limits' => ['sites.max' => 10, 'ai.requests.month' => 3000, 'ai.tokens.month' => 3000000, 'automation.rules.max' => 100, 'members.max' => 10], 'entitlements' => ['automation.enabled' => true, 'seo.audit.enabled' => true, 'backup.enabled' => true, 'backup.restore' => true, 'reports.export' => true, 'connector.advanced' => true]],
            ['code' => 'business', 'name' => 'Business', 'trial' => 0, 'grace' => 7, 'limits' => ['sites.max' => 50, 'ai.requests.month' => 15000, 'ai.tokens.month' => 15000000, 'automation.rules.max' => 1000, 'members.max' => 50], 'entitlements' => ['automation.enabled' => true, 'seo.audit.enabled' => true, 'backup.enabled' => true, 'backup.restore' => true, 'reports.export' => true, 'connector.advanced' => true]],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'trial' => 0, 'grace' => 14, 'limits' => ['sites.max' => null, 'ai.requests.month' => null, 'ai.tokens.month' => null, 'automation.rules.max' => null, 'members.max' => null], 'entitlements' => ['automation.enabled' => true, 'seo.audit.enabled' => true, 'backup.enabled' => true, 'backup.restore' => true, 'reports.export' => true, 'connector.advanced' => true]],
        ];
        foreach ($plans as $i => $plan) {
            DB::table('billing_plans')->insert(['code' => $plan['code'], 'name' => $plan['name'], 'localized_name' => json_encode(['en' => $plan['name']]), 'description' => null, 'price_minor' => $plan['code'] === 'free-trial' ? 0 : null, 'currency' => 'USD', 'billing_interval' => 'month', 'trial_period_days' => $plan['trial'], 'grace_period_days' => $plan['grace'], 'enabled' => true, 'display_order' => $i, 'provider' => $plan['code'] === 'free-trial' ? null : 'paypal', 'provider_product_id' => null, 'provider_plan_id' => null, 'limits' => json_encode($plan['limits']), 'entitlements' => json_encode($plan['entitlements']), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_changes');
        Schema::dropIfExists('billing_transactions');
        Schema::dropIfExists('billing_provider_events');
        Schema::dropIfExists('billing_audits');
        Schema::dropIfExists('tenant_usage_counters');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('tenant_billing_profiles');
        Schema::dropIfExists('billing_plan_audits');
        Schema::dropIfExists('billing_plans');
        Schema::dropIfExists('billing_provider_credentials');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('platform_admin'));
    }
};
