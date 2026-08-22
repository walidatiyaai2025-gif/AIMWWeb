using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

public sealed class BulkTrashExecutionService(
    IWordPressApiClient apiClient,
    SiteWebService sites,
    WordPressSyncWebService syncService,
    ExecutionOperationTracker tracker,
    CurrentUserContext currentUser)
{
    // Bulk trash runs synchronously from the Interactive Server confirmation dialog. A WordPress
    // endpoint that never completes must not leave the user's circuit in an infinite Busy state.
    // This is an end-to-end deadline for the remote mutations for one site, not a per-item timeout.
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
                    // WordPressApiClient deliberately does not retry mutation requests. Repeating a
                    // POST blindly can duplicate side effects and used to multiply its five-minute
                    // HttpClient timeout by three for every selected item.
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

                if (response.IsSuccess)
                    succeeded++;
                else
                    failures.Add($"{target.ContentType} #{target.WordPressId}: {response.ErrorMessage ?? "Unknown error"}");

                response.Value?.Dispose();
                tracker.Report(jobId, index + 1, targets.Count, $"Processed {index + 1}/{targets.Count}.");
            }

            if (succeeded > 0)
            {
                try
                {
                    tracker.Report(jobId, targets.Count, targets.Count, "Refreshing local WordPress cache.");
                    using var refreshCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
                    refreshCts.CancelAfter(CacheRefreshTimeout);
                    await syncService.SynchronizeAsync(siteId, refreshCts.Token);
                }
                catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
                {
                    // Remote mutations are already committed. Never keep the confirmation dialog
                    // blocked just because reconciliation is slow; the normal Sync workspace can retry.
                    tracker.Report(jobId, targets.Count, targets.Count, "Remote changes completed; local cache refresh timed out.");
                }
                catch
                {
                    // Remote operation already completed; cache refresh can be retried separately.
                }
            }
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            tracker.Fail(jobId, "Bulk trash operation was cancelled.");
            throw;
        }

        var failedCount = targets.Count - succeeded;
        var message = failedCount == 0
            ? $"تم نقل {succeeded} عنصر إلى سلة المهملات."
            : $"تم نقل {succeeded} عنصر وفشل {failedCount}. {string.Join(" | ", failures.Take(3))}";

        if (succeeded == 0) tracker.Fail(jobId, message);
        else tracker.Complete(jobId, targets.Count, targets.Count, message);

        return new BulkTrashResult(jobId, succeeded, failedCount, failures, message);
    }
}

public sealed record BulkTrashTarget(string ContentType, int WordPressId);
public sealed record BulkTrashRequest(IReadOnlyList<BulkTrashTarget> Targets);
public sealed record BulkTrashResult(Guid JobId, int Succeeded, int Failed, IReadOnlyList<string> Errors, string Message);
