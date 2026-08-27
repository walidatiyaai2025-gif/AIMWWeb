<?php

namespace App\Content\Remote;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NativeWordPressRestPath
{
    public function available(int $siteId): bool
    {
        $site = $this->site($siteId, false);
        return $site && filled($site->url ?? null) && filled($site->rest_username ?? null) && filled($site->rest_application_password ?? null);
    }

    public function list(int $siteId, string $resource, array $query = []): array
    {
        return $this->request($siteId)->get($this->url($siteId, $this->endpoint($resource)), ['context'=>'edit'] + $query)->throw()->json() ?? [];
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        return $this->request($siteId)->get($this->url($siteId, $this->endpoint($resource).'/'.$remoteId), ['context'=>'edit'] + $query)->throw()->json() ?? [];
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        $endpoint = $this->endpoint($resource).($remoteId ? '/'.$remoteId : '');
        if ($action === 'delete') return $this->request($siteId)->delete($this->url($siteId, $endpoint), ['force'=>true])->throw()->json() ?? [];
        if ($action === 'trash') return $this->request($siteId)->delete($this->url($siteId, $endpoint), ['force'=>false])->throw()->json() ?? [];
        if ($resource === 'comments') {
            $payload['status'] = match ($action) { 'approve'=>'approved','unapprove'=>'hold','spam'=>'spam','unspam'=>'hold','restore'=>'approved', default=>$payload['status'] ?? null };
            $payload = array_filter($payload, fn ($v) => $v !== null);
        }
        if ($action === 'restore' && in_array($resource, ['posts','pages'], true)) $payload['status'] = $payload['status'] ?? 'draft';
        return $this->request($siteId)->post($this->url($siteId, $endpoint), $payload)->throw()->json() ?? [];
    }

    public function upload(int $siteId, string $path, string $name, string $mimeType, array $metadata = []): array
    {
        $response = $this->request($siteId)->attach('file', fopen($path, 'r'), $name, ['Content-Type'=>$mimeType])->post($this->url($siteId, '/wp-json/wp/v2/media'), $metadata)->throw();
        return $response->json() ?? [];
    }

    private function request(int $siteId): PendingRequest
    {
        $site = $this->site($siteId);
        return Http::timeout(45)->retry(2, 250)->acceptJson()->withBasicAuth((string) $site->rest_username, (string) $site->rest_application_password);
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
            if ($fail) throw new RuntimeException('Site integration is not available until the Laravel site connector is integrated.');
            return null;
        }
        $site = $class::query()->find($siteId);
        if (! $site && $fail) throw new RuntimeException('Site not found.');
        return $site;
    }
}
