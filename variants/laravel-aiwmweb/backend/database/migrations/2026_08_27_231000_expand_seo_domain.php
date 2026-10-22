<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLLBACK_TENANT_INDEX = 'seo_audits_tenant_id_rollback_idx';

    public function up(): void
    {
        Schema::table('synced_contents', function (Blueprint $table) {
            $table->text('seo_canonical')->nullable()->after('seo_description');
            $table->json('seo_robots')->nullable()->after('seo_canonical');
            $table->string('seo_provider')->nullable()->after('seo_robots');
            $table->string('seo_source_hash', 64)->nullable()->after('seo_provider');
        });

        Schema::table('seo_audits', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->default(0)->after('status');
            $table->unsignedInteger('audited_items')->default(0)->after('score');
            $table->unsignedInteger('high_issues')->default(0)->after('audited_items');
            $table->unsignedInteger('medium_issues')->default(0)->after('high_issues');
            $table->unsignedInteger('low_issues')->default(0)->after('medium_issues');
            $table->string('source_hash', 64)->nullable()->after('low_issues');
            $table->string('rule_version')->default('seo-v2')->after('source_hash');
            $table->timestamp('started_at')->nullable()->after('rule_version');
            $table->index(['tenant_id', 'site_id', 'completed_at'], 'seo_audit_history_idx');
        });

        if ($this->isMysql() && $this->hasNamedIndex('seo_audits', self::ROLLBACK_TENANT_INDEX)) {
            Schema::table('seo_audits', fn (Blueprint $table) => $table->dropIndex(self::ROLLBACK_TENANT_INDEX));
        }

        Schema::table('seo_findings', function (Blueprint $table) {
            $table->string('field')->nullable()->after('code');
            $table->text('current_value')->nullable()->after('recommendation');
            $table->json('normalized_state')->nullable()->after('current_value');
            $table->timestamp('detected_at')->nullable()->after('status');
            $table->timestamp('resolved_at')->nullable()->after('detected_at');
        });
    }

    public function down(): void
    {
        Schema::table('seo_findings', function (Blueprint $table) {
            $table->dropColumn(['field', 'current_value', 'normalized_state', 'detected_at', 'resolved_at']);
        });

        if ($this->isMysql() && ! $this->hasLeadingIndex('seo_audits', 'tenant_id', 'seo_audit_history_idx')) {
            Schema::table('seo_audits', fn (Blueprint $table) => $table->index('tenant_id', self::ROLLBACK_TENANT_INDEX));
        }

        Schema::table('seo_audits', function (Blueprint $table) {
            $table->dropIndex('seo_audit_history_idx');
            $table->dropColumn(['score', 'audited_items', 'high_issues', 'medium_issues', 'low_issues', 'source_hash', 'rule_version', 'started_at']);
        });
        Schema::table('synced_contents', function (Blueprint $table) {
            $table->dropColumn(['seo_canonical', 'seo_robots', 'seo_provider', 'seo_source_hash']);
        });
    }

    private function isMysql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }

    private function hasNamedIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function hasLeadingIndex(string $table, string $column, string $except): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) !== $except && (($index['columns'][0] ?? null) === $column)) {
                return true;
            }
        }

        return false;
    }
};
