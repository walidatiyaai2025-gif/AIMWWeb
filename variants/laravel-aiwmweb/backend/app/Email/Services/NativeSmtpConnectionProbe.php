<?php

namespace App\Email\Services;

use App\Email\Contracts\SmtpConnectionProbe;

final class NativeSmtpConnectionProbe implements SmtpConnectionProbe
{
    public function diagnose(string $host, int $port): array
    {
        $host = trim($host);
        if ($host === '') {
            return [
                'dns_ok' => false,
                'dns_message' => 'SMTP host is empty.',
                'tcp_ok' => false,
                'tcp_message' => 'TCP probe was skipped because DNS resolution failed.',
            ];
        }

        $dnsOk = $this->resolves($host);
        $dnsMessage = $dnsOk
            ? "DNS resolved {$host}."
            : "DNS could not resolve {$host}.";

        if (! $dnsOk) {
            return [
                'dns_ok' => false,
                'dns_message' => $dnsMessage,
                'tcp_ok' => false,
                'tcp_message' => 'TCP probe was skipped because DNS resolution failed.',
            ];
        }

        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $error,
            5,
            STREAM_CLIENT_CONNECT,
        );

        if (is_resource($socket)) {
            fclose($socket);

            return [
                'dns_ok' => true,
                'dns_message' => $dnsMessage,
                'tcp_ok' => true,
                'tcp_message' => "TCP connection to {$host}:{$port} succeeded.",
            ];
        }

        return [
            'dns_ok' => true,
            'dns_message' => $dnsMessage,
            'tcp_ok' => false,
            'tcp_message' => "TCP connection to {$host}:{$port} failed.",
        ];
    }

    private function resolves(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records) && $records !== []) {
                return true;
            }
        }

        $resolved = @gethostbyname($host);

        return $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false;
    }
}
