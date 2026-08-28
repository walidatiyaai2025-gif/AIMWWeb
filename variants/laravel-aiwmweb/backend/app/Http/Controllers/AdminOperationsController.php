<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Operations\AdministrationService;
use App\Operations\OperationsControlPlaneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminOperationsController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly AdministrationService $administration,
        private readonly OperationsControlPlaneService $operations,
    ) {}

    public function members(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');

        return response()->json(['data' => $this->administration->members()]);
    }

    public function addMember(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('members.manage');
        $data = $request->validate(['email' => ['required', 'email'], 'role_id' => ['nullable', 'integer']]);

        return response()->json($this->administration->addOrInvite($data['email'], $data['role_id'] ?? null, (int) $request->user()->getAuthIdentifier()), 201);
    }

    public function updateMember(Request $request, string $tenant, int $membership): JsonResponse
    {
        $this->authorizer->authorize('members.manage');
        $data = $request->validate(['status' => ['nullable', 'string'], 'role_ids' => ['nullable', 'array'], 'role_ids.*' => ['integer']]);

        return response()->json($this->administration->updateMember($membership, $data['status'] ?? null, $data['role_ids'] ?? null, (int) $request->user()->getAuthIdentifier()));
    }

    public function removeMember(Request $request, string $tenant, int $membership): JsonResponse
    {
        $this->authorizer->authorize('members.manage');
        $this->administration->removeMember($membership, (int) $request->user()->getAuthIdentifier());

        return response()->json([], 204);
    }

    public function roles(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');

        return response()->json(['data' => $this->administration->roles()]);
    }

    public function saveRole(Request $request, string $tenant, ?int $role = null): JsonResponse
    {
        $this->authorizer->authorize('roles.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'permissions' => ['array'], 'permissions.*' => ['string', 'max:120']]);

        return response()->json($this->administration->saveRole($role, $data['name'], $data['permissions'] ?? [], (int) $request->user()->getAuthIdentifier()), $role ? 200 : 201);
    }

    public function sessions(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('sessions.manage');

        return response()->json(['data' => $this->administration->sessions((int) $request->user()->getAuthIdentifier(), $this->sessionId($request))]);
    }

    public function revokeSession(Request $request, string $tenant, string $session): JsonResponse
    {
        $this->authorizer->authorize('sessions.manage');
        $this->administration->revokeSession($session, (int) $request->user()->getAuthIdentifier(), $this->sessionId($request));

        return response()->json([], 204);
    }

    public function revokeOtherSessions(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('sessions.manage');

        return response()->json(['revoked' => $this->administration->revokeOtherSessions((int) $request->user()->getAuthIdentifier(), $this->sessionId($request))]);
    }

    public function platformSettings(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('settings.manage');

        return response()->json(['data' => $this->administration->platformSafeSettings(), 'writable' => false]);
    }

    public function settings(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('settings.manage');
        $data = $request->validate(['scope' => ['required', 'in:tenant,site,user'], 'site_key' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->administration->settings($data['scope'], $data['site_key'] ?? null, (int) $request->user()->getAuthIdentifier())]);
    }

    public function saveSetting(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('settings.manage');
        $data = $request->validate(['scope' => ['required', 'in:tenant,site,user'], 'key' => ['required', 'string', 'max:190'], 'value' => ['present'], 'secret' => ['sometimes', 'boolean'], 'site_key' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->administration->saveSetting($data['scope'], $data['key'], $data['value'], (bool) ($data['secret'] ?? false), $data['site_key'] ?? null, (int) $request->user()->getAuthIdentifier()));
    }

    public function schedules(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json(['data' => $this->operations->schedules()]);
    }

    public function saveSchedule(Request $request, string $tenant, ?int $task = null): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->saveSchedule($task, $request->all(), (int) $request->user()->getAuthIdentifier()), $task ? 200 : 201);
    }

    public function automations(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json(['data' => $this->operations->automations()]);
    }

    public function saveAutomation(Request $request, string $tenant, ?int $rule = null): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->saveAutomation($rule, $request->all(), (int) $request->user()->getAuthIdentifier()), $rule ? 200 : 201);
    }

    public function triggerAutomation(Request $request, string $tenant, int $rule): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->triggerAutomation($rule, (array) $request->input('payload', []), (int) $request->user()->getAuthIdentifier()));
    }

    public function approveAutomation(Request $request, string $tenant, int $run): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->approveAutomationRun($run, (int) $request->user()->getAuthIdentifier()));
    }

    public function operations(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json(['data' => $this->operations->operations($request->only(['status', 'type']))]);
    }

    public function operation(string $tenant, int $operation): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->operation($operation));
    }

    public function cancelOperation(Request $request, string $tenant, int $operation): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->cancelOperation($operation, (int) $request->user()->getAuthIdentifier()));
    }

    public function retryOperation(Request $request, string $tenant, int $operation): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->retryOperation($operation, (int) $request->user()->getAuthIdentifier()));
    }

    public function syncOperations(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json(['data' => $this->operations->syncOperations()]);
    }

    public function backups(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('backup.manage');

        return response()->json(['data' => $this->operations->backups()]);
    }

    public function requestBackup(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('backup.manage');
        $data = $request->validate(['level' => ['required', 'in:L1,L2,L3'], 'site_key' => ['nullable', 'string', 'max:255'], 'manifest' => ['array']]);

        return response()->json($this->operations->requestBackup($data['level'], $data['site_key'] ?? null, $data['manifest'] ?? [], (int) $request->user()->getAuthIdentifier()), 201);
    }

    public function approveBackup(Request $request, string $tenant, int $backup): JsonResponse
    {
        $this->authorizer->authorize('backup.manage');

        return response()->json($this->operations->approveBackup($backup, (int) $request->user()->getAuthIdentifier()));
    }

    public function requestRestore(Request $request, string $tenant, int $backup): JsonResponse
    {
        $this->authorizer->authorize('backup.manage');

        return response()->json($this->operations->requestRestore($backup, (int) $request->user()->getAuthIdentifier()), 201);
    }

    public function approveRestore(Request $request, string $tenant, int $restore): JsonResponse
    {
        $this->authorizer->authorize('backup.manage');

        return response()->json($this->operations->approveRestore($restore, (int) $request->user()->getAuthIdentifier()));
    }

    public function logs(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json(['data' => $this->operations->logs($request->only(['level', 'correlation_id', 'q']))]);
    }

    public function diagnostics(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->diagnostics());
    }

    public function report(string $tenant, string $report): JsonResponse
    {
        $this->authorizer->authorize('reports.manage');

        return response()->json($this->operations->report($report));
    }

    public function queueExport(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('reports.manage');
        $data = $request->validate(['report_type' => ['required', 'string'], 'filters' => ['array'], 'format' => ['sometimes', 'in:csv']]);

        return response()->json($this->operations->queueExport($data['report_type'], $data['filters'] ?? [], $data['format'] ?? 'csv', (int) $request->user()->getAuthIdentifier()), 202);
    }

    public function export(string $tenant, int $export): JsonResponse
    {
        $this->authorizer->authorize('reports.manage');

        return response()->json($this->operations->export($export));
    }

    public function downloadExport(string $tenant, int $export): StreamedResponse
    {
        $this->authorizer->authorize('reports.manage');
        $record = $this->operations->export($export);
        abort_unless(($record['status'] ?? null) === 'succeeded' && ! empty($record['file_path']), 409, 'Export is not ready.');
        abort_unless(Storage::disk('local')->exists($record['file_path']), 404);

        return Storage::disk('local')->download($record['file_path'], 'aimw-'.$record['report_type'].'-'.$export.'.csv');
    }

    private function sessionId(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }
}
