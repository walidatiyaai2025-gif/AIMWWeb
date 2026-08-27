<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->string('mode', 32);
            $table->string('trigger', 32)->default('manual');
            $table->string('state', 32)->default('queued');
            $table->json('resources');
            $table->json('metadata')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resume_of_sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->unsignedBigInteger('discovered_count')->default(0);
            $table->unsignedBigInteger('created_count')->default(0);
            $table->unsignedBigInteger('updated_count')->default(0);
            $table->unsignedBigInteger('unchanged_count')->default(0);
            $table->unsignedBigInteger('conflicted_count')->default(0);
            $table->unsignedBigInteger('deleted_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'site_id', 'state'], 'sync_runs_site_state_idx');
            $table->index(['tenant_id', 'site_id', 'created_at'], 'sync_runs_site_created_idx');
        });

        Schema::create('sync_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->string('resource', 64);
            $table->unsignedInteger('page')->default(1);
            $table->string('state', 32)->default('queued');
            $table->json('cursor')->nullable();
            $table->json('next_cursor')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('received_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'sync_run_id', 'resource', 'page'], 'sync_batch_unique');
            $table->index(['tenant_id', 'site_id', 'state'], 'sync_batches_site_state_idx');
        });

        Schema::create('sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->foreignId('sync_batch_id')->nullable()->constrained('sync_batches')->nullOnDelete();
            $table->string('resource', 64);
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->string('action', 32)->default('reconcile');
            $table->string('state', 32)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('remote_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'site_id', 'state'], 'sync_items_site_state_idx');
            $table->index(['tenant_id', 'sync_run_id', 'resource'], 'sync_items_run_resource_idx');
        });

        Schema::create('sync_resource_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->string('resource', 64);
            $table->unsignedBigInteger('remote_id');
            $table->string('local_model_type', 96)->nullable();
            $table->unsignedBigInteger('local_model_id')->nullable();
            $table->string('base_local_hash', 64)->nullable();
            $table->string('base_remote_hash', 64)->nullable();
            $table->string('remote_version')->nullable();
            $table->timestamp('remote_modified_at')->nullable();
            $table->foreignId('last_seen_sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('tombstoned_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'resource', 'remote_id'], 'sync_resource_version_unique');
            $table->index(['tenant_id', 'site_id', 'resource', 'last_seen_sync_run_id'], 'sync_resource_seen_idx');
        });

        Schema::create('sync_tombstones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->string('resource', 64);
            $table->unsignedBigInteger('remote_id');
            $table->unsignedInteger('missing_observations')->default(0);
            $table->timestamp('first_missing_at');
            $table->timestamp('last_checked_at');
            $table->timestamp('confirmed_deleted_at')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'resource', 'remote_id'], 'sync_tombstone_unique');
        });

        Schema::create('sync_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('connector_id')->nullable();
            $table->string('event_hash', 64);
            $table->string('event_id', 191);
            $table->string('event_type', 96);
            $table->string('resource', 64)->nullable();
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->string('action', 32)->nullable();
            $table->string('payload_hash', 64);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('verified_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('state', 32)->default('verified');
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'event_hash'], 'sync_webhook_event_unique');
            $table->index(['tenant_id', 'site_id', 'occurred_at'], 'sync_webhook_event_time_idx');
        });

        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->string('event_type', 64);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'site_id', 'occurred_at'], 'sync_event_time_idx');
        });

        Schema::create('sync_site_leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->uuid('owner_token');
            $table->string('purpose', 64);
            $table->timestamp('leased_until');
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id'], 'sync_site_lease_unique');
            $table->index(['tenant_id', 'leased_until'], 'sync_site_lease_expiry_idx');
        });

        Schema::table('content_conflicts', function (Blueprint $table) {
            $table->string('resource', 64)->nullable()->after('site_id');
            $table->string('local_hash', 64)->nullable()->after('remote_hash');
            $table->string('local_version')->nullable()->after('local_hash');
            $table->timestamp('detected_at')->nullable()->after('local_snapshot');
            $table->foreignId('resolved_by_user_id')->nullable()->after('resolution')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_conflicts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by_user_id');
            $table->dropColumn(['resource', 'local_hash', 'local_version', 'detected_at']);
        });
        Schema::dropIfExists('sync_site_leases');
        Schema::dropIfExists('sync_events');
        Schema::dropIfExists('sync_webhook_events');
        Schema::dropIfExists('sync_tombstones');
        Schema::dropIfExists('sync_resource_versions');
        Schema::dropIfExists('sync_items');
        Schema::dropIfExists('sync_batches');
        Schema::dropIfExists('sync_runs');
    }
};
