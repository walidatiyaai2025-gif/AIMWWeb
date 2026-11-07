<?php

namespace App\Content\Remote;

use App\Models\SiteCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
        // Never retry WordPress 4xx responses: an out-of-range page is an
        // expected terminal response, not a transient provider failure.
        $response = $this->requestWithoutRetry($siteId)
            ->retry(2, 250, static function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->status() >= 500);
            }, false)
            ->get($this->url($siteId, $this->endpoint($resource)), ['context' => 'edit'] + $query);

        // WordPress native REST rejects a page beyond the final full page
        // with a specific 400 code; that one documented condition is an
        // authoritative end-of-pagination, not a generic upstream failure.
        if ((int) ($query['page'] ?? 1) > 1 && $response->status() === 400
            && in_array($response->json('code'), [
                'rest_post_invalid_page_number',
                'rest_term_invalid_page_number',
                'rest_comment_invalid_page_number',
                'rest_user_invalid_page_number',
            ], true)) {
            return [];
        }

        $rows = $response->throw()->json();
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('WordPress REST resource list returned an invalid JSON collection.');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row)
                || ! isset($row['id']) || ! ctype_digit((string) $row['id'])
                || (int) $row['id'] < 1) {
                throw new RuntimeException('WordPress REST resource list returned a malformed record.');
            }
        }

        return $rows;
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        $row = $this->request($siteId)->get($this->url($siteId, $this->endpoint($resource).'/'.$remoteId), ['context' => 'edit'] + $query)->throw()->json();
        if (! is_array($row) || array_is_list($row)
            || (int) ($row['id'] ?? 0) !== $remoteId) {
            throw new RuntimeException('WordPress REST resource read returned an incorrect record.');
        }

        return $row;
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

    /**
     * Execute a non-idempotent WordPress mutation exactly once.
     *
     * Callers must reconcile with an authoritative read before surfacing success.
     */
    public function mutateOnce(int $siteId, string $resource, ?int $remoteId, array $payload = []): array
    {
        $endpoint = $this->endpoint($resource).($remoteId ? '/'.$remoteId : '');

        return $this->requestWithoutRetry($siteId)
            ->post($this->url($siteId, $endpoint), $payload)
            ->throw()
            ->json() ?? [];
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
        } catch (Throwable $error) {
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
        // Verification GETs may be retried because they are read-only, but the
        // final response must remain inspectable: WordPress 404 is authoritative
        // absence, not an exception that should escape the reconciliation path.
        $response = $this->requestWithoutRetry($siteId)
            ->retry(2, 250, null, false)
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
            'posts','pages','media','comments','categories','tags','users' => '/wp-json/wp/v2/'.$resource,
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
