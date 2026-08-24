using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

public sealed class BulkTrashExecutionService(
    IWordPressApiClient apiClient,
    SiteWebService sites,
    WordPressSyncWebService syncService,
    ExecutionOperationTracker tracker,
    CurrentUserContext currentUser)
{
    internal static readonly TimeSpan OperationTimeout = TimeSpan.FromSeconds(45);
    internal static readonly TimeSpan CacheRefreshTimeout = TimeSpan.FromSeconds(15);

    public async Task<BulkTrashResult> RunAsync(Guid siteId, BulkTrashRequest request, CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);

        var site = await sites.GetSiteAsync(siteId, cancellationToken)
            ?? throw new InvalidOperationException("الموقع غير موجود.");
        var ownerUserId = site.OwnerUserId
            ?? throw new UnauthorizedAccessException("The selected site has no application owner.");

        var targets = request.Targets
            .Where(x => x.WordPressId > 0 && x.ContentType is "post" or "page")
            .DistinctBy(x => (x.ContentType, x.WordPressId))
            .ToList();

        if (targets.Count == 0)
            throw new InvalidOperationException("لم يتم تحديد مقالات أو صفحات صالحة.");

        var jobId = tracker.Start(ownerUserId, siteId, "Move selected content to trash", "Bulk Trash", site.Name, targets.Count);
        var succeeded = 0;
        var failures = new List<string>();

        using var operationCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        operationCts.CancelAfter(OperationTimeout);
        var operationToken = operationCts.Token;

        try
        {
            for (var index = 0; index < targets.Count; index++)
            {
                cancellationToken.ThrowIfCancellationRequested();
                var target = targets[index];
                var endpoint = target.ContentType == "page"
                    ? $"/wp-json/wp/v2/pages/{target.WordPressId}"
                    : $"/wp-json/wp/v2/posts/{target.WordPressId}";

                tracker.Report(jobId, index, targets.Count, $"Moving {target.ContentType} #{target.WordPressId} to trash.");

                WordPressApiResponse<System.Text.Json.JsonDocument>? response = null;
                try
                {
                    response = await apiClient.SendAsync(
                        siteId,
                        HttpMethod.Post,
                        endpoint,
                        new { status = "trash" },
                        operationToken);
                }
                catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested && operationCts.IsCancellationRequested)
                {
                    failures.Add($"{target.ContentType} #{target.WordPressId}: انتهت مهلة تنفيذ العملية مع WordPress.");
                    var remaining = targets.Count - index - 1;
                    if (remaining > 0)
                        failures.Add($"لم تتم معالجة {remaining} عنصر متبقٍ لأن مهلة العملية انتهت.");
                    tracker.Report(jobId, index, targets.Count, "Bulk trash deadline reached; remaining items were not sent.");
                    break;
                }
                catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
                {
                    throw;
                }
                catch (Exception ex)
                {
                    failures.Add($"{target.ContentType} #{target.WordPressId}: WordPress mutation failed: {ex.Message}");
                    tracker.Report(jobId, index + 1, targets.Count, $"WordPress mutation failed for {target.ContentType} #{target.WordPressId}.");
                    continue;
                }

                if (response.IsSuccess)
                    succeeded++;
                else
                    failures.Add($"{target.ContentType} #{target.WordPressId}: {response.ErrorMessage ?? "Unknown error"}");

                response.Value?.Dispose();
                tracker.Report(jobId, index + 1, targets.Count, $"Processed {index + 1}/{targets.Count}.");
            }
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            if (succeeded > 0)
            {
                var partialMessage = $"WordPress moved {succeeded} item(s) to trash before cancellation. Local reconciliation is required and the remote mutation will not be replayed.";
                tracker.NeedsReconciliation(jobId, succeeded, targets.Count, partialMessage);
            }
            else
            {
                tracker.Fail(jobId, "Bulk trash operation was cancelled before any confirmed remote mutation completed.");
            }
            throw;
        }

        var remoteFailedCount = targets.Count - succeeded;
        var reconciliationSucceeded = succeeded == 0;
        string? reconciliationError = null;

        if (succeeded > 0)
        {
            try
            {
                tracker.Report(jobId, targets.Count, targets.Count, "Refreshing local WordPress cache.");
                using var refreshCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
                refreshCts.CancelAfter(CacheRefreshTimeout);
                await syncService.SynchronizeAsync(siteId, refreshCts.Token, forceFullRefresh: true);
                reconciliationSucceeded = true;
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                reconciliationError = "Local reconciliation was cancelled after WordPress changes were already applied.";
            }
            catch (OperationCanceledException)
            {
                reconciliationError = "Local reconciliation timed out after WordPress changes were already applied.";
            }
            catch (Exception ex)
            {
                reconciliationError = $"Local reconciliation failed after WordPress changes were already applied: {ex.Message}";
            }
        }

        var disposition = BulkExecutionOutcomePolicy.Resolve(succeeded, remoteFailedCount, reconciliationSucceeded);
        string message;
        switch (disposition)
        {
            case BulkExecutionDisposition.NeedsReconciliation:
                message = $"WordPress moved {succeeded} item(s) to trash, but the local cache is not reconciled. The remote mutation will not be replayed during recovery. {reconciliationError}";
                tracker.NeedsReconciliation(jobId, targets.Count, targets.Count, message);
                throw new BulkReconciliationRequiredException(jobId, message);
            case BulkExecutionDisposition.CompletedWithWarnings:
                message = $"تم نقل {succeeded} عنصر إلى سلة المهملات وفشل {remoteFailedCount}. {string.Join(" | ", failures.Take(3))}";
                tracker.CompleteWithWarnings(jobId, targets.Count, targets.Count, message);
                break;
            case BulkExecutionDisposition.Completed:
                message = $"تم نقل {succeeded} عنصر إلى سلة المهملات وتمت مطابقة البيانات المحلية مع WordPress.";
                tracker.Complete(jobId, targets.Count, targets.Count, message);
                break;
            default:
                message = $"فشل نقل العناصر إلى سلة المهملات. {string.Join(" | ", failures.Take(3))}";
                tracker.Fail(jobId, message);
                break;
        }

        return new BulkTrashResult(jobId, succeeded, remoteFailedCount, failures, message);
    }
}

public sealed class BulkReconciliationRequiredException(Guid jobId, string message) : InvalidOperationException(message)
{
    public Guid JobId { get; } = jobId;
}

public sealed record BulkTrashTarget(string ContentType, int WordPressId);
public sealed record BulkTrashRequest(IReadOnlyList<BulkTrashTarget> Targets);
public sealed record BulkTrashResult(
    Guid JobId,
    int Succeeded,
    int Failed,
    IReadOnlyList<string> Errors,
    string Message);
