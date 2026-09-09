<?php

namespace App\Email\Contracts;

use App\Models\InAppNotification;

interface NotificationEventSink
{
    public function consume(array $event): InAppNotification;
}
