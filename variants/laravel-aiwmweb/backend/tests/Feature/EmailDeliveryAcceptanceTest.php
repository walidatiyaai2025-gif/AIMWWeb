<?php

namespace Tests\Feature;

use App\Email\Contracts\EmailTransport;
use App\Email\Exceptions\EmailTransportException;
use App\Email\Services\DomainNotificationBridge;
use App\Email\Services\EmailDeliveryService;
use App\Email\Services\EmailScheduleService;
use App\Email\Services\EmailTemplateService;
use App\Email\Services\MailConfigurationService;
use App\Email\Services\NotificationPlatformService;
use App\Jobs\SendEmailDeliveryJob;
use App\Models\EmailDelivery;
use App\Models\InAppNotification;
use App\Models\MailConfiguration;
use App\Models\NotificationEventReceipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSecret;
use App\Models\User;
use App\Tenancy\TenantCache;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmailDeliveryAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_delivery_transitions_sending_to_sent_only_after_transport_success(): void
    {
        Queue::fake();
        [$tenant, $membership] = $this->tenantWithMember('mail-success');
        $this->activate($tenant, $membership);
        $this->configureMail();

        $observed = [];
        $transport = new ScriptedEmailTransport(function () use (&$observed): array {
            $current = EmailDelivery::query()->firstOrFail();
            $observed[] = [$current->status, $current->sent_at];

            return ['provider_message_id' => 'provider-success-1'];
        });
        $this->app->instance(EmailTransport::class, $transport);

        $delivery = app(EmailDeliveryService::class)->queue($this->deliveryInput('queue-success'));
        $this->assertSame('QUEUED', $delivery->status);
        Queue::assertPushed(SendEmailDeliveryJob::class, 1);

        $result = app(EmailDeliveryService::class)->send($delivery->id);

        $this->assertFalse($result['retry']);
        $this->assertSame([['SENDING', null]], $observed);
        $delivery->refresh();
        $this->assertSame('SENT', $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertSame('provider-success-1', $delivery->provider_message_id);
        $this->assertNotNull($delivery->sent_at);
    }

    public function test_transient_retry_is_bounded_and_permanent_config_and_invalid_recipient_fail_without_retry(): void
    {
        Queue::fake();
        [$tenant, $membership] = $this->tenantWithMember('mail-retry');
        $this->activate($tenant, $membership);
        $this->configureMail();

        $transport = new ScriptedEmailTransport(new EmailTransportException('TIMEOUT', true, 'timeout password=hidden', 9999));
        $this->app->instance(EmailTransport::class, $transport);
        $delivery = app(EmailDeliveryService::class)->queue($this->deliveryInput('retry-bounded', ['max_attempts' => 2]));

        $first = app(EmailDeliveryService::class)->send($delivery->id);
        $this->assertTrue($first['retry']);
        $this->assertSame(1800, $first['delay']);
        $this->assertSame('RETRYING', $delivery->fresh()->status);
        $this->assertSame('TIMEOUT', $delivery->fresh()->failure_category);
        $this->assertStringNotContainsString('hidden', (string) $delivery->fresh()->failure_message);

        $second = app(EmailDeliveryService::class)->send($delivery->id);
        $this->assertFalse($second['retry']);
        $this->assertSame('FAILED', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempt_count);

        $permanentTransport = new ScriptedEmailTransport(new EmailTransportException('PERMANENT_REJECTION', false, 'rejected'));
        $this->app->instance(EmailTransport::class, $permanentTransport);
        $permanent = app(EmailDeliveryService::class)->queue($this->deliveryInput('permanent'));
        $permanentResult = app(EmailDeliveryService::class)->send($permanent->id);
        $this->assertFalse($permanentResult['retry']);
        $this->assertSame('FAILED', $permanent->fresh()->status);
        $this->assertSame('PERMANENT_REJECTION', $permanent->fresh()->failure_category);

        [$noConfigTenant, $noConfigMembership] = $this->tenantWithMember('mail-no-config');
        $this->activate($noConfigTenant, $noConfigMembership);
        $noConfig = app(EmailDeliveryService::class)->queue($this->deliveryInput('no-config'));
        $noConfigResult = app(EmailDeliveryService::class)->send($noConfig->id);
        $this->assertFalse($noConfigResult['retry']);
        $this->assertSame('FAILED', $noConfig->fresh()->status);
        $this->assertSame('AUTHENTICATION_CONFIG_FAILURE', $noConfig->fresh()->failure_category);

        try {
            app(EmailDeliveryService::class)->queue($this->deliveryInput('invalid-recipient', ['recipient' => 'not-an-email']));
            $this->fail('Invalid recipient must be rejected before queue persistence.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_duplicate_delivery_repeated_send_and_concurrent_job_identity_are_idempotent_and_tenant_partitioned(): void
    {
        Queue::fake();
        [$tenantA, $memberA] = $this->tenantWithMember('mail-dup-a');
        [$tenantB, $memberB] = $this->tenantWithMember('mail-dup-b');

        $this->activate($tenantA, $memberA);
        $this->configureMail();
        $transport = new ScriptedEmailTransport(['provider_message_id' => 'once']);
        $this->app->instance(EmailTransport::class, $transport);
        $service = app(EmailDeliveryService::class);
        $input = $this->deliveryInput('same-key');
        $first = $service->queue($input);
        $duplicate = $service->queue($input);
        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(1, EmailDelivery::query()->count());
        Queue::assertPushed(SendEmailDeliveryJob::class, 1);

        $jobA1 = new SendEmailDeliveryJob($tenantA->id, $first->id);
        $jobA2 = new SendEmailDeliveryJob($tenantA->id, $first->id);
        $this->assertSame($jobA1->uniqueId(), $jobA2->uniqueId());

        $service->send($first->id);
        $service->send($first->id);
        $this->assertSame(1, $transport->attempts);
        $this->assertSame('SENT', $first->fresh()->status);

        $lockA = app(TenantCache::class)->key('lock:email-delivery:shared');
        $this->activate($tenantB, $memberB);
        $this->configureMail();
        $other = app(EmailDeliveryService::class)->queue($this->deliveryInput('same-key'));
        $lockB = app(TenantCache::class)->key('lock:email-delivery:shared');
        $this->assertNotSame($first->id, $other->id);
        $this->assertNotSame($lockA, $lockB);
        $this->assertNotSame(
            (new SendEmailDeliveryJob($tenantA->id, $first->id))->uniqueId(),
            (new SendEmailDeliveryJob($tenantB->id, $other->id))->uniqueId(),
        );
    }

    public function test_templates_render_english_arabic_rtl_escape_variables_and_reject_mismatch_and_subject_newlines(): void
    {
        [$tenant, $membership] = $this->tenantWithMember('mail-template');
        $this->activate($tenant, $membership);
        $templates = app(EmailTemplateService::class);

        $en = $templates->render('operation.alert', 'en', ['title' => 'Alert', 'message' => '<script>alert(1)</script>']);
        $ar = $templates->render('operation.alert', 'ar', ['title' => 'تنبيه', 'message' => 'رسالة عربية']);
        $this->assertSame('ltr', $en['direction']);
        $this->assertStringContainsString('dir="ltr"', $en['html']);
        $this->assertStringNotContainsString('<script>', $en['html']);
        $this->assertStringContainsString('&lt;script&gt;', $en['html']);
        $this->assertSame('rtl', $ar['direction']);
        $this->assertStringContainsString('dir="rtl"', $ar['html']);
        $this->assertStringContainsString('رسالة عربية', $ar['html']);

        foreach ([
            ['title' => 'Missing message'],
            ['title' => 'Known', 'message' => 'Known', 'unexpected' => 'no'],
        ] as $invalidVariables) {
            try {
                $templates->render('operation.alert', 'en', $invalidVariables);
                $this->fail('Template variable mismatch must fail closed.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        try {
            $templates->render('operation.alert', 'en', ['title' => "Hello\r\nBcc: victim@example.test", 'message' => 'body']);
            $this->fail('Rendered subject newline injection must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        try {
            $templates->save('custom.notice', 'en', [
                'subject_template' => "Bad\nSubject",
                'html_template' => '<p>{{message}}</p>',
                'variables' => ['message'],
            ]);
            $this->fail('Stored subject newline injection must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_user_and_tenant_preferences_suppress_optional_events_but_mandatory_alerts_override(): void
    {
        Queue::fake();
        [$tenant, $membership] = $this->tenantWithMember('mail-pref');
        $this->activate($tenant, $membership);
        $notifications = app(NotificationPlatformService::class);

        $notifications->setPreference('reports', 'disabled');
        $optional = $notifications->consume($this->event($membership, 'report.ready', 'reports-disabled'));
        $this->assertSame('disabled', $optional->delivery_mode);
        $this->assertSame('SUPPRESSED', EmailDelivery::query()->where('event_id', $optional->event_id)->firstOrFail()->status);

        $notifications->setPreference('sync', 'disabled', $membership->user_id);
        $userSuppressed = $notifications->consume($this->event($membership, 'sync.completed', 'user-disabled'));
        $this->assertSame('disabled', $userSuppressed->delivery_mode);
        $this->assertSame('SUPPRESSED', EmailDelivery::query()->where('event_id', $userSuppressed->event_id)->firstOrFail()->status);

        $mandatory = $notifications->consume($this->event($membership, 'sync.failed', 'mandatory-override'));
        $this->assertTrue($mandatory->mandatory);
        $this->assertSame('immediate', $mandatory->delivery_mode);
        $this->assertSame('QUEUED', EmailDelivery::query()->where('event_id', $mandatory->event_id)->firstOrFail()->status);
    }

    public function test_domain_event_bridges_cover_billing_sync_and_job_failures(): void
    {
        Queue::fake();
        [$tenant, $membership] = $this->tenantWithMember('mail-events');
        $this->activate($tenant, $membership);
        $bridge = app(DomainNotificationBridge::class);

        $billingId = (string) Str::uuid();
        $billing = $bridge->billing($billingId, 'payment_failed', $this->bridgePayload($membership, 'Payment failed'));
        $this->assertSame('billing', $billing->source);
        $this->assertSame('error', $billing->severity);
        $this->assertTrue($billing->mandatory);

        $jobId = (string) Str::uuid();
        $job = $bridge->operational($jobId, 'job.failed', 'jobs', $this->bridgePayload($membership, 'Job failed'));
        $this->assertSame('jobs', $job->source);
        $this->assertTrue($job->mandatory);

        $run = (object) [
            'id' => 501,
            'tenant_id' => $tenant->id,
            'site_id' => 77,
            'initiated_by_user_id' => $membership->user_id,
        ];
        Event::dispatch('SyncStarted', [$run, ['state' => 'running']]);
        Event::dispatch('SyncFailed', [$run, ['state' => 'failed', 'error' => 'must-not-be-copied']]);
        Event::dispatch('SyncFailed', [$run, ['state' => 'failed', 'error' => 'duplicate']]);
        Event::dispatch('SyncCompleted', [$run, ['state' => 'completed']]);

        $this->assertSame(5, InAppNotification::query()->count());
        $this->assertSame(5, NotificationEventReceipt::query()->count());
        $failed = InAppNotification::query()->where('source', 'sync')->where('severity', 'error')->firstOrFail();
        $this->assertStringNotContainsString('must-not-be-copied', $failed->message);
        $this->assertTrue($failed->mandatory);
    }

    public function test_notification_center_lists_filters_counts_reads_and_validates_deep_links(): void
    {
        [$tenant, $membership] = $this->tenantWithMember('mail-center');
        $this->activate($tenant, $membership);
        $notifications = app(NotificationPlatformService::class);

        $notifications->consume($this->event($membership, 'sync.started', 'center-one', ['deep_link' => '/sync']));
        $notifications->consume($this->event($membership, 'backup.failed', 'center-two', ['source' => 'backup']));

        $this->assertSame(2, $notifications->unreadCount());
        $filtered = $notifications->listForCurrentUser(['severity' => 'error', 'source' => 'backup', 'per_page' => 10]);
        $this->assertSame(1, $filtered['total']);
        $first = InAppNotification::query()->where('user_id', $membership->user_id)->oldest()->firstOrFail();
        $read = $notifications->markRead($first->id);
        $this->assertNotNull($read['read_at']);
        $this->assertSame(1, $notifications->unreadCount());
        $this->assertSame(1, $notifications->markAllRead());
        $this->assertSame(0, $notifications->unreadCount());

        foreach (['https://evil.example', '//evil.example/path', "/safe\nInjected"] as $badLink) {
            try {
                $notifications->consume($this->event($membership, 'sync.started', (string) Str::uuid(), ['deep_link' => $badLink]));
                $this->fail('Unsafe deep links must be rejected.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_configuration_and_delivery_history_redact_secrets_and_sensitive_contents(): void
    {
        Queue::fake();
        [$tenant, $membership] = $this->tenantWithMember('mail-redaction');
        $this->activate($tenant, $membership);
        $configuration = $this->configureMail('smtp-top-secret');

        $serialized = app(MailConfigurationService::class)->serialize($configuration);
        $this->assertArrayNotHasKey('secret', $serialized);
        $this->assertTrue($serialized['has_secret']);
        $rawSecret = DB::table('tenant_secrets')->where('tenant_id', $tenant->id)->where('name', "email.transport.{$configuration->id}.credential")->value('encrypted_value');
        $this->assertNotSame('smtp-top-secret', $rawSecret);
        $this->assertArrayNotHasKey('encrypted_value', TenantSecret::query()->where('name', "email.transport.{$configuration->id}.credential")->firstOrFail()->toArray());

        $transport = new ScriptedEmailTransport(new EmailTransportException('TEMPORARY_PROVIDER_FAILURE', false, 'password=smtp-top-secret token=private-token'));
        $this->app->instance(EmailTransport::class, $transport);
        $delivery = app(EmailDeliveryService::class)->queue($this->deliveryInput('history-redaction'));
        app(EmailDeliveryService::class)->send($delivery->id);
        $history = app(EmailDeliveryService::class)->history();
        $json = json_encode($history, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('owner@example.test', $json);
        $this->assertStringNotContainsString('smtp-top-secret', $json);
        $this->assertStringNotContainsString('private-token', $json);
        $this->assertStringNotContainsString('variables', $json);
        $this->assertStringContainsString('o***@example.test', $json);
    }

    public function test_cross_tenant_notification_history_preference_and_configuration_idor_are_closed(): void
    {
        Queue::fake();
        [$tenantA, $memberA] = $this->tenantWithMember('mail-idor-a');
        [$tenantB, $memberB] = $this->tenantWithMember('mail-idor-b');

        $this->activate($tenantB, $memberB);
        $this->configureMail('beta-secret');
        app(NotificationPlatformService::class)->setPreference('beta-only', 'disabled');
        $notificationB = app(NotificationPlatformService::class)->consume($this->event($memberB, 'sync.failed', 'idor-b'));
        $deliveryB = EmailDelivery::query()->where('event_id', $notificationB->event_id)->firstOrFail();
        $deliveryB->update(['provider_message_id' => 'beta-provider-id']);
        app(TenantContext::class)->forget();

        $this->actingAs($memberA->user)
            ->postJson("/api/v1/tenants/{$tenantA->slug}/notifications/{$notificationB->id}/read")
            ->assertNotFound();

        $history = $this->actingAs($memberA->user)
            ->getJson("/api/v1/tenants/{$tenantA->slug}/email/deliveries")
            ->assertOk()->getContent();
        $this->assertStringNotContainsString($deliveryB->delivery_id, $history);
        $this->assertStringNotContainsString('beta-provider-id', $history);

        $preferences = $this->actingAs($memberA->user)
            ->getJson("/api/v1/tenants/{$tenantA->slug}/notification-preferences/tenant")
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('beta-only', $preferences);

        $this->actingAs($memberA->user)
            ->getJson("/api/v1/tenants/{$tenantB->slug}/email/configuration")
            ->assertNotFound();

        $this->actingAs($memberA->user)
            ->getJson("/api/v1/tenants/{$tenantA->slug}/email/configuration")
            ->assertOk()->assertJsonPath('configured', false);
    }

    public function test_send_email_job_restores_tenant_context_and_system_audit_works_without_membership(): void
    {
        Queue::fake();
        [$tenant, $membership] = $this->tenantWithMember('mail-job-context');
        $this->activate($tenant, $membership);
        $this->configureMail();
        $observedTenantIds = [];
        $transport = new ScriptedEmailTransport(function () use (&$observedTenantIds): array {
            $observedTenantIds[] = app(TenantContext::class)->id();

            return ['provider_message_id' => 'job-context-provider'];
        });
        $this->app->instance(EmailTransport::class, $transport);
        $delivery = app(EmailDeliveryService::class)->queue($this->deliveryInput('job-context'));
        app(TenantContext::class)->forget();

        Bus::dispatchSync(new SendEmailDeliveryJob($tenant->id, $delivery->id));

        $this->assertSame([$tenant->id], $observedTenantIds);
        $this->assertFalse(app(TenantContext::class)->active());
        $this->activate($tenant, $membership);
        $this->assertSame('SENT', EmailDelivery::query()->findOrFail($delivery->id)->status);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'event' => 'email.delivery.sent', 'actor_user_id' => null]);
    }

    public function test_due_schedules_queue_once_and_validate_site_ownership(): void
    {
        Queue::fake();
        [$tenantA, $memberA] = $this->tenantWithMember('mail-schedule-a');
        [$tenantB, $memberB] = $this->tenantWithMember('mail-schedule-b');

        $this->activate($tenantB, $memberB);
        $siteB = Site::query()->create(['name' => 'B Site', 'url' => 'https://b.example.test']);

        $this->activate($tenantA, $memberA);
        $siteA = Site::query()->create(['name' => 'A Site', 'url' => 'https://a.example.test']);
        $service = app(EmailScheduleService::class);
        $schedule = $service->save(null, [
            'site_id' => $siteA->id,
            'name' => 'Daily summary',
            'template_stable_id' => 'operation.alert',
            'recipient' => 'owner@example.test',
            'locale' => 'en',
            'variables' => ['title' => 'Summary', 'message' => 'Ready'],
            'enabled' => true,
            'interval_minutes' => 60,
            'next_run_at' => now()->subMinute(),
        ]);
        $this->assertCount(1, $service->all());
        $this->assertSame(1, $service->dispatchDue());
        $this->assertSame(1, EmailDelivery::query()->count());
        $this->assertNotNull($schedule->fresh()->last_run_at);
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());

        try {
            $service->save(null, [
                'site_id' => $siteB->id,
                'name' => 'Foreign site',
                'template_stable_id' => 'operation.alert',
                'recipient' => 'owner@example.test',
                'variables' => ['title' => 'x', 'message' => 'y'],
                'enabled' => true,
            ]);
            $this->fail('Cross-tenant schedule site association must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_site_mail_configuration_cannot_reference_another_tenants_site_and_diagnostics_omit_secret(): void
    {
        [$tenantA, $memberA] = $this->tenantWithMember('mail-site-a');
        [$tenantB, $memberB] = $this->tenantWithMember('mail-site-b');
        $this->activate($tenantB, $memberB);
        $siteB = Site::query()->create(['name' => 'B Site', 'url' => 'https://mail-b.example.test']);

        $this->activate($tenantA, $memberA);
        $siteA = Site::query()->create(['name' => 'A Site', 'url' => 'https://mail-a.example.test']);
        $transport = new ScriptedEmailTransport(['provider_message_id' => null], ['ok' => true, 'message' => 'SMTP configuration is syntactically valid.']);
        $this->app->instance(EmailTransport::class, $transport);
        $configuration = app(MailConfigurationService::class)->save('site:'.$siteA->id, [
            'site_id' => $siteA->id,
            'host' => 'smtp.example.test',
            'from_address' => 'noreply@example.test',
            'from_name' => 'AIWMWeb',
            'username' => 'mailer',
            'secret' => 'site-secret',
            'enabled' => true,
        ]);
        $this->assertSame($siteA->id, $configuration->site_id);
        $diagnostic = app(MailConfigurationService::class)->diagnose('site:'.$siteA->id);
        $this->assertTrue($diagnostic['ok']);
        $this->assertStringNotContainsString('site-secret', json_encode($diagnostic, JSON_THROW_ON_ERROR));

        try {
            app(MailConfigurationService::class)->save('foreign', [
                'site_id' => $siteB->id,
                'host' => 'smtp.example.test',
                'from_address' => 'noreply@example.test',
                'from_name' => 'AIWMWeb',
                'enabled' => true,
            ]);
            $this->fail('Cross-tenant mail configuration site association must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_email_parity_artifact_contains_exact_82_individual_classifications(): void
    {
        $path = dirname(__DIR__, 3).'/docs/EMAIL_DELIVERY_PARITY.md';
        $text = file_get_contents($path);
        $this->assertNotFalse($text);
        preg_match_all('/AIMW-EMAI-[A-F0-9]{10}/', $text, $ids);
        $this->assertCount(82, array_unique($ids[0]));
        $this->assertSame(33, substr_count($text, '| ADAPTED |'));
        $this->assertSame(49, substr_count($text, '| PENDING |'));
        $this->assertStringContainsString('PORTED 0 / ADAPTED 33 / PENDING 49 / BLOCKED 0 / VERIFIED_UNAVAILABLE_EXTERNAL 0', $text);
        $this->assertStringContainsString('40.24% (33/82)', $text);
        $this->assertStringContainsString('f88e41f9b74442cbb9666f5618c9845c2ac48a9a', $text);
    }

    private function tenantWithMember(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create(['email' => $slug.'@example.test']);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'email-owner']);
        foreach (['tenant.view', 'tenant.manage'] as $permissionName) {
            $permission = Permission::query()->create(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }

    private function activate(Tenant $tenant, TenantMembership $membership): void
    {
        $membership->setRelation('user', $membership->user()->firstOrFail());
        app(TenantContext::class)->activate($tenant, $membership);
    }

    private function configureMail(string $secret = 'smtp-secret'): MailConfiguration
    {
        return app(MailConfigurationService::class)->save('default', [
            'transport' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer',
            'secret' => $secret,
            'from_address' => 'noreply@example.test',
            'from_name' => 'AIWMWeb',
            'enabled' => true,
            'timeout_seconds' => 20,
            'max_attempts' => 4,
        ]);
    }

    private function deliveryInput(string $key, array $overrides = []): array
    {
        return array_replace([
            'event_id' => (string) Str::uuid(),
            'idempotency_key' => $key,
            'recipient' => 'owner@example.test',
            'template_stable_id' => 'operation.alert',
            'locale' => 'en',
            'variables' => ['title' => 'Alert', 'message' => 'Message'],
            'max_attempts' => 4,
        ], $overrides);
    }

    private function event(TenantMembership $membership, string $type, string $seed, array $overrides = []): array
    {
        $eventId = Str::isUuid($seed) ? $seed : (string) Str::uuid();

        return array_replace([
            'event_id' => $eventId,
            'type' => $type,
            'user_id' => $membership->user_id,
            'recipient_email' => $membership->user->email,
            'locale' => 'en',
            'title' => 'Event '.$seed,
            'message' => 'Event message '.$seed,
            'source' => Str::before($type, '.'),
        ], $overrides);
    }

    private function bridgePayload(TenantMembership $membership, string $title): array
    {
        return [
            'user_id' => $membership->user_id,
            'recipient_email' => $membership->user->email,
            'locale' => 'en',
            'title' => $title,
            'message' => $title.' message',
            'deep_link' => '/notifications',
        ];
    }
}

final class ScriptedEmailTransport implements EmailTransport
{
    public int $attempts = 0;

    public function __construct(
        private mixed $sendOutcome = ['provider_message_id' => 'provider-default'],
        private array $diagnostic = ['ok' => true, 'message' => 'ok'],
    ) {}

    public function send(MailConfiguration $configuration, ?string $secret, array $message): array
    {
        $this->attempts++;
        if ($this->sendOutcome instanceof EmailTransportException) {
            throw $this->sendOutcome;
        }
        if (is_callable($this->sendOutcome)) {
            return ($this->sendOutcome)($configuration, $secret, $message);
        }

        return $this->sendOutcome;
    }

    public function diagnose(MailConfiguration $configuration, ?string $secret): array
    {
        return $this->diagnostic;
    }
}
