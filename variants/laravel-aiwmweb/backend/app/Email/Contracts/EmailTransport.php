<?php

namespace App\Email\Contracts;

use App\Models\MailConfiguration;

interface EmailTransport
{
    /** @return array{provider_message_id:?string} */
    public function send(MailConfiguration $configuration, ?string $secret, array $message): array;

    /** @return array{ok:bool,message:string} */
    public function diagnose(MailConfiguration $configuration, ?string $secret): array;
}
