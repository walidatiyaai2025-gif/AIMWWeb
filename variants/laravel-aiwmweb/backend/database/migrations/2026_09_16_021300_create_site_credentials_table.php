<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('username')->nullable();
            $table->text('secret_value');
            $table->timestamps();

            $table->unique(['tenant_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_credentials');
    }
};
