<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Email\Services\SiteEmailRecipientService;
use App\Email\Services\SiteMailProfileService;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SiteEmailSettingsController extends Controller
{
    public const DIAGNOSTICS_BACK_OPERATION_ID = 'AIMW-EMAI-8E56589573';
    public function module(
        Request $request,
        string $tenant,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SiteEmailRecipientService $recipients,
    ): View {
        $this->authorizeWorkspace($tenant, $authorizer, $context);

        $sites = Site::query()->orderBy('name')->get();
        $selectedSite = null;
        $siteRecipients = collect();
        $requestedSite = $request->integer('site');
        if ($requestedSite > 0) {
            $selectedSite = Site::query()->findOrFail($requestedSite);
            $siteRecipients = $recipients->listAsync((int) $selectedSite->getKey());
        }

        return view('sites.email-settings', [
            'tenant' => $tenant,
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'recipients' => $siteRecipients,
        ]);
    }

    public function site(
        string $tenant,
        int $site,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SiteEmailRecipientService $recipients,
    ): View {
        $this->authorizeWorkspace($tenant, $authorizer, $context);
        $selectedSite = Site::query()->findOrFail($site);

        return view('sites.email-settings', [
            'tenant' => $tenant,
            'sites' => Site::query()->orderBy('name')->get(),
            'selectedSite' => $selectedSite,
            'recipients' => $recipients->listAsync($site),
        ]);
    }

    public function diagnostics(
        string $tenant,
        int $site,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SiteEmailRecipientService $recipients,
        SiteMailProfileService $profiles,
    ): View {
        $this->authorizeWorkspace($tenant, $authorizer, $context);
        $selectedSite = Site::query()->findOrFail($site);
        $profile = $profiles->getAsync($site);
        $siteRecipients = $recipients->listAsync($site);

        return view('sites.email-diagnostics', [
            'tenant' => $tenant,
            'selectedSite' => $selectedSite,
            'profile' => $profile,
            'suggestedRecipient' => $siteRecipients->firstWhere('is_enabled', true),
            'backOperationId' => self::DIAGNOSTICS_BACK_OPERATION_ID,
        ]);
    }

    public function store(
        Request $request,
        string $tenant,
        int $site,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SiteEmailRecipientService $recipients,
    ): RedirectResponse {
        $this->authorizeWorkspace($tenant, $authorizer, $context);
        Site::query()->findOrFail($site);
        $validated = $request->validate([
            'email_address' => ['required', 'string', 'max:320'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ]);

        $recipients->addAsync(
            $site,
            (string) $validated['email_address'],
            isset($validated['display_name']) ? (string) $validated['display_name'] : null,
        );

        return redirect()
            ->route('canonical.workspace.site-email-settings.site', ['tenant' => $tenant, 'site' => $site])
            ->with('success', 'Recipient added.');
    }

    private function authorizeWorkspace(
        string $tenant,
        TenantAuthorizer $authorizer,
        TenantContext $context,
    ): void {
        $authorizer->authorize('tenant.view');
        $authorizer->authorize('sites.view');
        $authorizer->authorize('settings.manage');
        abort_unless($context->tenant()->slug === $tenant, 404);
    }
}
