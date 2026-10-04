<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Connector\WordPressApplicationPasswordVerifier;
use App\Jobs\SyncSiteJob;
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
            'url' => ['required', 'url', 'max:2048'],
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
            $this->assertSameRequest($existing, $requestHash);
            $siteId = (int) (($existing->response ?? [])['site_id'] ?? 0);
            $runId = (int) (($existing->response ?? [])['run_id'] ?? 0);
            abort_unless($existing->completed_at && $siteId > 0 && $runId > 0, 409, 'Onboarding request is already in progress.');

            return response()->json($this->authoritativeResponse($context->id(), $siteId, $runId, true));
        }

        try {
            $entitlements->assertCanCreate();
        } catch (RuntimeException $exception) {
            return response()->json(['state' => 'CAPABILITY_DISABLED', 'message' => $exception->getMessage()], 403);
        }

        $probe = new Site;
        $probe->url = $url;
        try {
            $verification = $verifier->verify($probe, $username, $password);
        } catch (Throwable) {
            return response()->json([
                'message' => 'WordPress connection test failed. No site or credential was created.',
                'errors' => ['application_password' => ['WordPress connection test failed.']],
            ], 422);
        }

        $created = DB::transaction(function () use (
            $context,
            $key,
            $requestHash,
            $name,
            $url,
            $username,
            $password,
            $verification,
        ): array {
            $receipt = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->first();
            if ($receipt !== null) {
                $this->assertSameRequest($receipt, $requestHash);
                $siteId = (int) (($receipt->response ?? [])['site_id'] ?? 0);
                $runId = (int) (($receipt->response ?? [])['run_id'] ?? 0);
                abort_unless($receipt->completed_at && $siteId > 0 && $runId > 0, 409, 'Onboarding request is already in progress.');

                return ['site_id' => $siteId, 'run_id' => $runId, 'replay' => true];
            }

            $receipt = IdempotencyKey::query()->create([
                'key' => $key,
                'operation' => self::OPERATION_ID,
                'request_hash' => $requestHash,
            ]);

            $site = Site::query()->create([
                'name' => $name,
                'url' => $url,
                'status' => 'active',
                'connection_status' => 'verified',
                'health_state' => ($verification['limited_permissions'] ?? false) ? 'limited' : 'healthy',
                'last_verified_at' => now(),
            ]);

            $credential = new SiteCredential;
            $credential->tenant_id = $context->id();
            $credential->site_id = $site->getKey();
            $credential->username = $username;
            $credential->secret_value = $password;
            $credential->save();

            $run = SyncRun::query()->create(['site_id' => $site->getKey()]);

            $receipt->response = [
                'site_id' => (int) $site->getKey(),
                'run_id' => (int) $run->getKey(),
            ];
            $receipt->completed_at = now();
            $receipt->save();

            return ['site_id' => (int) $site->getKey(), 'run_id' => (int) $run->getKey(), 'replay' => false];
        }, 3);

        if (! $created['replay']) {
            try {
                SyncSiteJob::dispatch($context->id(), $created['site_id'], $created['run_id']);
            } catch (Throwable $exception) {
                SyncRun::query()->whereKey($created['run_id'])->update([
                    'status' => 'failed',
                    'failure' => 'Queue dispatch failed before initial synchronization could start.',
                    'completed_at' => now(),
                ]);
                throw $exception;
            }
        }

        return response()->json(
            $this->authoritativeResponse($context->id(), $created['site_id'], $created['run_id'], (bool) $created['replay']),
            $created['replay'] ? 200 : 202,
        );
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
