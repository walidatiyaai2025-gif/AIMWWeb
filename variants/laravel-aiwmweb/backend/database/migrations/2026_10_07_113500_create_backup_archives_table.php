<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_archives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation_id', 32);
            $table->string('path', 500);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('note', 500)->nullable();
            $table->boolean('protected_secret_recovery')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'path']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_archives');
    }
};
