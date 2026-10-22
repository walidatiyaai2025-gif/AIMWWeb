<?php

namespace App\Email\Services;

use App\Email\Contracts\NotificationEventSink;
use App\Models\InAppNotification;

final class DomainNotificationBridge
{
    public function __construct(private readonly NotificationEventSink $sink) {}

    public function sync(string $eventId, string $state, array $payload): InAppNotification
    {
        return $this->emit($eventId, "sync.{$state}", 'sync', $payload);
    }

    public function billing(string $eventId, string $event, array $payload): InAppNotification
    {
        return $this->emit($eventId, "billing.{$event}", 'billing', $payload);
    }

    public function operational(string $eventId, string $type, string $source, array $payload): InAppNotification
    {
        return $this->emit($eventId, $type, $source, $payload);
    }

    private function emit(string $eventId, string $type, string $source, array $payload): InAppNotification
    {
        return $this->sink->consume([
            ...$payload,
            'event_id' => $eventId,
            'type' => $type,
            'source' => $source,
        ]);
    }
}
