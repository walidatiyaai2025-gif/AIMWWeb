<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Connector\WordPressApplicationPasswordVerifier;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

final class SiteCredentialController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $tenantContext,
        private readonly WordPressApplicationPasswordVerifier $verifier,
    ) {}

    public function store(Request $request, string $tenant, int $site): RedirectResponse
    {
        $activeTenant = $this->tenantContext->tenant();
        abort_unless(hash_equals($activeTenant->slug, $tenant), 404);

        $this->authorizer->authorize('sites.manage');

        $siteModel = Site::withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->whereKey($site)
            ->firstOrFail();

        $unexpected = array_values(array_diff($request->keys(), ['_token', 'username', 'application_password']));
        if ($unexpected !== []) {
            return back()->withErrors(['request' => 'Credential save does not accept caller-supplied tenant, site, URL, credential, or status identifiers.'])
                ->withInput($request->except('application_password'));
        }

        $validator = Validator::make($request->only(['username', 'application_password']), [
            'username' => ['required', 'string', 'max:255'],
            'application_password' => ['required', 'string', 'min:8', 'max:1024'],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except('application_password'));
        }

        $data = $validator->validated();
        $username = trim($data['username']);
        $applicationPassword = $data['application_password'];
        if ($username === '') {
            return back()->withErrors(['username' => 'The username field is required.']);
        }

        try {
            $verification = $this->verifier->verify($siteModel, $username, $applicationPassword);
        } catch (Throwable) {
            return back()->withErrors(['application_password' => 'WordPress connection test failed and the new credential was not saved.'])
                ->withInput(['username' => $username]);
        }

        $credentialId = DB::transaction(function () use ($activeTenant, $siteModel, $username, $applicationPassword): int {
            $credential = SiteCredential::withoutGlobalScopes()
                ->where('tenant_id', $activeTenant->getKey())
                ->where('site_id', $siteModel->getKey())
                ->lockForUpdate()
                ->first();

            if ($credential === null) {
                $credential = new SiteCredential;
                $credential->tenant_id = $activeTenant->getKey();
                $credential->site_id = $siteModel->getKey();
            }

            $credential->username = $username;
            $credential->secret_value = $applicationPassword;
            $credential->save();

            return (int) $credential->getKey();
        }, 3);

        $authoritativeCredential = SiteCredential::withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->where('site_id', $siteModel->getKey())
            ->whereKey($credentialId)
            ->firstOrFail();

        if (! hash_equals($username, (string) $authoritativeCredential->username)
            || ! hash_equals($applicationPassword, (string) $authoritativeCredential->secret_value)) {
            throw new RuntimeException('Credential persistence could not be reconciled.');
        }

        $status = ($verification['limited_permissions'] ?? false)
            ? 'Credential saved. WordPress connection succeeded with limited permissions.'
            : 'Credential saved and WordPress connection verified.';

        return redirect()
            ->route('canonical.site.settings', ['tenant' => $activeTenant->slug, 'site' => $siteModel->getKey()])
            ->with('status', $status);
    }

    public function destroy(string $tenant, int $site): RedirectResponse
    {
        $activeTenant = $this->tenantContext->tenant();
        abort_unless(hash_equals($activeTenant->slug, $tenant), 404);

        $this->authorizer->authorize('sites.manage');

        $siteModel = Site::withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->whereKey($site)
            ->firstOrFail();

        DB::transaction(function () use ($activeTenant, $siteModel): void {
            $credential = SiteCredential::withoutGlobalScopes()
                ->where('tenant_id', $activeTenant->getKey())
                ->where('site_id', $siteModel->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $credential->delete();

            $stillExists = SiteCredential::withoutGlobalScopes()
                ->where('tenant_id', $activeTenant->getKey())
                ->where('site_id', $siteModel->getKey())
                ->exists();

            if ($stillExists) {
                throw new RuntimeException('Credential removal could not be verified.');
            }
        });

        $authoritativeCredential = SiteCredential::withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->where('site_id', $siteModel->getKey())
            ->first();

        if ($authoritativeCredential !== null) {
            throw new RuntimeException('Credential removal could not be reconciled.');
        }

        return redirect()
            ->route('canonical.site.settings', ['tenant' => $activeTenant->slug, 'site' => $siteModel->getKey()])
            ->with('status', 'Encrypted credential removed.');
    }
}
