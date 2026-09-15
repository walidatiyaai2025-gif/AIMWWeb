<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('home_url', 2048)->nullable()->after('url');
            $table->string('wordpress_version', 64)->nullable()->after('home_url');
            $table->string('language_code', 32)->nullable()->after('wordpress_version');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['home_url', 'wordpress_version', 'language_code']);
        });
    }
};
