using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

public sealed class BulkTrashExecutionService(
    IWordPressApiClient apiClient,
    SiteWebService sites,
    WordPressSyncWebService syncService,
    ExecutionOperationTracker tracker)
{
    public async Task<BulkTrashResult> RunAsync(Guid siteId, BulkTrashRequest request, CancellationToken cancellationToken = default)
    {
        var site = await sites.GetSiteAsync(siteId, cancellationToken)
            ?? throw new InvalidOperationException("الموقع غير موجود.");

        var targets = request.Targets
            .Where(x => x.WordPressId > 0 && x.ContentType is "post" or "page")
            .DistinctBy(x => (x.ContentType, x.WordPressId))
            .ToList();

        if (targets.Count == 0)
            throw new InvalidOperationException("لم يتم تحديد مقالات أو صفحات صالحة.");

        var jobId = tracker.Start("Move selected content to trash", "Bulk Trash", site.Name, targets.Count);
        var succeeded = 0;
        var failures = new List<string>();

        for (var index = 0; index < targets.Count; index++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var target = targets[index];
            var endpoint = target.ContentType == "page"
                ? $"/wp-json/wp/v2/pages/{target.WordPressId}"
                : $"/wp-json/wp/v2/posts/{target.WordPressId}";

            tracker.Report(jobId, index, targets.Count, $"Moving {target.ContentType} #{target.WordPressId} to trash.");

            WordPressApiResponse<System.Text.Json.JsonDocument>? response = null;
            for (var attempt = 1; attempt <= 3; attempt++)
            {
                response = await apiClient.SendAsync(siteId, HttpMethod.Post, endpoint, new { status = "trash" }, cancellationToken);
                if (response.IsSuccess) break;
                if (attempt < 3) await Task.Delay(TimeSpan.FromSeconds(attempt), cancellationToken);
            }

            if (response?.IsSuccess == true) succeeded++;
            else failures.Add($"{target.ContentType} #{target.WordPressId}: {response?.ErrorMessage ?? "Unknown error"}");

            response?.Value?.Dispose();
            tracker.Report(jobId, index + 1, targets.Count, $"Processed {index + 1}/{targets.Count}.");
        }

        try { await syncService.SynchronizeAsync(siteId, cancellationToken); }
        catch { /* Remote operation already completed; cache refresh can be retried separately. */ }

        var message = failures.Count == 0
            ? $"تم نقل {succeeded} عنصر إلى سلة المهملات."
            : $"تم نقل {succeeded} عنصر وفشل {failures.Count}. {string.Join(" | ", failures.Take(3))}";

        if (succeeded == 0) tracker.Fail(jobId, message);
        else tracker.Complete(jobId, targets.Count, targets.Count, message);

        return new BulkTrashResult(jobId, succeeded, failures.Count, failures, message);
    }
}

public sealed record BulkTrashTarget(string ContentType, int WordPressId);
public sealed record BulkTrashRequest(IReadOnlyList<BulkTrashTarget> Targets);
public sealed record BulkTrashResult(Guid JobId, int Succeeded, int Failed, IReadOnlyList<string> Errors, string Message);
