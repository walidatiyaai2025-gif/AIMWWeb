<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\BillingAudit;
use App\Models\BillingSubscriptionChange;
use App\Models\BillingTransaction;
use App\Models\Concerns\BelongsToTenant;
use App\Models\IdempotencyKey;
use App\Models\TenantBillingProfile;
use App\Models\TenantSecret;
use App\Models\TenantSubscription;
use App\Models\TenantUsageCounter;
use Tests\TestCase;

class AcceptanceFrameworkTest extends TestCase
{
    public function test_acceptance_matrix_covers_every_required_security_family(): void
    {
        $matrix = json_decode(
            file_get_contents(base_path('tests/Contracts/acceptance-matrix.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame([
            'sites', 'content', 'media', 'comments', 'taxonomy', 'credentials', 'connector', 'ai_config',
            'audits', 'findings', 'suggestions', 'approvals', 'executions', 'evidence', 'jobs', 'schedules',
            'backups', 'reports', 'members', 'settings', 'billing_subscription', 'billing_profile',
            'billing_usage', 'billing_transactions', 'billing_changes', 'billing_audits',
        ], $matrix['tenant_resources']);

        $this->assertContains('bulk_mixed_tenant_ids', $matrix['tenant_attack_shapes']);
        $this->assertContains('nonce_replay', $matrix['connector_security']);
        $this->assertContains('idempotent_replay', $matrix['connector_security']);
        $this->assertContains('retry_does_not_duplicate_mutation', $matrix['execution_safety']);
        $this->assertContains('stale_lock_recovery', $matrix['queue_concurrency']);
        $this->assertContains('rollback_then_forward', $matrix['mysql_validation']);
        $this->assertContains('mutation_and_verification_when_domain_runtime_present', $matrix['wordpress_e2e']);
        $this->assertContains('no_obvious_n_plus_one', $matrix['performance']);
        $this->assertContains('accessibility', $matrix['frontend_acceptance']);
    }

    public function test_current_tenant_owned_models_use_the_shared_tenant_scope_contract(): void
    {
        foreach ([
            TenantSecret::class,
            IdempotencyKey::class,
            AuditEvent::class,
            TenantBillingProfile::class,
            TenantSubscription::class,
            TenantUsageCounter::class,
            BillingTransaction::class,
            BillingSubscriptionChange::class,
            BillingAudit::class,
        ] as $model) {
            $uses = class_uses_recursive($model);
            $this->assertArrayHasKey(
                BelongsToTenant::class,
                $uses,
                $model.' must remain tenant scoped.',
            );
        }
    }

    public function test_release_gate_and_census_artifacts_are_present(): void
    {
        $this->assertFileExists(base_path('../tools/capability_census.py'));
        $this->assertFileExists(base_path('../tools/acceptance_gate.py'));
        $this->assertFileExists(base_path('../docs/capability-parity-ledger.json'));
        $this->assertFileExists(base_path('../docs/CAPABILITY_PARITY_LEDGER.md'));
        $this->assertFileExists(base_path('../docs/ACCEPTANCE_SECURITY_MATRIX.md'));
    }
}
