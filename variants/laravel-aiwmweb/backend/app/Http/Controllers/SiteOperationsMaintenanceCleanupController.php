<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SiteOperationsMaintenanceCleanupController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-DA1A53D8A1';

    private const ALLOWED_OLDER_THAN_DAYS = [30, 60, 90, 180, 365];

    private const ALLOWED_KEEP_LATEST = [50, 100, 250, 500];

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly SiteOperationHistoryService $history,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Request $request, string $tenant): RedirectResponse
    {
        $this->authorizer->authorize('execution.view');
        $this->authorizer->authorize('operations.manage');

        $activeTenant = $this->context->tenant();
        abort_unless(hash_equals($activeTenant->slug, $tenant), 404);

        $actorUserId = (int) $request->user()->getAuthIdentifier();
        $membership = $this->context->membership();
        abort_if($actorUserId < 1 || (int) $membership->user_id !== $actorUserId, 403);

        $validated = $request->validate([
            'older_than_days' => ['required', 'integer', Rule::in(self::ALLOWED_OLDER_THAN_DAYS)],
            'keep_latest' => ['required', 'integer', Rule::in(self::ALLOWED_KEEP_LATEST)],
            'confirmation' => ['required', 'string', 'max:32'],
        ]);

        if (! hash_equals('CLEANUP', trim((string) $validated['confirmation']))) {
            throw ValidationException::withMessages([
                'confirmation' => 'Type CLEANUP to confirm operation-history cleanup.',
            ]);
        }

        $olderThanDays = (int) $validated['older_than_days'];
        $keepLatest = (int) $validated['keep_latest'];

        $beforeStorage = $this->history->getStorageInfo();
        $beforePreview = $this->history->previewCleanup($olderThanDays, $keepLatest);
        abort_if(
            (int) $beforePreview['removable_count'] < 1,
            409,
            'No eligible operation-history records remain for cleanup.',
        );

        $result = $this->history->cleanup($olderThanDays, $keepLatest);
        $removedCount = (int) ($result['removed_count'] ?? 0);
        abort_if($removedCount < 1, 409, 'Cleanup did not remove any eligible records.');

        $afterStorage = $this->history->getStorageInfo();
        $afterPreview = $this->history->previewCleanup($olderThanDays, $keepLatest);

        $beforeCount = (int) $beforeStorage['record_count'];
        $afterCount = (int) $afterStorage['record_count'];
        $reportedRemaining = (int) ($result['remaining_count'] ?? -1);

        abort_unless(
            $afterCount === $reportedRemaining
                && $afterCount === $beforeCount - $removedCount
                && (int) $afterPreview['total_count'] === $afterCount,
            409,
            'Cleanup persistence could not be verified by authoritative reread.',
        );

        return redirect()
            ->route('canonical.workspace.site-operations-maintenance', ['tenant' => $activeTenant->slug])
            ->with(
                'status',
                "Removed {$removedCount} operation-history records; {$afterCount} records remain.",
            );
    }
}
