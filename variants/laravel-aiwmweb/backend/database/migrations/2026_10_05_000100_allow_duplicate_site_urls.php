<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'url']);
            $table->index(['tenant_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'url']);
            $table->unique(['tenant_id', 'url']);
        });
    }
};
