<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Email\Services\SiteEmailRecipientService;
use App\Email\Services\SiteMailProfileService;
use App\Email\Services\SmtpDiagnosticsService;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SiteEmailSettingsController extends Controller
{
    public const DIAGNOSTICS_BACK_OPERATION_ID = 'AIMW-EMAI-8E56589573';

    public const SAVE_MAIL_PROFILE_OPERATION_ID = 'AIMW-BILL-609F15BFCA';

    public const SEND_TEST_OPERATION_ID = 'AIMW-BILL-BC093D6418';

    public const DIAGNOSE_OPERATION_ID = 'AIMW-BILL-EE42461195';

    public function module(
        Request $request,
        string $tenant,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SiteEmailRecipientService $recipients,
        SiteMailProfileService $profiles,
    ): View {
        $this->authorizeWorkspace($tenant, $authorizer, $context);

        $sites = Site::query()->orderBy('name')->get();
        $selectedSite = null;
        $siteRecipients = collect();
        $mailProfile = null;
        $requestedSite = $request->integer('site');
        if ($requestedSite > 0) {
            $selectedSite = Site::query()->findOrFail($requestedSite);
            $siteRecipients = $recipients->listAsync((int) $selectedSite->getKey());
            $mailProfile = $profiles->getAsync((int) $selectedSite->getKey());
        }

        return view('sites.email-settings', [
            'tenant' => $tenant,
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'recipients' => $siteRecipients,
            'mailProfile' => $mailProfile,
        ]);
    }

    public function site(
        string $tenant,
        int $site,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SiteEmailRecipientService $recipients,
        SiteMailProfileService $profiles,
    ): View {
        $this->authorizeWorkspace($tenant, $authorizer, $context);
        $selectedSite = Site::query()->findOrFail($site);

        return view('sites.email-settings', [
            'tenant' => $tenant,
            'sites' => Site::query()->orderBy('name')->get(),
            'selectedSite' => $selectedSite,
            'recipients' => $recipients->listAsync($site),
            'mailProfile' => $profiles->getAsync($site),
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
            'sendTestOperationId' => self::SEND_TEST_OPERATION_ID,
            'diagnoseOperationId' => self::DIAGNOSE_OPERATION_ID,
        ]);
    }

    public function diagnoseConnection(
        string $tenant,
        int $site,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SmtpDiagnosticsService $diagnostics,
    ): RedirectResponse {
        $this->authorizeWorkspace($tenant, $authorizer, $context);
        Site::query()->findOrFail($site);

        $result = $diagnostics->diagnose($site);

        return redirect()
            ->route('canonical.workspace.site-email-diagnostics', ['tenant' => $tenant, 'site' => $site])
            ->with('smtp_diagnostic_result', $result);
    }

    public function sendTest(
        Request $request,
        string $tenant,
        int $site,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SmtpDiagnosticsService $diagnostics,
    ): RedirectResponse {
        $this->authorizeWorkspace($tenant, $authorizer, $context);
        Site::query()->findOrFail($site);

        $validated = $request->validate([
            'recipient' => ['required', 'email', 'max:320'],
        ]);

        $result = $diagnostics->sendTest($site, (string) $validated['recipient']);

        return redirect()
            ->route('canonical.workspace.site-email-diagnostics', ['tenant' => $tenant, 'site' => $site])
            ->withInput()
            ->with('smtp_diagnostic_result', $result);
    }

    public function saveMailProfile(
        Request $request,
        string $tenant,
        int $site,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SiteMailProfileService $profiles,
    ): RedirectResponse {
        $this->authorizeWorkspace($tenant, $authorizer, $context);
        Site::query()->findOrFail($site);

        $validated = $request->validate([
            'use_account_profile' => ['required', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:320'],
            'password' => ['nullable', 'string', 'max:2048'],
            'from_address' => ['nullable', 'email', 'max:320'],
            'from_name' => ['nullable', 'string', 'max:160'],
            'reply_to' => ['nullable', 'email', 'max:320'],
            'enable_ssl' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
        ]);

        $profiles->saveAsync($site, [
            'use_account_profile' => (bool) $validated['use_account_profile'],
            'host' => (string) ($validated['host'] ?? ''),
            'port' => (int) ($validated['port'] ?? 587),
            'username' => (string) ($validated['username'] ?? ''),
            'password' => (string) ($validated['password'] ?? ''),
            'from_address' => (string) ($validated['from_address'] ?? ''),
            'from_name' => (string) ($validated['from_name'] ?? ''),
            'reply_to' => (string) ($validated['reply_to'] ?? ''),
            'enable_ssl' => (bool) $validated['enable_ssl'],
            'enabled' => (bool) $validated['enabled'],
        ]);

        return redirect()
            ->route('canonical.workspace.site-email-settings.site', ['tenant' => $tenant, 'site' => $site])
            ->with('success', 'Mail profile saved securely.');
    }

    public function clearMailPassword(
        string $tenant,
        int $site,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SiteMailProfileService $profiles,
    ): RedirectResponse {
        $this->authorizeWorkspace($tenant, $authorizer, $context);
        Site::query()->findOrFail($site);
        $profiles->clearPasswordAsync($site);

        return redirect()
            ->route('canonical.workspace.site-email-settings.site', ['tenant' => $tenant, 'site' => $site])
            ->with('success', 'Saved SMTP password cleared.');
    }

    public function saveEdit(
        Request $request,
        string $tenant,
        int $site,
        int $recipient,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SiteEmailRecipientService $recipients,
    ): RedirectResponse {
        $this->authorizeWorkspace($tenant, $authorizer, $context);
        Site::query()->findOrFail($site);

        $validated = $request->validate([
            'email_address' => ['required', 'email', 'max:320'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $recipients->updateAsync(
            $site,
            $recipient,
            (string) $validated['email_address'],
            isset($validated['display_name']) ? (string) $validated['display_name'] : null,
            (bool) $validated['is_enabled'],
        );

        return redirect()
            ->route('canonical.workspace.site-email-settings.site', ['tenant' => $tenant, 'site' => $site])
            ->with('success', 'Email recipient updated.');
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
