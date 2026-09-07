<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table): void {
            $table->foreignId('suggestion_id')->nullable()->change();
            $table->foreignId('site_id')->nullable()->after('suggestion_id')->constrained()->nullOnDelete();
            $table->string('site_name')->nullable()->after('site_id');
            $table->string('source_operation_id', 64)->nullable()->after('status');
            $table->string('operation_type', 191)->nullable()->after('source_operation_id');
            $table->string('title')->nullable()->after('operation_type');
            $table->string('actor_label')->nullable()->after('title');
            $table->string('risk_level', 32)->nullable()->after('actor_label');
            $table->uuid('request_key')->nullable()->after('risk_level');
            $table->unique(['tenant_id', 'request_key'], 'approvals_tenant_request_key_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('approvals')->whereNull('suggestion_id')->exists()) {
            throw new RuntimeException('Cannot roll back generalized approvals while direct AI approvals exist.');
        }

        Schema::table('approvals', function (Blueprint $table): void {
            $table->dropUnique('approvals_tenant_request_key_unique');
            $table->dropForeign(['site_id']);
            $table->dropColumn([
                'site_id',
                'site_name',
                'source_operation_id',
                'operation_type',
                'title',
                'actor_label',
                'risk_level',
                'request_key',
            ]);
            $table->foreignId('suggestion_id')->nullable(false)->change();
        });
    }
};
