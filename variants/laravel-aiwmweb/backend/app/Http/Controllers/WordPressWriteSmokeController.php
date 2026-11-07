<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Diagnostics\WordPressWriteSmokeService;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class WordPressWriteSmokeController extends Controller
{
    /**
     * High-risk live WordPress write probe, never invoked by a GET/Inspector scan.
     * The browser cannot choose a remote object ID or write payload.
     */
    public function __invoke(
        string $tenant,
        Request $request,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        WordPressWriteSmokeService $smoke,
    ): JsonResponse {
        abort_unless(hash_equals((string) $context->tenant()->slug, $tenant), 404);
        $authorizer->authorize('diagnostics.view');
        $authorizer->authorize('content.edit');
        $authorizer->authorize('content.manage');

        $data = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'resource' => ['required', 'in:posts,pages,categories,tags,comments,media'],
            'confirmation' => ['required', 'in:RUN_DISPOSABLE_WORDPRESS_TEST'],
            'tenant_id' => ['prohibited'],
            'remote_id' => ['prohibited'],
            'payload' => ['prohibited'],
        ]);

        $site = Site::query()
            ->where('tenant_id', $context->id())
            ->findOrFail((int) $data['site_id']);

        $credential = SiteCredential::query()
            ->where('site_id', $site->id)
            ->first();

        if ($credential === null) {
            return response()->json([
                'schema' => 'aiwm.wordpress-write-smoke.v1',
                'transport' => 'native_wordpress_rest',
                'resource' => $data['resource'],
                'state' => 'not_tested',
                'reason' => 'application_password_not_configured',
                'create_verified' => false,
                'save_verified' => false,
                'delete_verified' => false,
                'cleanup_required' => false,
                'recovery' => null,
            ], 200)->header('Cache-Control', 'no-store, private');
        }

        // The comments canary performs up to ten serial REST operations,
        // each with a 12-second timeout. A 120s lease may expire before the
        // verification/cleanup loop finishes and let another write probe in.
        // Allow generous headroom for PHP/DB overhead while retaining a finite
        // stale-lock recovery bound if the worker is terminated unexpectedly.
        $lock = Cache::lock('wp-smoke:tenant:'.$context->id().':site:'.$site->id, 300);
        if (! $lock->get()) {
            return response()->json([
                'schema' => 'aiwm.wordpress-write-smoke.v1',
                'transport' => 'native_wordpress_rest',
                'resource' => $data['resource'],
                'state' => 'not_tested',
                'reason' => 'another_write_probe_is_active',
                'create_verified' => false,
                'save_verified' => false,
                'delete_verified' => false,
                'cleanup_required' => false,
                'recovery' => null,
            ], 409)->header('Cache-Control', 'no-store, private');
        }

        try {
            return response()->json($smoke->run($site, $credential, $data['resource']))
                ->header('Cache-Control', 'no-store, private');
        } finally {
            $lock->release();
        }
    }
}
