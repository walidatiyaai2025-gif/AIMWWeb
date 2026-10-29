<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('durable_ai_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('site_id')->nullable()->constrained();
            $table->string('submission_key', 64);
            $table->string('request_hash', 64);
            $table->string('state', 32)->default('pending');
            $table->timestamp('resume_after')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'submission_key']);
        });
        Schema::create('durable_ai_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('site_id')->nullable()->constrained();
            $table->foreignUuid('batch_id')->constrained('durable_ai_batches');
            $table->string('item_key', 100);
            $table->string('request_hash', 64);
            $table->unsignedInteger('position');
            $table->string('state', 32)->default('pending');
            $table->longText('request');
            $table->longText('result')->nullable();
            $table->json('usage')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->uuid('attempt_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'item_key']);
            $table->index(['state', 'created_at']);
        });
        Schema::create('durable_ai_gate', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->uuid('item_id')->nullable();
            $table->uuid('owner')->nullable();
            $table->unsignedBigInteger('fence')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('next_at')->nullable();
            $table->string('circuit', 32)->default('closed');
            $table->string('day', 10)->nullable();
            $table->unsignedInteger('calls_reserved')->default(0);
            $table->unsignedBigInteger('tokens_reserved')->default(0);
            $table->decimal('usd_reserved', 14, 6)->default(0);
        });
        DB::table('durable_ai_gate')->insert(['id' => 1]);
    }

    public function down(): void
    {
        // Only an empty development/test schema is reversible. Production AI
        // charge/result evidence is never removed by automatic down migrations.
        if (app()->environment('production') || DB::table('durable_ai_batches')->exists() || DB::table('durable_ai_items')->exists()) {
            throw new RuntimeException('Durable AI evidence cannot be rolled back; use the verified database recovery procedure.');
        }
        Schema::drop('durable_ai_items');
        Schema::drop('durable_ai_batches');
        Schema::drop('durable_ai_gate');
    }
};
