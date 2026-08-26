using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

public sealed class BulkStatusExecutionService(
    IWordPressPostEditorService editor,
    SiteWebService sites,
    WordPressSyncWebService syncService,
    ExecutionOperationTracker tracker,
    CurrentUserContext currentUser)
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
        currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
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
        var confirmedRemoteMutations = 0;
        var failures = new List<string>();

        try
        {
            for (var index = 0; index < targets.Count; index++)
            {
                cancellationToken.ThrowIfCancellationRequested();
                var target = targets[index];
                string? lastError = null;
                var satisfied = false;
                var remotelyMutated = false;

                for (var attempt = 1; attempt <= attempts; attempt++)
                {
                    tracker.Report(jobId, index, targets.Count,
                        $"Processing {target.ContentType} #{target.WordPressId}; attempt {attempt}/{attempts}.");
                    try
                    {
                        var current = await editor.GetAsync(siteId, target.ContentType, target.WordPressId, cancellationToken);
                        if (current.IsFailure)
                        {
                            lastError = current.Error.Message;
                        }
                        else
                        {
                            var value = current.Value;
                            if (string.Equals(value.Status, status, StringComparison.OrdinalIgnoreCase))
                            {
                                satisfied = true;
                                tracker.Report(jobId, index, targets.Count,
                                    $"{target.ContentType} #{target.WordPressId} already has status {status}; duplicate mutation skipped.");
                                break;
                            }

                            var update = new WordPressContentUpdateRequest(
                                value.ContentType, value.Id, value.Title, value.Slug, status,
                                value.Content, value.Excerpt, value.DateGmt, value.FeaturedMediaId,
                                value.CategoryIds, value.TagIds, value.Template, value.CommentStatus,
                                value.PingStatus, value.Format, value.Sticky);
                            var result = await editor.UpdateAsync(siteId, update, cancellationToken);
                            if (result.IsSuccess)
                            {
                                satisfied = true;
                                remotelyMutated = true;
                                break;
                            }
                            lastError = result.Error.Message;
                        }
                    }
                    catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
                    {
                        throw;
                    }
                    catch (Exception ex)
                    {
                        lastError = ex.Message;
                    }

                    if (attempt < attempts)
                        await Task.Delay(TimeSpan.FromSeconds(attempt), cancellationToken);
                }

                if (satisfied)
                {
                    succeeded++;
                    if (remotelyMutated) confirmedRemoteMutations++;
                }
                else
                {
                    failures.Add($"{target.ContentType} #{target.WordPressId}: {lastError ?? "Unknown error"}");
                }

                tracker.Report(jobId, index + 1, targets.Count, $"Processed {index + 1}/{targets.Count}.");
            }
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            if (confirmedRemoteMutations > 0)
            {
                tracker.NeedsReconciliation(jobId, confirmedRemoteMutations, targets.Count,
                    $"WordPress applied {confirmedRemoteMutations} status mutation(s) to {status} before cancellation. Local reconciliation is required and mutations will not be replayed.");
            }
            else
            {
                tracker.Fail(jobId,
                    $"Bulk status operation was cancelled with zero confirmed remote mutations; {succeeded} target(s) were already satisfied or processed without a WordPress mutation.");
            }
            throw;
        }

        var reconciliationSucceeded = confirmedRemoteMutations == 0;
        string? reconciliationError = null;
        if (confirmedRemoteMutations > 0)
        {
            try
            {
                tracker.Report(jobId, targets.Count, targets.Count, "Refreshing local WordPress cache.");
                await syncService.SynchronizeAsync(siteId, cancellationToken, forceFullRefresh: true);
                reconciliationSucceeded = true;
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                reconciliationError = "Local reconciliation was cancelled after WordPress changes were already applied.";
            }
            catch (Exception ex)
            {
                reconciliationError = $"Local reconciliation failed after WordPress changes were already applied: {ex.Message}";
            }
        }

        var disposition = BulkExecutionOutcomePolicy.Resolve(succeeded, failures.Count, reconciliationSucceeded);
        string message;
        switch (disposition)
        {
            case BulkExecutionDisposition.NeedsReconciliation:
                message = $"{succeeded} target(s) now satisfy status {status}; WordPress was actually mutated for {confirmedRemoteMutations}. The local cache is not reconciled, and recovery will not replay those mutations. {reconciliationError}";
                tracker.NeedsReconciliation(jobId, confirmedRemoteMutations, targets.Count, message);
                break;
            case BulkExecutionDisposition.CompletedWithWarnings:
                message = $"{succeeded} target(s) now satisfy status {status}; {confirmedRemoteMutations} required a WordPress mutation and {failures.Count} failed. {string.Join(" | ", failures.Take(3))}";
                tracker.CompleteWithWarnings(jobId, targets.Count, targets.Count, message);
                break;
            case BulkExecutionDisposition.Completed:
                message = $"{succeeded} target(s) now satisfy status {status}; {confirmedRemoteMutations} required a WordPress mutation. No duplicate mutation was sent for targets already in the requested status.";
                tracker.Complete(jobId, targets.Count, targets.Count, message);
                break;
            default:
                message = $"فشل تحديث العناصر إلى {status}. {string.Join(" | ", failures.Take(3))}";
                tracker.Fail(jobId, message);
                break;
        }

        if (cancellationToken.IsCancellationRequested && disposition == BulkExecutionDisposition.NeedsReconciliation)
            cancellationToken.ThrowIfCancellationRequested();

        return new BulkStatusResult(
            jobId,
            status,
            succeeded,
            failures.Count,
            failures,
            message,
            disposition == BulkExecutionDisposition.NeedsReconciliation,
            confirmedRemoteMutations);
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
    string Message,
    bool RequiresReconciliation = false,
    int ConfirmedRemoteMutations = 0);
