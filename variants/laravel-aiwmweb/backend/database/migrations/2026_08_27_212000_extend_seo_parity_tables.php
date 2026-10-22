<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('synced_contents', function (Blueprint $table) {
            $table->unsignedTinyInteger('seo_readability_score')->nullable()->after('seo_description');
        });

        Schema::table('seo_audits', function (Blueprint $table) {
            $table->unsignedInteger('total_items')->default(0)->after('status');
            $table->unsignedInteger('processed_items')->default(0)->after('total_items');
            $table->unsignedInteger('failed_items')->default(0)->after('processed_items');
            $table->string('current_item')->nullable()->after('failed_items');
            $table->json('log')->nullable()->after('current_item');
        });

        Schema::table('seo_findings', function (Blueprint $table) {
            $table->text('before_value')->nullable()->after('recommendation');
            $table->text('suggested_value')->nullable()->after('before_value');
            $table->json('evidence')->nullable()->after('suggested_value');
        });
    }

    public function down(): void
    {
        Schema::table('seo_findings', function (Blueprint $table) {
            $table->dropColumn(['before_value', 'suggested_value', 'evidence']);
        });
        Schema::table('seo_audits', function (Blueprint $table) {
            $table->dropColumn(['total_items', 'processed_items', 'failed_items', 'current_item', 'log']);
        });
        Schema::table('synced_contents', function (Blueprint $table) {
            $table->dropColumn(['seo_readability_score']);
        });
    }
};
