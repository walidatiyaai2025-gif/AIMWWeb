<?php

namespace App\Content\Remote;

use App\Models\SiteCredential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NativeWordPressRestPath
{
    public function available(int $siteId): bool
    {
        $site = $this->site($siteId, false);
        if (! $site || blank($site->url ?? null)) {
            return false;
        }

        return $this->credential($siteId, false) !== null;
    }

    public function list(int $siteId, string $resource, array $query = []): array
    {
        return $this->request($siteId)->get($this->url($siteId, $this->endpoint($resource)), ['context' => 'edit'] + $query)->throw()->json() ?? [];
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        return $this->request($siteId)->get($this->url($siteId, $this->endpoint($resource).'/'.$remoteId), ['context' => 'edit'] + $query)->throw()->json() ?? [];
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        $endpoint = $this->endpoint($resource).($remoteId ? '/'.$remoteId : '');
        if ($action === 'delete') {
            return $this->request($siteId)->delete($this->url($siteId, $endpoint), ['force' => true])->throw()->json() ?? [];
        }
        if ($action === 'trash') {
            return $this->request($siteId)->delete($this->url($siteId, $endpoint), ['force' => false])->throw()->json() ?? [];
        }
        if ($resource === 'comments') {
            $payload['status'] = match ($action) {
                'approve' => 'approved','unapprove' => 'hold','spam' => 'spam','unspam' => 'hold','restore' => 'approved', default => $payload['status'] ?? null
            };
            $payload = array_filter($payload, fn ($v) => $v !== null);
        }
        if ($action === 'restore' && in_array($resource, ['posts', 'pages'], true)) {
            $payload['status'] = $payload['status'] ?? 'draft';
        }

        return $this->request($siteId)->post($this->url($siteId, $endpoint), $payload)->throw()->json() ?? [];
    }

    public function deletePermanently(int $siteId, int $remoteId): array
    {
        $endpoint = $this->endpoint('media').'/'.$remoteId;
        $response = null;

        try {
            // Destructive requests are not blindly retried. A lost response is
            // reconciled by the authoritative GET below before any local delete.
            $response = $this->requestWithoutRetry($siteId)
                ->delete($this->url($siteId, $endpoint), ['force' => true]);

            if ($response->status() !== 404) {
                $response->throw();
            }
        } catch (\Throwable $error) {
            if ($this->exists($siteId, 'media', $remoteId)) {
                throw $error;
            }
        }

        if ($this->exists($siteId, 'media', $remoteId)) {
            throw new RuntimeException('WordPress media still exists after permanent deletion.');
        }

        $payload = $response?->json();

        return is_array($payload) ? $payload : [];
    }

    public function exists(int $siteId, string $resource, int $remoteId): bool
    {
        $response = $this->request($siteId)
            ->get($this->url($siteId, $this->endpoint($resource).'/'.$remoteId), ['context' => 'edit']);

        if ($response->status() === 404) {
            return false;
        }

        $response->throw();

        return (int) data_get($response->json(), 'id', 0) === $remoteId;
    }

    public function upload(int $siteId, string $path, string $name, string $mimeType, array $metadata = []): array
    {
        $response = $this->request($siteId)->attach('file', fopen($path, 'r'), $name, ['Content-Type' => $mimeType])->post($this->url($siteId, '/wp-json/wp/v2/media'), $metadata)->throw();

        return $response->json() ?? [];
    }

    private function request(int $siteId): PendingRequest
    {
        return $this->requestWithoutRetry($siteId)->retry(2, 250);
    }

    private function requestWithoutRetry(int $siteId): PendingRequest
    {
        $credential = $this->credential($siteId);

        return Http::timeout(45)
            ->acceptJson()
            ->withBasicAuth((string) $credential->username, (string) $credential->secret_value);
    }

    private function credential(int $siteId, bool $fail = true): ?SiteCredential
    {
        $credential = SiteCredential::query()->where('site_id', $siteId)->first();
        if (! $credential && $fail) {
            throw new RuntimeException('WordPress application-password credential is not configured.');
        }

        return $credential;
    }

    private function url(int $siteId, string $path): string
    {
        $site = $this->site($siteId);

        return rtrim((string) $site->url, '/').'/'.ltrim($path, '/');
    }

    private function endpoint(string $resource): string
    {
        return match ($resource) {
            'posts','pages','media','comments','categories','tags' => '/wp-json/wp/v2/'.$resource,
            default => throw new RuntimeException("WordPress REST resource '{$resource}' is not directly supported."),
        };
    }

    private function site(int $siteId, bool $fail = true): ?object
    {
        $class = 'App\\Models\\Site';
        if (! class_exists($class)) {
            if ($fail) {
                throw new RuntimeException('Site integration is not available until the Laravel site connector is integrated.');
            }

            return null;
        }
        $site = $class::query()->find($siteId);
        if (! $site && $fail) {
            throw new RuntimeException('Site not found.');
        }

        return $site;
    }
}
