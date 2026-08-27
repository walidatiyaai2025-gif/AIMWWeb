<?php

namespace Tests\Feature;

use App\Email\Contracts\EmailTransport;
use App\Email\Exceptions\EmailTransportException;
use App\Email\Services\EmailDeliveryService;
use App\Email\Services\EmailSecretStore;
use App\Email\Services\EmailTemplateService;
use App\Email\Services\MailConfigurationService;
use App\Email\Services\NotificationPlatformService;
use App\Jobs\SendEmailDeliveryJob;
use App\Models\EmailDelivery;
use App\Models\InAppNotification;
use App\Models\MailConfiguration;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailNotificationClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_secret_is_encrypted_hidden_and_templates_support_english_and_arabic(): void
    {
        [$tenant, $membership] = $this->tenantWithMember('email-a');
        app(TenantContext::class)->activate($tenant, $membership);

        $configuration = app(MailConfigurationService::class)->save('default', [
            'host' => 'smtp.example.test',
            'port' => 587,
            'from_address' => 'noreply@example.test',
            'from_name' => 'AIWMWeb',
            'username' => 'mailer',
            'secret' => 'smtp-super-secret',
            'enabled' => true,
        ]);

        $raw = DB::table('tenant_secrets')
            ->where('tenant_id', $tenant->id)
            ->where('name', "email.transport.{$configuration->id}.credential")
            ->value('encrypted_value');

        $this->assertNotNull($raw);
        $this->assertNotSame('smtp-super-secret', $raw);
        $this->assertSame('smtp-super-secret', app(EmailSecretStore::class)->get($configuration));
        $this->assertArrayNotHasKey('secret', app(MailConfigurationService::class)->get('default'));

        $templates = app(EmailTemplateService::class);
        $en = $templates->render('sync.status', 'en', ['title' => 'Done', 'message' => 'Completed']);
        $ar = $templates->render('sync.status', 'ar', ['title' => 'اكتمل', 'message' => 'تمت المزامنة']);

        $this->assertSame('ltr', $en['direction']);
        $this->assertStringContainsString('dir="ltr"', $en['html']);
        $this->assertSame('rtl', $ar['direction']);
        $this->assertStringContainsString('dir="rtl"', $ar['html']);
        $this->assertStringContainsString('تمت المزامنة', $ar['html']);
    }

    public function test_delivery_queue_is_idempotent_and_retry_failures_are_bounded_and_redacted(): void
    {
        Queue::fake();
        [$tenant, $membership] = $this->tenantWithMember('email-a');
        app(TenantContext::class)->activate($tenant, $membership);

        $transport = new RetryableFakeEmailTransport;
        $this->app->instance(EmailTransport::class, $transport);
        app(MailConfigurationService::class)->save('default', [
            'host' => 'smtp.example.test',
            'from_address' => 'noreply@example.test',
            'from_name' => 'AIWMWeb',
            'secret' => 'smtp-secret',
            'enabled' => true,
        ]);

        $service = app(EmailDeliveryService::class);
        $input = [
            'event_id' => (string) Str::uuid(),
            'idempotency_key' => 'email-idempotency-1',
            'recipient' => 'owner@example.test',
            'template_stable_id' => 'operation.alert',
            'locale' => 'en',
            'variables' => ['title' => 'Alert', 'message' => 'Retry me'],
            'max_attempts' => 2,
        ];

        $first = $service->queue($input);
        $second = $service->queue($input);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, EmailDelivery::query()->count());
        Queue::assertPushed(SendEmailDeliveryJob::class, 1);

        $firstAttempt = $service->send($first->id);
        $this->assertTrue($firstAttempt['retry']);
        $this->assertSame(20, $firstAttempt['delay']);
        $this->assertSame('RETRYING', $first->fresh()->status);
        $this->assertStringNotContainsString('smtp-secret-value', (string) $first->fresh()->failure_message);
        $this->assertStringNotContainsString('token-value', (string) $first->fresh()->failure_message);

        $secondAttempt = $service->send($first->id);
        $this->assertFalse($secondAttempt['retry']);
        $this->assertSame('FAILED', $first->fresh()->status);
        $this->assertSame(2, $first->fresh()->attempt_count);
        $this->assertSame(2, $transport->attempts);
    }

    public function test_notification_center_is_idempotent_readable_preference_aware_and_tenant_scoped(): void
    {
        [$tenantA, $memberA] = $this->tenantWithMember('email-a');
        [$tenantB, $memberB] = $this->tenantWithMember('email-b');
        $context = app(TenantContext::class);
        $context->activate($tenantA, $memberA);

        $service = app(NotificationPlatformService::class);
        $service->setPreference('sync', 'digest', $memberA->user_id, 'ar');
        $eventId = (string) Str::uuid();
        $event = [
            'event_id' => $eventId,
            'type' => 'sync.completed',
            'user_id' => $memberA->user_id,
            'locale' => 'ar',
            'title' => 'اكتملت المزامنة',
            'message' => 'تمت العملية بنجاح',
            'source' => 'sync',
        ];

        $first = $service->consume($event);
        $second = $service->consume($event);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, InAppNotification::query()->count());
        $this->assertSame('digest', $first->delivery_mode);
        $this->assertSame('ar', $first->locale);
        $this->assertSame(1, $service->unreadCount());
        $service->markRead($first->id);
        $this->assertSame(0, $service->unreadCount());
        $this->assertSame('digest', $service->preferences($memberA->user_id)[0]['mode']);

        $context->activate($tenantB, $memberB);
        $this->assertNull(InAppNotification::query()->find($first->id));
        $this->assertSame(0, app(NotificationPlatformService::class)->unreadCount());
    }

    private function tenantWithMember(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }
}

final class RetryableFakeEmailTransport implements EmailTransport
{
    public int $attempts = 0;

    public function send(MailConfiguration $configuration, ?string $secret, array $message): array
    {
        $this->attempts++;

        throw new EmailTransportException(
            'TRANSIENT_PROVIDER_FAILURE',
            true,
            'password=smtp-secret-value token=token-value',
            20,
        );
    }

    public function diagnose(MailConfiguration $configuration, ?string $secret): array
    {
        return ['ok' => true, 'message' => 'ok'];
    }
}
