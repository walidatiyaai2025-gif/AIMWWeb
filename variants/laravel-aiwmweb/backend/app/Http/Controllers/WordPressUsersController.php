<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Content\Remote\NativeWordPressRestPath;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class WordPressUsersController extends Controller
{
    public const SAVE_OPERATION_ID = 'AIMW-BILL-D307963B59';

    public function __construct(private readonly NativeWordPressRestPath $wordpress) {}

    public function index(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        Site::query()->findOrFail($site);

        $query = [
            'page' => max(1, $request->integer('page', 1)),
            'per_page' => min(max($request->integer('per_page', 50), 1), 100),
            'context' => 'edit',
            'orderby' => 'registered_date',
            'order' => 'desc',
        ];

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query['search'] = $search;
        }

        $role = trim((string) $request->query('role', 'all'));
        if ($role !== '' && $role !== 'all') {
            $query['roles'] = $role;
        }

        $items = $this->wordpress->list($site, 'users', $query);

        return response()->json([
            'data' => array_values($items),
            'total' => count($items),
            'current_page' => (int) $query['page'],
            'last_page' => count($items) < (int) $query['per_page'] ? (int) $query['page'] : (int) $query['page'] + 1,
        ]);
    }

    public function save(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        Site::query()->findOrFail($site);

        $callerOwned = ['tenant', 'tenant_id', 'site', 'site_id', 'actor_user_id', 'owner_user_id'];
        abort_if(array_intersect(array_keys($request->all()), $callerOwned) !== [], 422, 'WordPress user save does not accept caller-owned tenant identity fields.');

        $data = $request->validate([
            'wordpress_user_id' => 'nullable|integer|min:1',
            'username' => 'nullable|string|max:60',
            'email' => 'required|email|max:254',
            'password' => 'nullable|string|min:6|max:4096',
            'name' => 'nullable|string|max:250',
            'first_name' => 'nullable|string|max:250',
            'last_name' => 'nullable|string|max:250',
            'url' => 'nullable|url|max:2000',
            'description' => 'nullable|string|max:5000',
            'role' => ['nullable', Rule::in(['administrator', 'editor', 'author', 'contributor', 'subscriber'])],
        ]);

        $remoteId = isset($data['wordpress_user_id']) ? (int) $data['wordpress_user_id'] : null;
        $creating = $remoteId === null;

        if ($creating) {
            abort_if(trim((string) ($data['username'] ?? '')) === '', 422, 'Username is required when creating a WordPress user.');
            abort_if(trim((string) ($data['password'] ?? '')) === '', 422, 'Password is required when creating a WordPress user.');
        } else {
            // The selected remote id must exist in this exact tenant-owned site before mutation.
            $baseline = $this->wordpress->get($site, 'users', $remoteId);
            abort_unless((int) ($baseline['id'] ?? 0) === $remoteId, 404);
        }

        $payload = [
            'email' => trim((string) $data['email']),
            'name' => trim((string) ($data['name'] ?? '')),
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'url' => trim((string) ($data['url'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'roles' => [(string) ($data['role'] ?? 'subscriber')],
        ];

        if ($creating) {
            $payload['username'] = trim((string) $data['username']);
            $payload['password'] = (string) $data['password'];
        } elseif (filled($data['password'] ?? null)) {
            $payload['password'] = (string) $data['password'];
        }

        $accepted = $this->wordpress->mutate($site, 'users', $remoteId, $creating ? 'create' : 'update', $payload);
        $savedId = (int) ($accepted['id'] ?? $remoteId ?? 0);
        abort_if($savedId <= 0, 502, 'WordPress accepted the request without a verifiable user id.');

        // Authoritative reconciliation: success is not surfaced until WordPress can be re-read.
        $verified = $this->wordpress->get($site, 'users', $savedId);
        abort_unless((int) ($verified['id'] ?? 0) === $savedId, 502, 'WordPress user could not be reconciled after save.');

        return response()->json([
            'operation_id' => self::SAVE_OPERATION_ID,
            'mode' => $creating ? 'create' : 'update',
            'message' => $creating ? 'WordPress user created and verified.' : 'WordPress user updated and verified.',
            'data' => $verified,
            'reconciled' => true,
        ], $creating ? 201 : 200);
    }
}
