using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

public sealed class BulkStatusExecutionService(
    IWordPressPostEditorService editor,
    SiteWebService sites,
    WordPressSyncWebService syncService,
    ExecutionOperationTracker tracker)
{
    private static readonly HashSet<string> AllowedStatuses = new(StringComparer.OrdinalIgnoreCase)
    {
        "publish", "draft", "pending", "private"
    };

    public async Task<BulkStatusResult> RunAsync(
        Guid siteId,
        BulkStatusRequest request,
        CancellationToken cancellationToken = default)
    {
        var site = await sites.GetSiteAsync(siteId, cancellationToken)
            ?? throw new InvalidOperationException("الموقع غير موجود.");
        var ownerUserId = site.OwnerUserId
            ?? throw new UnauthorizedAccessException("The selected site has no application owner.");

        var status = (request.Status ?? string.Empty).Trim().ToLowerInvariant();
        if (!AllowedStatuses.Contains(status))
            throw new InvalidOperationException("الحالة المطلوبة غير مدعومة. استخدم publish أو draft أو pending أو private.");

        var targets = (request.Targets ?? Array.Empty<BulkStatusTarget>())
            .Where(x => x.WordPressId > 0 && x.ContentType is "post" or "page")
            .DistinctBy(x => (x.ContentType, x.WordPressId))
            .ToList();

        if (targets.Count == 0)
            throw new InvalidOperationException("لم يتم تحديد مقالات أو صفحات صالحة.");

        var attempts = Math.Clamp(request.MaxAttempts, 1, 5);
        var title = status switch
        {
            "publish" => "Publish selected content",
            "draft" => "Move selected content to draft",
            "pending" => "Send selected content for review",
            "private" => "Make selected content private",
            _ => "Bulk content status"
        };

        var jobId = tracker.Start(ownerUserId, siteId, title, $"Bulk Status: {status}", site.Name, targets.Count);
        var succeeded = 0;
        var failures = new List<string>();

        for (var index = 0; index < targets.Count; index++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var target = targets[index];
            string? lastError = null;
            var updated = false;

            for (var attempt = 1; attempt <= attempts; attempt++)
            {
                tracker.Report(jobId, index, targets.Count,
                    $"Processing {target.ContentType} #{target.WordPressId}; attempt {attempt}/{attempts}.");

                var current = await editor.GetAsync(siteId, target.ContentType, target.WordPressId, cancellationToken);
                if (current.IsFailure)
                {
                    lastError = current.Error.Message;
                }
                else
                {
                    var value = current.Value;
                    var update = new WordPressContentUpdateRequest(
                        value.ContentType,
                        value.Id,
                        value.Title,
                        value.Slug,
                        status,
                        value.Content,
                        value.Excerpt,
                        value.DateGmt,
                        value.FeaturedMediaId,
                        value.CategoryIds,
                        value.TagIds,
                        value.Template,
                        value.CommentStatus,
                        value.PingStatus,
                        value.Format,
                        value.Sticky);

                    var result = await editor.UpdateAsync(siteId, update, cancellationToken);
                    if (result.IsSuccess)
                    {
                        updated = true;
                        break;
                    }

                    lastError = result.Error.Message;
                }

                if (attempt < attempts)
                    await Task.Delay(TimeSpan.FromSeconds(attempt), cancellationToken);
            }

            if (updated) succeeded++;
            else failures.Add($"{target.ContentType} #{target.WordPressId}: {lastError ?? "Unknown error"}");

            tracker.Report(jobId, index + 1, targets.Count, $"Processed {index + 1}/{targets.Count}.");
        }

        try
        {
            tracker.Report(jobId, targets.Count, targets.Count, "Refreshing local WordPress cache.");
            await syncService.SynchronizeAsync(siteId, cancellationToken);
        }
        catch
        {
            // The remote updates are already committed. Cache refresh can be retried separately.
        }

        var message = failures.Count == 0
            ? $"تم تحديث حالة {succeeded} عنصر إلى {status}."
            : $"تم تحديث {succeeded} عنصر وفشل {failures.Count}. {string.Join(" | ", failures.Take(3))}";

        if (succeeded == 0) tracker.Fail(jobId, message);
        else tracker.Complete(jobId, targets.Count, targets.Count, message);

        return new BulkStatusResult(jobId, status, succeeded, failures.Count, failures, message);
    }
}

public sealed record BulkStatusTarget(string ContentType, int WordPressId);
public sealed record BulkStatusRequest(string? Status, IReadOnlyList<BulkStatusTarget>? Targets, int MaxAttempts = 3);
public sealed record BulkStatusResult(
    Guid JobId,
    string Status,
    int Succeeded,
    int Failed,
    IReadOnlyList<string> Errors,
    string Message);
