using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

public sealed class BulkContentOperationWorker(
    BulkContentOperationQueue queue,
    IServiceScopeFactory scopeFactory,
    ExecutionOperationTracker tracker,
    ExecutionCenterService executionCenter,
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
            }
        }
    }

    private async Task ProcessAsync(BulkContentOperationRequest request, CancellationToken cancellationToken)
    {
        using var scope = scopeFactory.CreateScope();
        var editor = scope.ServiceProvider.GetRequiredService<IWordPressPostEditorService>();
        var syncService = scope.ServiceProvider.GetRequiredService<WordPressSyncWebService>();
        var succeeded = 0;
        var failures = new List<string>();
        var total = Math.Max(1, request.Targets.Count);

        for (var index = 0; index < request.Targets.Count; index++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var currentJob = executionCenter.GetJobs().FirstOrDefault(x => x.Id == request.JobId);
            if (currentJob?.Status == "Cancelled") return;

            while (currentJob?.Status == "Paused")
            {
                await Task.Delay(750, cancellationToken);
                currentJob = executionCenter.GetJobs().FirstOrDefault(x => x.Id == request.JobId);
                if (currentJob?.Status == "Cancelled") return;
            }

            var target = request.Targets[index];
            tracker.Report(request.JobId, index, total, $"Loading {target.ContentType} #{target.WordPressId}: {target.Title}");

            var current = await editor.GetAsync(request.SiteId, target.ContentType, target.WordPressId, cancellationToken);
            if (current.IsFailure)
            {
                failures.Add($"{target.Title}: {current.Error.Message}");
                tracker.Report(request.JobId, index + 1, total, $"Failed: {target.Title}");
                continue;
            }

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
            if (result.IsSuccess) succeeded++;
            else failures.Add($"{target.Title}: {result.Error.Message}");

            tracker.Report(request.JobId, index + 1, total, $"Processed {index + 1}/{total}: {target.Title}");
        }

        tracker.Report(request.JobId, total, total, "Refreshing local WordPress cache.");
        await syncService.SynchronizeAsync(request.SiteId, cancellationToken);

        if (failures.Count == 0)
        {
            tracker.Complete(request.JobId, total, total, $"Bulk operation completed. {succeeded} item(s) updated.");
        }
        else if (succeeded > 0)
        {
            tracker.Complete(request.JobId, total, total, $"Completed with warnings. {succeeded} succeeded, {failures.Count} failed. {string.Join(" | ", failures.Take(3))}");
        }
        else
        {
            tracker.Fail(request.JobId, $"All items failed. {string.Join(" | ", failures.Take(3))}");
        }
    }
}
