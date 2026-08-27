<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Email\Services\EmailDeliveryService;
use App\Email\Services\EmailTemplateService;
use App\Email\Services\MailConfigurationService;
use App\Email\Services\NotificationPlatformService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailNotificationController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
        private readonly NotificationPlatformService $notifications,
        private readonly MailConfigurationService $configuration,
        private readonly EmailTemplateService $templates,
        private readonly EmailDeliveryService $deliveries,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');
        return response()->json($this->notifications->listForCurrentUser($request->only(['unread', 'severity', 'source', 'per_page'])));
    }

    public function unreadCount(): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');
        return response()->json(['count' => $this->notifications->unreadCount()]);
    }

    public function markRead(int $notification): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');
        return response()->json($this->notifications->markRead($notification));
    }

    public function markAllRead(): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');
        return response()->json(['updated' => $this->notifications->markAllRead()]);
    }

    public function userPreferences(): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');
        return response()->json($this->notifications->preferences($this->context->membership()->user_id));
    }

    public function saveUserPreference(Request $request): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');
        $data = $request->validate(['category' => ['required', 'string', 'max:80'], 'mode' => ['required', 'in:immediate,digest,disabled'], 'locale' => ['nullable', 'in:en,ar']]);
        return response()->json($this->notifications->setPreference($data['category'], $data['mode'], $this->context->membership()->user_id, $data['locale'] ?? null));
    }

    public function tenantPreferences(): JsonResponse
    {
        $this->authorizer->authorize('tenant.manage');
        return response()->json($this->notifications->preferences());
    }

    public function saveTenantPreference(Request $request): JsonResponse
    {
        $this->authorizer->authorize('tenant.manage');
        $data = $request->validate(['category' => ['required', 'string', 'max:80'], 'mode' => ['required', 'in:immediate,digest,disabled'], 'locale' => ['nullable', 'in:en,ar']]);
        return response()->json($this->notifications->setPreference($data['category'], $data['mode'], null, $data['locale'] ?? null));
    }

    public function configuration(Request $request): JsonResponse
    {
        $this->authorizer->authorize('tenant.manage');
        return response()->json($this->configuration->get((string) $request->query('key', 'default')));
    }

    public function saveConfiguration(Request $request): JsonResponse
    {
        $this->authorizer->authorize('tenant.manage');
        $data = $request->validate([
            'configuration_key' => ['nullable', 'string', 'max:160'], 'site_id' => ['nullable', 'integer', 'min:1'], 'transport' => ['nullable', 'string'],
            'host' => ['required', 'string', 'max:255'], 'port' => ['required', 'integer', 'between:1,65535'], 'encryption' => ['nullable', 'in:tls,ssl'],
            'username' => ['nullable', 'string', 'max:255'], 'secret' => ['nullable', 'string', 'max:4000'], 'clear_secret' => ['nullable', 'boolean'],
            'from_address' => ['required', 'email:rfc', 'max:255'], 'from_name' => ['required', 'string', 'max:255'], 'reply_to' => ['nullable', 'email:rfc', 'max:255'],
            'enabled' => ['required', 'boolean'], 'timeout_seconds' => ['nullable', 'integer', 'between:2,120'], 'max_attempts' => ['nullable', 'integer', 'between:1,5'],
        ]);
        $key = (string) ($data['configuration_key'] ?? 'default');
        return response()->json($this->configuration->serialize($this->configuration->save($key, $data)));
    }

    public function diagnose(Request $request): JsonResponse
    {
        $this->authorizer->authorize('tenant.manage');
        return response()->json($this->configuration->diagnose((string) $request->input('configuration_key', 'default')));
    }

    public function templates(): JsonResponse
    {
        $this->authorizer->authorize('tenant.manage');
        return response()->json($this->templates->all());
    }

    public function saveTemplate(Request $request, string $stableId, string $locale): JsonResponse
    {
        $this->authorizer->authorize('tenant.manage');
        $data = $request->validate([
            'subject_template' => ['required', 'string', 'max:500'], 'html_template' => ['required', 'string', 'max:100000'],
            'text_template' => ['nullable', 'string', 'max:100000'], 'variables' => ['required', 'array', 'max:100'], 'variables.*' => ['string', 'max:80'],
        ]);
        return response()->json($this->templates->serialize($this->templates->save($stableId, $locale, $data)));
    }

    public function deliveries(): JsonResponse
    {
        $this->authorizer->authorize('tenant.manage');
        return response()->json($this->deliveries->history());
    }
}
