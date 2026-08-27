<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->string('type', 64);
            $table->string('slug')->nullable();
            $table->text('title')->nullable();
            $table->longText('body')->nullable();
            $table->longText('excerpt')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedBigInteger('author_remote_id')->nullable();
            $table->unsignedBigInteger('featured_media_remote_id')->nullable();
            $table->string('link')->nullable();
            $table->string('template')->nullable();
            $table->string('comment_status', 32)->nullable();
            $table->string('ping_status', 32)->nullable();
            $table->string('format', 32)->nullable();
            $table->boolean('sticky')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('remote_modified_at')->nullable();
            $table->string('remote_version')->nullable();
            $table->string('remote_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->boolean('stale')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'type', 'remote_id'], 'content_items_remote_unique');
            $table->index(['tenant_id', 'site_id', 'type', 'status']);
            $table->index(['tenant_id', 'site_id', 'remote_modified_at']);
        });

        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->json('snapshot');
            $table->string('content_hash', 64);
            $table->timestamp('remote_modified_at')->nullable();
            $table->string('source', 32)->default('wordpress');
            $table->timestamps();
            $table->index(['tenant_id', 'site_id', 'content_item_id']);
        });

        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('remote_id');
            $table->text('title')->nullable();
            $table->string('slug')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('media_type', 32)->nullable();
            $table->text('source_url')->nullable();
            $table->text('alt_text')->nullable();
            $table->longText('caption')->nullable();
            $table->longText('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('remote_hash', 64)->nullable();
            $table->timestamp('remote_modified_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('processing_state', 32)->default('ready');
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'remote_id'], 'media_remote_unique');
        });

        Schema::create('content_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('remote_id');
            $table->unsignedBigInteger('content_remote_id')->nullable();
            $table->unsignedBigInteger('parent_remote_id')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->longText('body')->nullable();
            $table->string('status', 32)->nullable();
            $table->string('link')->nullable();
            $table->timestamp('remote_created_at')->nullable();
            $table->timestamp('remote_modified_at')->nullable();
            $table->string('remote_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'remote_id'], 'comment_remote_unique');
            $table->index(['tenant_id', 'site_id', 'status']);
        });

        Schema::create('taxonomy_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('remote_id');
            $table->string('taxonomy', 96);
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('parent_remote_id')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->string('remote_hash', 64)->nullable();
            $table->timestamp('remote_modified_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'taxonomy', 'remote_id'], 'taxonomy_remote_unique');
            $table->index(['tenant_id', 'site_id', 'taxonomy', 'parent_remote_id']);
        });

        Schema::create('content_taxonomy', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxonomy_term_id')->constrained()->cascadeOnDelete();
            $table->primary(['content_item_id', 'taxonomy_term_id']);
            $table->index(['tenant_id', 'site_id']);
        });

        Schema::create('content_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->string('resource', 64);
            $table->string('state', 32)->default('idle');
            $table->string('cursor')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_remote_modified_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'resource']);
        });

        Schema::create('content_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->string('status', 32)->default('open');
            $table->timestamp('expected_modified_at')->nullable();
            $table->timestamp('remote_modified_at')->nullable();
            $table->string('expected_version')->nullable();
            $table->string('remote_version')->nullable();
            $table->string('expected_hash', 64)->nullable();
            $table->string('remote_hash', 64)->nullable();
            $table->json('local_snapshot')->nullable();
            $table->json('remote_snapshot')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'site_id', 'status']);
        });

        Schema::create('content_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->string('kind', 16);
            $table->string('state', 32)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('storage_path')->nullable();
            $table->json('options')->nullable();
            $table->json('result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'site_id', 'kind', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_transfers');
        Schema::dropIfExists('content_conflicts');
        Schema::dropIfExists('content_sync_states');
        Schema::dropIfExists('content_taxonomy');
        Schema::dropIfExists('taxonomy_terms');
        Schema::dropIfExists('content_comments');
        Schema::dropIfExists('media_items');
        Schema::dropIfExists('content_revisions');
        Schema::dropIfExists('content_items');
    }
};
