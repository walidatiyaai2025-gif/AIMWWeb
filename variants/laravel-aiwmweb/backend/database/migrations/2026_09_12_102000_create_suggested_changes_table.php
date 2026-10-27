<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggested_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->uuid('change_id');
            $table->string('fingerprint', 64)->nullable();
            $table->string('source_type', 96)->nullable();
            $table->string('object_type', 96)->nullable();
            $table->string('object_id')->nullable();
            $table->string('change_type', 128)->nullable();
            $table->longText('current_value')->nullable();
            $table->longText('proposed_value')->nullable();
            $table->text('reason')->nullable();
            $table->decimal('confidence', 5, 4)->default(0);
            $table->string('risk_level', 16)->default('Low');
            $table->boolean('requires_backup')->default(false);
            $table->boolean('requires_staging')->default(false);
            $table->string('approval_status', 24)->default('Pending');
            $table->string('execution_status', 24)->default('NotStarted');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'change_id'], 'suggested_changes_tenant_change_unique');
            $table->index(['tenant_id', 'site_id', 'approval_status'], 'suggested_changes_approval_index');
            $table->index(['tenant_id', 'site_id', 'execution_status'], 'suggested_changes_execution_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggested_changes');
    }
};
