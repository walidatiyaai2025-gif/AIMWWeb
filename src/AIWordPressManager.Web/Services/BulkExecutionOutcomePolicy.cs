namespace AIWordPressManager.Web.Services;

public enum BulkExecutionDisposition
{
    Failed,
    NeedsReconciliation,
    CompletedWithWarnings,
    Completed
}

/// <summary>
/// Converts the real WordPress mutation + local reconciliation outcome into an execution state.
/// A remote mutation is never considered fully complete until the local cache has reconciled.
/// </summary>
public static class BulkExecutionOutcomePolicy
{
    public static BulkExecutionDisposition Resolve(int succeeded, int failed, bool reconciliationSucceeded)
    {
        if (succeeded < 0) throw new ArgumentOutOfRangeException(nameof(succeeded));
        if (failed < 0) throw new ArgumentOutOfRangeException(nameof(failed));

        if (succeeded == 0) return BulkExecutionDisposition.Failed;
        if (!reconciliationSucceeded) return BulkExecutionDisposition.NeedsReconciliation;
        return failed > 0
            ? BulkExecutionDisposition.CompletedWithWarnings
            : BulkExecutionDisposition.Completed;
    }
}

/// <summary>
/// Production recovery boundary for bulk jobs whose WordPress mutation already happened but whose
/// local WordPress cache did not reconcile. Recovery is deliberately synchronization-only: it never
/// calls a WordPress mutation API and therefore cannot duplicate the destructive/update operation.
/// </summary>
public sealed class BulkContentReconciliationRecoveryService(
    ExecutionCenterService executionCenter,
    ExecutionOperationTracker tracker,
    WordPressSyncWebService syncService,
    SiteWebService sites,
    CurrentUserContext currentUser,
    ApplicationSecurityAuditService audit)
{
    public async Task RetryAsync(Guid jobId, CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
        var ownerUserId = currentUser.RequireUserId();
        var job = executionCenter.GetJobs(ownerUserId).FirstOrDefault(x => x.Id == jobId)
            ?? throw new UnauthorizedAccessException("The requested execution job does not belong to the signed-in user.");

        if (!string.Equals(job.Status, "NeedsReconciliation", StringComparison.Ordinal))
            throw new InvalidOperationException("Only bulk jobs waiting for local reconciliation can use this recovery action.");
        if (!IsSupportedBulkJob(job))
            throw new InvalidOperationException("This execution job does not support bulk reconciliation recovery.");
        if (!job.SiteId.HasValue)
            throw new InvalidOperationException("The bulk execution job has no site identity.");

        var site = await sites.GetSiteAsync(job.SiteId.Value, cancellationToken)
            ?? throw new UnauthorizedAccessException("The execution site is no longer owned by the signed-in user.");

        await audit.RecordCurrentAsync(
            "Content",
            "BulkReconciliationRetry",
            "Started",
            "ExecutionJob",
            job.Id.ToString("D"),
            job.Title,
            new Dictionary<string, string>
            {
                ["siteId"] = site.Id.ToString("D"),
                ["executionType"] = job.Type,
                ["confirmedRemoteMutations"] = job.ProcessedItems.ToString(),
                ["requestedMutations"] = job.TotalItems.ToString()
            },
            cancellationToken);

        try
        {
            tracker.Report(job.Id, job.ProcessedItems, job.TotalItems,
                "Retrying local reconciliation only; the WordPress mutation will not be replayed.");
            await syncService.SynchronizeAsync(site.Id, cancellationToken, forceFullRefresh: true);

            var wasPartialMutation = job.ProcessedItems < job.TotalItems;
            if (wasPartialMutation)
            {
                tracker.CompleteWithWarnings(
                    job.Id,
                    job.ProcessedItems,
                    job.TotalItems,
                    $"Local cache reconciled with the {job.ProcessedItems} confirmed remote mutation(s). " +
                    $"{job.TotalItems - job.ProcessedItems} originally requested mutation(s) were not replayed after interruption.");
            }
            else
            {
                tracker.Complete(job.Id, job.ProcessedItems, job.TotalItems,
                    "Local cache reconciled with the WordPress state that was already applied remotely.");
            }

            await audit.RecordCurrentAsync(
                "Content",
                "BulkReconciliationRetry",
                wasPartialMutation ? "SucceededWithWarnings" : "Succeeded",
                "ExecutionJob",
                job.Id.ToString("D"),
                job.Title,
                new Dictionary<string, string>
                {
                    ["siteId"] = site.Id.ToString("D"),
                    ["confirmedRemoteMutations"] = job.ProcessedItems.ToString(),
                    ["requestedMutations"] = job.TotalItems.ToString(),
                    ["replayedMutations"] = "0"
                },
                cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            const string message = "Remote WordPress changes remain applied; local reconciliation retry was cancelled before completion.";
            tracker.NeedsReconciliation(job.Id, job.ProcessedItems, job.TotalItems, message);
            await audit.RecordCurrentAsync(
                "Content",
                "BulkReconciliationRetry",
                "Cancelled",
                "ExecutionJob",
                job.Id.ToString("D"),
                job.Title,
                new Dictionary<string, string>
                {
                    ["siteId"] = site.Id.ToString("D"),
                    ["replayedMutations"] = "0"
                },
                CancellationToken.None);
            throw;
        }
        catch (Exception ex)
        {
            var message = $"Remote WordPress changes remain applied; local reconciliation retry failed: {ex.Message}";
            tracker.NeedsReconciliation(job.Id, job.ProcessedItems, job.TotalItems, message);
            await audit.RecordCurrentAsync(
                "Content",
                "BulkReconciliationRetry",
                "Failed",
                "ExecutionJob",
                job.Id.ToString("D"),
                job.Title,
                new Dictionary<string, string>
                {
                    ["siteId"] = site.Id.ToString("D"),
                    ["reason"] = ex.Message,
                    ["replayedMutations"] = "0"
                },
                CancellationToken.None);
            throw new InvalidOperationException(message, ex);
        }
    }

    internal static bool IsSupportedBulkJob(ExecutionJob job) =>
        string.Equals(job.Type, "Bulk Trash", StringComparison.Ordinal) ||
        job.Type.StartsWith("Bulk Status:", StringComparison.Ordinal) ||
        job.Type.StartsWith("Global Bulk Status:", StringComparison.Ordinal);
}
