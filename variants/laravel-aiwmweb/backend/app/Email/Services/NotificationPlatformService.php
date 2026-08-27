<?php

namespace App\Email\Services;

use App\Email\Contracts\NotificationEventSink;
use App\Models\AuditEvent;
use App\Models\InAppNotification;
use App\Models\NotificationEventReceipt;
use App\Models\NotificationPreference;
use App\Models\TenantMembership;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class NotificationPlatformService implements NotificationEventSink
{
    private const EVENT_MAP = [
        'sync.started' => ['category' => 'sync', 'template' => 'sync.status', 'severity' => 'info', 'mandatory' => false],
        'sync.failed' => ['category' => 'sync', 'template' => 'sync.status', 'severity' => 'error', 'mandatory' => true],
        'sync.completed' => ['category' => 'sync', 'template' => 'sync.status', 'severity' => 'success', 'mandatory' => false],
        'connector.disconnected' => ['category' => 'connector', 'template' => 'operation.alert', 'severity' => 'warning', 'mandatory' => true],
        'connector.revoked' => ['category' => 'connector', 'template' => 'operation.alert', 'severity' => 'error', 'mandatory' => true],
        'job.failed' => ['category' => 'operations', 'template' => 'operation.alert', 'severity' => 'error', 'mandatory' => true],
        'approval.pending' => ['category' => 'approval', 'template' => 'operation.alert', 'severity' => 'warning', 'mandatory' => false],
        'approval.completed' => ['category' => 'approval', 'template' => 'operation.alert', 'severity' => 'success', 'mandatory' => false],
        'execution.failed' => ['category' => 'execution', 'template' => 'operation.alert', 'severity' => 'error', 'mandatory' => true],
        'execution.succeeded' => ['category' => 'execution', 'template' => 'operation.alert', 'severity' => 'success', 'mandatory' => false],
        'billing.trial_expiring' => ['category' => 'billing', 'template' => 'billing.alert', 'severity' => 'warning', 'mandatory' => true],
        'billing.payment_failed' => ['category' => 'billing', 'template' => 'billing.alert', 'severity' => 'error', 'mandatory' => true],
        'billing.subscription_cancelled' => ['category' => 'billing', 'template' => 'billing.alert', 'severity' => 'warning', 'mandatory' => true],
        'billing.quota_nearing_limit' => ['category' => 'billing', 'template' => 'billing.alert', 'severity' => 'warning', 'mandatory' => false],
        'backup.completed' => ['category' => 'backup', 'template' => 'operation.alert', 'severity' => 'success', 'mandatory' => false],
        'backup.failed' => ['category' => 'backup', 'template' => 'operation.alert', 'severity' => 'error', 'mandatory' => true],
        'export.ready' => ['category' => 'reports', 'template' => 'operation.alert', 'severity' => 'success', 'mandatory' => false],
        'report.ready' => ['category' => 'reports', 'template' => 'operation.alert', 'severity' => 'success', 'mandatory' => false],
        'security.session' => ['category' => 'security', 'template' => 'security.alert', 'severity' => 'warning', 'mandatory' => true],
        'security.alert' => ['category' => 'security', 'template' => 'security.alert', 'severity' => 'error', 'mandatory' => true],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailDeliveryService $delivery,
        private readonly AuditLogger $audit,
    ) {}

    public function consume(array $event): InAppNotification
    {
        $eventId = (string) ($event['event_id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $definition = self::EVENT_MAP[$type] ?? null;
        $userId = (int) ($event['user_id'] ?? 0);
        $recipient = trim((string) ($event['recipient_email'] ?? ''));
        if (! Str::isUuid($eventId) || ! $definition) {
            throw ValidationException::withMessages(['event' => 'Stable UUID event_id and supported event type are required.']);
        }
        if ($userId < 1 || ! TenantMembership::query()->where('user_id', $userId)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Notification user is not an active member of this tenant.']);
        }
        if ($recipient !== '' && ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['recipient_email' => 'Notification recipient is invalid.']);
        }

        $locale = strtolower((string) ($event['locale'] ?? 'en')) === 'ar' ? 'ar' : 'en';
        $mode = $this->modeFor($userId, $definition['category']);
        $mandatory = (bool) $definition['mandatory'];
        if ($mandatory && $mode === 'disabled') {
            $mode = 'immediate';
        }
        $title = trim((string) ($event['title'] ?? $type));
        $message = trim((string) ($event['message'] ?? ''));

        try {
            $notification = DB::transaction(function () use ($eventId, $type, $event, $userId, $definition, $locale, $mode, $mandatory, $title, $message): InAppNotification {
                NotificationEventReceipt::query()->create([
                    'event_id' => $eventId,
                    'event_type' => $type,
                    'source' => (string) ($event['source'] ?? Str::before($type, '.')),
                    'received_at' => now(),
                ]);

                return InAppNotification::query()->create([
                    'user_id' => $userId,
                    'notification_id' => (string) Str::uuid(),
                    'event_id' => $eventId,
                    'category' => $definition['category'],
                    'severity' => $definition['severity'],
                    'source' => (string) ($event['source'] ?? Str::before($type, '.')),
                    'title' => $title,
                    'message' => $message,
                    'deep_link' => $this->deepLink($event['deep_link'] ?? null),
                    'mandatory' => $mandatory,
                    'locale' => $locale,
                    'delivery_mode' => $mode,
                    'metadata' => (array) ($event['metadata'] ?? []),
                ]);
            });
        } catch (QueryException $exception) {
            $existing = InAppNotification::query()->where('event_id', $eventId)->first();
            if ($existing) {
                return $existing;
            }
            throw $exception;
        }

        if ($recipient !== '') {
            $this->delivery->queue([
                'in_app_notification_id' => $notification->id,
                'event_id' => $eventId,
                'idempotency_key' => "event:{$eventId}:email:{$userId}",
                'recipient' => $recipient,
                'template_stable_id' => $definition['template'],
                'locale' => $locale,
                'variables' => ['title' => $title, 'message' => $message],
                'scheduled_for' => $mode === 'digest' ? now()->addHour()->startOfHour() : null,
            ], $mode === 'disabled');
        }

        $this->recordAudit('notification.created', [
            'notification_id' => $notification->notification_id,
            'event_type' => $type,
            'category' => $definition['category'],
            'severity' => $definition['severity'],
            'delivery_mode' => $mode,
            'mandatory' => $mandatory,
        ], $notification, $userId);

        return $notification;
    }

    public function listForCurrentUser(array $filters = []): array
    {
        return InAppNotification::query()
            ->where('user_id', $this->context->membership()->user_id)
            ->when(isset($filters['unread']), fn ($q) => $filters['unread'] ? $q->whereNull('read_at') : $q)
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->latest()->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100))
            ->through(fn (InAppNotification $n) => $this->serialize($n))->toArray();
    }

    public function unreadCount(): int
    {
        return InAppNotification::query()->where('user_id', $this->context->membership()->user_id)->whereNull('read_at')->count();
    }

    public function markRead(int $id): array
    {
        $notification = InAppNotification::query()->where('user_id', $this->context->membership()->user_id)->findOrFail($id);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return $this->serialize($notification->fresh());
    }

    public function markAllRead(): int
    {
        return InAppNotification::query()->where('user_id', $this->context->membership()->user_id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function preferences(?int $userId = null): array
    {
        $scope = $userId ? "user:{$userId}" : 'tenant';

        return NotificationPreference::query()->where('scope_key', $scope)->orderBy('category')->get()->toArray();
    }

    public function setPreference(string $category, string $mode, ?int $userId = null, ?string $locale = null): NotificationPreference
    {
        if (! in_array($mode, ['immediate', 'digest', 'disabled'], true)) {
            throw ValidationException::withMessages(['mode' => 'Mode must be immediate, digest, or disabled.']);
        }
        if ($userId && ! TenantMembership::query()->where('user_id', $userId)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Preference user is not an active member of this tenant.']);
        }
        $scope = $userId ? "user:{$userId}" : 'tenant';

        return NotificationPreference::query()->updateOrCreate(
            ['scope_key' => $scope, 'category' => $category, 'channel' => 'email'],
            ['user_id' => $userId, 'mode' => $mode, 'locale' => $locale],
        );
    }

    private function modeFor(int $userId, string $category): string
    {
        $user = NotificationPreference::query()->where('scope_key', "user:{$userId}")->where('category', $category)->where('channel', 'email')->first();
        if ($user) {
            return $user->mode;
        }

        return NotificationPreference::query()->where('scope_key', 'tenant')->where('category', $category)->where('channel', 'email')->value('mode') ?? 'immediate';
    }

    private function deepLink(mixed $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }
        $link = (string) $link;
        if (! Str::startsWith($link, '/') || Str::startsWith($link, '//') || preg_match('/[\x00-\x1F\x7F]/', $link)) {
            throw ValidationException::withMessages(['deep_link' => 'Notification deep links must be safe application-relative paths.']);
        }

        return $link;
    }

    private function recordAudit(string $event, array $metadata, InAppNotification $notification, int $actorUserId): void
    {
        try {
            $this->audit->record($event, $metadata, InAppNotification::class, $notification->id);

            return;
        } catch (LogicException) {
            // Domain-event workers intentionally carry tenant context without an authenticated membership.
        }

        AuditEvent::query()->create([
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'subject_type' => InAppNotification::class,
            'subject_id' => $notification->id,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function serialize(InAppNotification $n): array
    {
        return [
            'id' => $n->id,
            'notification_id' => $n->notification_id,
            'category' => $n->category,
            'severity' => $n->severity,
            'source' => $n->source,
            'title' => $n->title,
            'message' => $n->message,
            'deep_link' => $n->deep_link,
            'mandatory' => (bool) $n->mandatory,
            'locale' => $n->locale,
            'delivery_mode' => $n->delivery_mode,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
