<?php

namespace App\Email\Contracts;

interface SmtpConnectionProbe
{
    /**
     * @return array{
     *   dns_ok: bool,
     *   dns_message: string,
     *   tcp_ok: bool,
     *   tcp_message: string
     * }
     */
    public function diagnose(string $host, int $port): array;
}
