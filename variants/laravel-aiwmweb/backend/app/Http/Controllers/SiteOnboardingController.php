<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Connector\WordPressApplicationPasswordVerifier;
use App\Jobs\SyncSiteJob;
use App\Models\AuditEvent;
use App\Models\IdempotencyKey;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SyncRun;
use App\Sites\SiteEntitlementHook;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class SiteOnboardingController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-2EF6B8A27A';

    public function store(
        Request $request,
        string $tenant,
        TenantAuthorizer $auth,
        TenantContext $context,
        SiteEntitlementHook $entitlements,
        WordPressApplicationPasswordVerifier $verifier,
    ): JsonResponse {
        $auth->authorize('sites.manage');
        abort_unless(hash_equals((string) $context->tenant()->slug, $tenant), 404);

        $unexpected = array_values(array_diff(
            $request->keys(),
            ['name', 'url', 'username', 'application_password'],
        ));
        abort_if($unexpected !== [], 422, 'Onboarding does not accept caller-owned tenant, site, status, or actor identifiers.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:http,https', 'max:2048'],
            'username' => ['required', 'string', 'max:255'],
            'application_password' => ['required', 'string', 'min:8', 'max:1024'],
        ]);

        $name = trim((string) $data['name']);
        $url = rtrim(trim((string) $data['url']), '/');
        $username = trim((string) $data['username']);
        $password = (string) $data['application_password'];
        abort_if($name === '' || $username === '', 422, 'Site name and WordPress username are required.');

        $key = trim((string) $request->header('Idempotency-Key', ''));
        abort_if($key === '' || strlen($key) > 128, 422, 'A bounded Idempotency-Key is required.');

        $requestHash = hash('sha256', json_encode([
            'tenant_id' => $context->id(),
            'operation' => self::OPERATION_ID,
            'name' => $name,
            'url' => $url,
            'username' => $username,
            'password_hash' => hash('sha256', $password),
        ], JSON_THROW_ON_ERROR));

        $existing = IdempotencyKey::query()->where('key', $key)->first();
        if ($existing !== null) {
            return $this->replay($existing, $requestHash, $context->id());
        }

        $existingSite = Site::query()->where('url', $url)->first();

        if ($existingSite === null) {
            try {
                $entitlements->assertCanCreate();
            } catch (RuntimeException $exception) {
                return response()->json(['state' => 'CAPABILITY_DISABLED', 'message' => $exception->getMessage()], 403);
            }
        }

        $persisted = DB::transaction(function () use (
            $request,
            $key,
            $requestHash,
            $existingSite,
            $name,
            $url,
            $username,
            $password,
        ): array {
            $receipt = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->first();
            if ($receipt !== null) {
                $this->assertSameRequest($receipt, $requestHash);
                abort_unless($receipt->completed_at, 409, 'Onboarding request is already in progress.');

                return [
                    'replay' => true,
                    'site_id' => (int) (($receipt->response ?? [])['site_id'] ?? 0),
                    'run_id' => (int) (($receipt->response ?? [])['run_id'] ?? 0),
                    'result' => (string) (($receipt->response ?? [])['result'] ?? ''),
                ];
            }

            $receipt = IdempotencyKey::query()->create([
                'key' => $key,
                'operation' => self::OPERATION_ID,
                'request_hash' => $requestHash,
            ]);

            $site = $existingSite === null
                ? null
                : Site::query()->whereKey($existingSite->getKey())->lockForUpdate()->first();

            if ($site === null) {
                $site = Site::query()->create([
                    'name' => $name,
                    'url' => $url,
                    'status' => 'active',
                    'connection_status' => 'testing',
                    'health_state' => 'unknown',
                ]);
            } else {
                $site->name = $name;
                $site->url = $url;
                $site->connection_status = 'testing';
                $site->health_state = 'unknown';
                $site->save();
            }

            $receipt->response = [
                'site_id' => (int) $site->getKey(),
                'result' => 'profile_saved',
            ];
            $receipt->save();

            return [
                'replay' => false,
                'site_id' => (int) $site->getKey(),
                'receipt_id' => (int) $receipt->getKey(),
            ];
        }, 3);

        if ($persisted['replay']) {
            $receipt = IdempotencyKey::query()->where('key', $key)->firstOrFail();

            return $this->replay($receipt, $requestHash, $context->id());
        }

        $siteId = (int) $persisted['site_id'];
        $receiptId = (int) $persisted['receipt_id'];
        $probe = Site::query()->findOrFail($siteId);

        try {
            $verification = $verifier->verify($probe, $username, $password);
        } catch (Throwable) {
            DB::transaction(function () use ($request, $siteId, $receiptId): void {
                Site::query()->whereKey($siteId)->update([
                    'connection_status' => 'failed',
                    'health_state' => 'unhealthy',
                ]);

                $receipt = IdempotencyKey::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
                $receipt->response = [
                    'site_id' => $siteId,
                    'result' => 'connection_failed',
                ];
                $receipt->completed_at = now();
                $receipt->save();

                AuditEvent::query()->create([
                    'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
                    'event' => 'site_onboarding.connection_failed',
                    'subject_type' => 'site',
                    'subject_id' => (string) $siteId,
                    'metadata' => ['operation_id' => self::OPERATION_ID],
                    'occurred_at' => now(),
                ]);
            }, 3);

            return response()->json(
                $this->failureResponse($context->id(), $siteId, false, 'WordPress connection test failed. Initial synchronization was not started.'),
                422,
            );
        }

        $runId = DB::transaction(function () use ($request, $siteId, $receiptId, $verification, $username, $password): int {
            $credential = SiteCredential::query()
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->first();

            if ($credential === null) {
                $credential = new SiteCredential;
                $credential->site_id = $siteId;
            }

            $credential->username = $username;
            $credential->secret_value = $password;
            $credential->save();

            AuditEvent::query()->create([
                'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
                'event' => 'site_onboarding.credentials_saved',
                'subject_type' => 'site',
                'subject_id' => (string) $siteId,
                'metadata' => ['operation_id' => self::OPERATION_ID],
                'occurred_at' => now(),
            ]);

            Site::query()->whereKey($siteId)->update([
                'connection_status' => 'verified',
                'health_state' => ($verification['limited_permissions'] ?? false) ? 'limited' : 'healthy',
                'last_verified_at' => now(),
            ]);

            $run = SyncRun::query()->create(['site_id' => $siteId]);

            $receipt = IdempotencyKey::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $receipt->response = [
                'site_id' => $siteId,
                'run_id' => (int) $run->getKey(),
                'result' => 'accepted',
            ];
            $receipt->completed_at = now();
            $receipt->save();

            AuditEvent::query()->create([
                'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
                'event' => 'site_onboarding.initial_sync_queued',
                'subject_type' => 'site',
                'subject_id' => (string) $siteId,
                'metadata' => [
                    'operation_id' => self::OPERATION_ID,
                    'sync_run_id' => (int) $run->getKey(),
                    'limited_permissions' => (bool) ($verification['limited_permissions'] ?? false),
                ],
                'occurred_at' => now(),
            ]);

            return (int) $run->getKey();
        }, 3);

        try {
            SyncSiteJob::dispatch($context->id(), $siteId, $runId);
        } catch (Throwable) {
            DB::transaction(function () use ($request, $siteId, $runId, $receiptId): void {
                SyncRun::query()->whereKey($runId)->update([
                    'status' => 'failed',
                    'failure' => 'Queue dispatch failed before initial synchronization could start.',
                    'completed_at' => now(),
                ]);

                $receipt = IdempotencyKey::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
                $receipt->response = [
                    'site_id' => $siteId,
                    'run_id' => $runId,
                    'result' => 'queue_failed',
                ];
                $receipt->save();

                AuditEvent::query()->create([
                    'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
                    'event' => 'site_onboarding.initial_sync_queue_failed',
                    'subject_type' => 'site',
                    'subject_id' => (string) $siteId,
                    'metadata' => [
                        'operation_id' => self::OPERATION_ID,
                        'sync_run_id' => $runId,
                    ],
                    'occurred_at' => now(),
                ]);
            }, 3);

            $payload = $this->authoritativeResponse($context->id(), $siteId, $runId, false);
            $payload['message'] = 'Site and credentials were saved and verified, but initial synchronization could not be queued.';

            return response()->json($payload, 503);
        }

        return response()->json(
            $this->authoritativeResponse($context->id(), $siteId, $runId, false),
            202,
        );
    }

    private function replay(IdempotencyKey $receipt, string $requestHash, int $tenantId): JsonResponse
    {
        $this->assertSameRequest($receipt, $requestHash);
        abort_unless($receipt->completed_at, 409, 'Onboarding request is already in progress.');

        $response = is_array($receipt->response) ? $receipt->response : [];
        $siteId = (int) ($response['site_id'] ?? 0);
        $runId = (int) ($response['run_id'] ?? 0);
        $result = (string) ($response['result'] ?? '');
        abort_unless($siteId > 0, 409, 'Onboarding receipt is incomplete.');

        if ($result === 'connection_failed') {
            return response()->json(
                $this->failureResponse($tenantId, $siteId, true, 'WordPress connection test failed. Initial synchronization was not started.'),
                422,
            );
        }

        abort_unless($runId > 0, 409, 'Onboarding receipt is incomplete.');
        $payload = $this->authoritativeResponse($tenantId, $siteId, $runId, true);

        if ($result === 'queue_failed') {
            $payload['message'] = 'Site and credentials were saved and verified, but initial synchronization could not be queued.';

            return response()->json($payload, 503);
        }

        return response()->json($payload);
    }

    private function assertSameRequest(IdempotencyKey $receipt, string $requestHash): void
    {
        abort_unless(
            $receipt->operation === self::OPERATION_ID && hash_equals((string) $receipt->request_hash, $requestHash),
            409,
            'Idempotency key was already used for a different request.',
        );
    }

    /** @return array<string, mixed> */
    private function failureResponse(int $tenantId, int $siteId, bool $replay, string $message): array
    {
        $site = Site::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($siteId)
            ->firstOrFail();

        return [
            'operation_id' => self::OPERATION_ID,
            'message' => $message,
            'site' => [
                'id' => (int) $site->getKey(),
                'name' => (string) $site->name,
                'url' => (string) $site->url,
                'connection_status' => (string) $site->connection_status,
                'health_state' => (string) $site->health_state,
                'last_verified_at' => $site->last_verified_at?->utc()->toIso8601String(),
            ],
            'credential_configured' => false,
            'sync' => null,
            'idempotent_replay' => $replay,
        ];
    }

    /** @return array<string, mixed> */
    private function authoritativeResponse(int $tenantId, int $siteId, int $runId, bool $replay): array
    {
        $site = Site::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($siteId)
            ->firstOrFail();

        $credentialExists = SiteCredential::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId)
            ->exists();
        abort_unless($credentialExists, 409, 'Credential persistence could not be reconciled.');

        $run = SyncRun::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId)
            ->whereKey($runId)
            ->firstOrFail();

        return [
            'operation_id' => self::OPERATION_ID,
            'site' => [
                'id' => (int) $site->getKey(),
                'name' => (string) $site->name,
                'url' => (string) $site->url,
                'connection_status' => (string) $site->connection_status,
                'health_state' => (string) $site->health_state,
                'last_verified_at' => $site->last_verified_at?->utc()->toIso8601String(),
            ],
            'credential_configured' => true,
            'sync' => [
                'id' => (int) $run->getKey(),
                'status' => (string) $run->status,
                'processed' => (int) ($run->processed ?? 0),
                'failure' => filled($run->failure) ? 'Initial synchronization failed.' : null,
            ],
            'idempotent_replay' => $replay,
        ];
    }
}
