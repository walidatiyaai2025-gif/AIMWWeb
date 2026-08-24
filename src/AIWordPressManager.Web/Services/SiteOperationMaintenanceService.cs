namespace AIWordPressManager.Web.Services;

public sealed class SiteOperationMaintenanceService(
    SiteOperationHistoryService history,
    SiteWebService siteService,
    CurrentUserContext currentUser,
    ApplicationSecurityAuditService securityAudit,
    ILogger<SiteOperationMaintenanceService> logger)
{
    public async Task<SiteOperationMaintenanceSnapshot> GetSnapshotAsync(
        int olderThanDays,
        int keepLatest,
        CancellationToken cancellationToken = default)
    {
        var scope = await ResolveScopeAsync(cancellationToken);
        return BuildSnapshot(scope.OwnerUserId, scope.OwnedSiteIds, olderThanDays, keepLatest);
    }

    public async Task<SiteOperationCleanupExecutionResult> CleanupAsync(
        int olderThanDays,
        int keepLatest,
        CancellationToken cancellationToken = default)
    {
        var scope = await ResolveScopeAsync(cancellationToken);
        var preview = history.PreviewCleanup(scope.OwnerUserId, scope.OwnedSiteIds, olderThanDays, keepLatest);
        if (preview.RemovableCount == 0)
        {
            var empty = new SiteOperationCleanupResult(0, preview.RetainedCount, preview.CutoffUtc, DateTime.UtcNow);
            return new SiteOperationCleanupExecutionResult(empty, true, null);
        }

        var operationId = Guid.NewGuid();
        var requestedMetadata = AuditMetadata(operationId, preview, removedCount: null, remainingCount: null);

        // The intent audit is required and deliberately precedes the destructive file mutation.
        // If audit persistence fails, cleanup fails closed and no history records are removed.
        await securityAudit.RecordCurrentAsync(
            "SiteOperations",
            "HistoryCleanup",
            "Requested",
            "SiteOperationHistory",
            operationId.ToString("D"),
            "Site operation history cleanup",
            requestedMetadata,
            cancellationToken);

        SiteOperationCleanupResult cleanup;
        try
        {
            cleanup = history.Cleanup(scope.OwnerUserId, scope.OwnedSiteIds, olderThanDays, keepLatest);
        }
        catch (Exception ex)
        {
            await TryRecordTerminalAuditAsync(
                operationId,
                preview,
                "Failed",
                removedCount: null,
                remainingCount: null,
                ex.GetType().Name,
                cancellationToken);
            throw;
        }

        var completionRecorded = await TryRecordTerminalAuditAsync(
            operationId,
            preview,
            "Succeeded",
            cleanup.RemovedCount,
            cleanup.RemainingCount,
            errorType: null,
            cancellationToken);

        var warning = completionRecorded
            ? null
            : "Cleanup completed, but its completion audit could not be persisted. The requested audit entry remains recorded.";
        return new SiteOperationCleanupExecutionResult(cleanup, completionRecorded, warning);
    }

    private async Task<SiteOperationMaintenanceScope> ResolveScopeAsync(CancellationToken cancellationToken)
    {
        var ownerUserId = currentUser.RequirePermission(ApplicationPermissionCatalog.OperationsExecute);
        var sites = await siteService.GetSitesAsync(cancellationToken);
        return new SiteOperationMaintenanceScope(ownerUserId, sites.Select(x => x.Id).ToArray());
    }

    private SiteOperationMaintenanceSnapshot BuildSnapshot(
        Guid ownerUserId,
        IReadOnlyCollection<Guid> ownedSiteIds,
        int olderThanDays,
        int keepLatest) =>
        new(
            history.GetStorageInfo(ownerUserId, ownedSiteIds),
            history.PreviewCleanup(ownerUserId, ownedSiteIds, olderThanDays, keepLatest));

    private async Task<bool> TryRecordTerminalAuditAsync(
        Guid operationId,
        SiteOperationCleanupPreview preview,
        string outcome,
        int? removedCount,
        int? remainingCount,
        string? errorType,
        CancellationToken cancellationToken)
    {
        try
        {
            await securityAudit.RecordCurrentAsync(
                "SiteOperations",
                "HistoryCleanup",
                outcome,
                "SiteOperationHistory",
                operationId.ToString("D"),
                "Site operation history cleanup",
                AuditMetadata(operationId, preview, removedCount, remainingCount, errorType),
                cancellationToken);
            return true;
        }
        catch (Exception auditException)
        {
            logger.LogError(
                auditException,
                "Could not persist terminal Site Operations cleanup audit for operation {OperationId} with outcome {Outcome}.",
                operationId,
                outcome);
            return false;
        }
    }

    private static IReadOnlyDictionary<string, string> AuditMetadata(
        Guid operationId,
        SiteOperationCleanupPreview preview,
        int? removedCount,
        int? remainingCount,
        string? errorType = null)
    {
        var metadata = new Dictionary<string, string>(StringComparer.Ordinal)
        {
            ["operationId"] = operationId.ToString("D"),
            ["cutoffUtc"] = preview.CutoffUtc.ToString("O"),
            ["keepLatest"] = preview.KeepLatest.ToString(),
            ["previewRemovableCount"] = preview.RemovableCount.ToString(),
            ["previewRetainedCount"] = preview.RetainedCount.ToString()
        };
        if (removedCount.HasValue) metadata["removedCount"] = removedCount.Value.ToString();
        if (remainingCount.HasValue) metadata["remainingCount"] = remainingCount.Value.ToString();
        if (!string.IsNullOrWhiteSpace(errorType)) metadata["errorType"] = errorType;
        return metadata;
    }

    private sealed record SiteOperationMaintenanceScope(Guid OwnerUserId, Guid[] OwnedSiteIds);
}

public sealed record SiteOperationMaintenanceSnapshot(
    SiteOperationHistoryStorageInfo Storage,
    SiteOperationCleanupPreview Preview);

public sealed record SiteOperationCleanupExecutionResult(
    SiteOperationCleanupResult Cleanup,
    bool CompletionAuditRecorded,
    string? Warning);
