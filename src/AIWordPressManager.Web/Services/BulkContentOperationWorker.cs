using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class BulkContentOperationWorker(
    BulkContentOperationQueue queue,
    IServiceScopeFactory scopeFactory,
    ExecutionOperationTracker tracker,
    ExecutionCenterService executionCenter,
    NotificationInboxService notifications,
    ILogger<BulkContentOperationWorker> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        await foreach (var request in queue.ReadAllAsync(stoppingToken))
        {
            try
            {
                await ProcessAsync(request, stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                logger.LogError(ex, "Bulk content operation {JobId} failed.", request.JobId);
                tracker.Fail(request.JobId, ex.Message);
                NotifyFailureFromExecutionJob(request, ex.Message);
            }
        }
    }

    private async Task ProcessAsync(BulkContentOperationRequest request, CancellationToken cancellationToken)
    {
        using var scope = scopeFactory.CreateScope();
        var dbContext = scope.ServiceProvider.GetRequiredService<AppDbContext>();
        var ownerUserId = await dbContext.Sites
            .AsNoTracking()
            .Where(x => x.Id == request.SiteId)
            .Select(x => x.OwnerUserId)
            .SingleOrDefaultAsync(cancellationToken);

        if (!ownerUserId.HasValue || ownerUserId.Value == Guid.Empty)
            throw new UnauthorizedAccessException("Background execution could not resolve an owner for the selected site.");

        using var executionIdentity = BackgroundExecutionIdentity.Push(ownerUserId.Value);
        var editor = scope.ServiceProvider.GetRequiredService<IWordPressPostEditorService>();
        var syncService = scope.ServiceProvider.GetRequiredService<WordPressSyncWebService>();
        var succeeded = 0;
        var failures = new List<string>();
        var total = Math.Max(1, request.Targets.Count);

        for (var index = 0; index < request.Targets.Count; index++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (!await WaitUntilRunnableAsync(request.JobId, cancellationToken)) return;

            var target = request.Targets[index];
            var outcome = await ProcessTargetWithRetryAsync(
                editor,
                request,
                target,
                index,
                total,
                cancellationToken);

            if (outcome.Success)
                succeeded++;
            else
                failures.Add($"{target.Title}: {outcome.Error}");

            tracker.Report(
                request.JobId,
                index + 1,
                total,
                outcome.Success
                    ? $"Processed {index + 1}/{total}: {target.Title}"
                    : $"Failed after {outcome.Attempts} attempt(s): {target.Title}");
        }

        if (!await WaitUntilRunnableAsync(request.JobId, cancellationToken)) return;

        tracker.Report(request.JobId, total, total, "Refreshing local WordPress cache.");
        try
        {
            await syncService.SynchronizeAsync(request.SiteId, cancellationToken);
        }
        catch (Exception ex)
        {
            logger.LogWarning(ex, "Bulk operation {JobId} completed but local cache refresh failed.", request.JobId);
            failures.Add($"Local cache refresh: {ex.Message}");
        }

        if (failures.Count == 0)
        {
            var message = $"Bulk operation completed. {succeeded} item(s) updated.";
            tracker.Complete(request.JobId, total, total, message);
            Notify(ownerUserId.Value, request, "Bulk operation completed", message, NotificationSeverity.Success);
        }
        else if (succeeded > 0)
        {
            var message = $"Completed with warnings. {succeeded} succeeded, {failures.Count} failed. {string.Join(" | ", failures.Take(3))}";
            tracker.Complete(request.JobId, total, total, message);
            Notify(ownerUserId.Value, request, "Bulk operation completed with warnings", message, NotificationSeverity.Warning);
        }
        else
        {
            var message = $"All items failed. {string.Join(" | ", failures.Take(3))}";
            tracker.Fail(request.JobId, message);
            Notify(ownerUserId.Value, request, "Bulk operation failed", message, NotificationSeverity.Error);
        }
    }

    private async Task<BulkTargetOutcome> ProcessTargetWithRetryAsync(
        IWordPressPostEditorService editor,
        BulkContentOperationRequest request,
        BulkContentTarget target,
        int index,
        int total,
        CancellationToken cancellationToken)
    {
        var maximumAttempts = request.NormalizedRetryCount + 1;
        string? lastError = null;

        for (var attempt = 1; attempt <= maximumAttempts; attempt++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (!await WaitUntilRunnableAsync(request.JobId, cancellationToken))
                return new BulkTargetOutcome(false, attempt, "Cancelled by user.");

            tracker.Report(
                request.JobId,
                index,
                total,
                $"Attempt {attempt}/{maximumAttempts}: {target.ContentType} #{target.WordPressId} - {target.Title}");

            try
            {
                var current = await editor.GetAsync(
                    request.SiteId,
                    target.ContentType,
                    target.WordPressId,
                    cancellationToken);

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
                        request.TargetStatus,
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

                    var result = await editor.UpdateAsync(request.SiteId, update, cancellationToken);
                    if (result.IsSuccess)
                        return new BulkTargetOutcome(true, attempt, null);

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
                logger.LogWarning(
                    ex,
                    "Bulk item {ContentType} {WordPressId} failed on attempt {Attempt}/{MaximumAttempts}.",
                    target.ContentType,
                    target.WordPressId,
                    attempt,
                    maximumAttempts);
            }

            if (attempt < maximumAttempts)
            {
                var delay = TimeSpan.FromSeconds(Math.Min(10, attempt * 2));
                tracker.Report(
                    request.JobId,
                    index,
                    total,
                    $"Retrying {target.Title} in {delay.TotalSeconds:0} second(s). Last error: {lastError}");
                await Task.Delay(delay, cancellationToken);
            }
        }

        return new BulkTargetOutcome(false, maximumAttempts, lastError ?? "Unknown error.");
    }

    private async Task<bool> WaitUntilRunnableAsync(Guid jobId, CancellationToken cancellationToken)
    {
        while (true)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var currentJob = executionCenter.GetJobs().FirstOrDefault(x => x.Id == jobId);

            if (currentJob?.Status == "Cancelled")
                return false;

            if (currentJob?.Status != "Paused")
                return true;

            await Task.Delay(750, cancellationToken);
        }
    }

    private void NotifyFailureFromExecutionJob(BulkContentOperationRequest request, string message)
    {
        var job = executionCenter.GetJobs().FirstOrDefault(x => x.Id == request.JobId);
        if (!job?.OwnerUserId.HasValue ?? true) return;
        Notify(job!.OwnerUserId!.Value, request, "Bulk operation failed", message, NotificationSeverity.Error, job.SiteId);
    }

    private void Notify(
        Guid ownerUserId,
        BulkContentOperationRequest request,
        string title,
        string message,
        NotificationSeverity severity,
        Guid? siteId = null)
    {
        try
        {
            notifications.Create(
                ownerUserId,
                title,
                message,
                severity,
                siteId: siteId ?? request.SiteId,
                executionJobId: request.JobId,
                source: "BulkContentWorker");
        }
        catch (Exception ex)
        {
            logger.LogWarning(ex, "Failed to persist notification for bulk job {JobId}.", request.JobId);
        }
    }

    private sealed record BulkTargetOutcome(bool Success, int Attempts, string? Error);
}
