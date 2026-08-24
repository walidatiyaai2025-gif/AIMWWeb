using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class BulkContentOperationWorker(
    BulkContentOperationQueue queue,
    IServiceScopeFactory scopeFactory,
    ExecutionOperationTracker tracker,
    ExecutionCenterService executionCenter,
    ApprovalWorkflowService approvals,
    NotificationInboxService notifications,
    ILogger<BulkContentOperationWorker> logger) : BackgroundService
{
    protected override Task ExecuteAsync(CancellationToken stoppingToken) =>
        Task.WhenAll(ProcessBulkQueueAsync(stoppingToken), ProcessApprovedQueueAsync(stoppingToken));

    private async Task ProcessBulkQueueAsync(CancellationToken stoppingToken)
    {
        await foreach (var request in queue.ReadAllAsync(stoppingToken))
        {
            try
            {
                await ProcessBulkAsync(request, stoppingToken);
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

    private async Task ProcessApprovedQueueAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                var jobs = executionCenter.GetPendingExternalJobs(10);
                foreach (var job in jobs)
                {
                    stoppingToken.ThrowIfCancellationRequested();
                    if (!job.OwnerUserId.HasValue || !job.SiteId.HasValue) continue;
                    if (!executionCenter.TryStartExternal(job.Id, job.OwnerUserId.Value)) continue;

                    try
                    {
                        await ProcessApprovedChangeAsync(job, stoppingToken);
                    }
                    catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
                    {
                        throw;
                    }
                    catch (Exception ex)
                    {
                        logger.LogError(ex, "Approved change execution {JobId} failed.", job.Id);
                        var currentApproval = approvals.GetByExecutionJobId(job.OwnerUserId.Value, job.Id);
                        if (currentApproval?.Status == ApprovalStatus.Executed)
                        {
                            TryCompleteReconciledJob(job, "Approved change was already applied; execution state was reconciled.");
                            continue;
                        }

                        executionCenter.FailExternal(job.Id, job.OwnerUserId.Value, ex.Message);
                        if (currentApproval is not null)
                            approvals.RecordExecutionFailed(job.OwnerUserId.Value, currentApproval.Id, job.Id, ex.Message);
                    }
                }

                await Task.Delay(TimeSpan.FromSeconds(1), stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                logger.LogError(ex, "Approved change queue polling failed.");
                await Task.Delay(TimeSpan.FromSeconds(2), stoppingToken);
            }
        }
    }

    private async Task ProcessApprovedChangeAsync(ExecutionJob job, CancellationToken cancellationToken)
    {
        var ownerUserId = job.OwnerUserId ?? throw new UnauthorizedAccessException("Approved execution owner is missing.");
        var siteId = job.SiteId ?? throw new UnauthorizedAccessException("Approved execution site is missing.");

        using var scope = scopeFactory.CreateScope();
        var dbContext = scope.ServiceProvider.GetRequiredService<AppDbContext>();
        var authoritativeOwner = await dbContext.Sites
            .AsNoTracking()
            .Where(x => x.Id == siteId)
            .Select(x => x.OwnerUserId)
            .SingleOrDefaultAsync(cancellationToken);

        if (!authoritativeOwner.HasValue || authoritativeOwner.Value != ownerUserId)
            throw new UnauthorizedAccessException("The selected site is no longer owned by the approval owner.");

        using var executionIdentity = BackgroundExecutionIdentity.Push(ownerUserId);
        var approval = approvals.GetByExecutionJobId(ownerUserId, job.Id)
            ?? throw new InvalidOperationException("The execution job is not linked to an owned approval.");

        if (approval.SiteId != siteId)
            throw new InvalidOperationException("Approval and execution job site identities do not match.");

        if (approval.Status == ApprovalStatus.Executed)
        {
            executionCenter.CompleteExternal(job.Id, ownerUserId, "Approved change execution reconciled after restart.");
            return;
        }

        if (approval.Status != ApprovalStatus.Approved)
            throw new InvalidOperationException("Only approved changes can be executed.");

        if (!ApprovedChangePolicy.TryGetRequest(approval, out var desired, out var policyError))
            throw new InvalidOperationException(policyError);

        var editor = scope.ServiceProvider.GetRequiredService<IWordPressPostEditorService>();
        var remoteResult = await editor.GetAsync(siteId, desired.ContentType, desired.Id, cancellationToken);
        if (remoteResult.IsFailure)
            throw new InvalidOperationException(remoteResult.Error.Message);

        var remote = remoteResult.Value;
        if (ApprovedChangePolicy.RemoteMatches(remote, desired))
        {
            approvals.MarkExecutionSucceeded(ownerUserId, approval.Id, job.Id, "Remote content already matched the approved state; no duplicate mutation was sent.");
            executionCenter.CompleteExternal(job.Id, ownerUserId, "Approved state already existed remotely. Execution completed idempotently.");
            return;
        }

        if (WordPressPostEditorWebService.HasRemoteChanged(desired.ExpectedModifiedGmt, remote.ModifiedGmt))
            throw new InvalidOperationException(WordPressPostEditorWebService.ConflictMessage);

        using var mutationAuthorization = BackgroundContentMutationAuthorization.Push();
        var updateResult = await editor.UpdateAsync(siteId, desired with { ForceOverwrite = false }, cancellationToken);
        if (updateResult.IsFailure)
            throw new InvalidOperationException(updateResult.Error.Message);

        approvals.MarkExecutionSucceeded(
            ownerUserId,
            approval.Id,
            job.Id,
            $"WordPress {desired.ContentType} #{desired.Id} updated successfully.");
        executionCenter.CompleteExternal(job.Id, ownerUserId, "Approved WordPress content change completed successfully.");

        try
        {
            var syncService = scope.ServiceProvider.GetRequiredService<WordPressSyncWebService>();
            await syncService.SynchronizeAsync(siteId, cancellationToken, forceFullRefresh: true);
        }
        catch (Exception ex)
        {
            logger.LogWarning(ex, "Approved change {JobId} completed but local cache refresh failed.", job.Id);
        }
    }

    private void TryCompleteReconciledJob(ExecutionJob job, string message)
    {
        if (!job.OwnerUserId.HasValue) return;
        try { executionCenter.CompleteExternal(job.Id, job.OwnerUserId.Value, message); }
        catch (Exception ex) { logger.LogWarning(ex, "Could not reconcile completed approved job {JobId}.", job.Id); }
    }

    private async Task ProcessBulkAsync(BulkContentOperationRequest request, CancellationToken cancellationToken)
    {
        if (request.OwnerUserId == Guid.Empty)
            throw new UnauthorizedAccessException("Bulk execution owner is missing.");

        using var scope = scopeFactory.CreateScope();
        var dbContext = scope.ServiceProvider.GetRequiredService<AppDbContext>();
        var authoritativeOwner = await dbContext.Sites
            .AsNoTracking()
            .Where(x => x.Id == request.SiteId && !x.IsDeleted)
            .Select(x => x.OwnerUserId)
            .SingleOrDefaultAsync(cancellationToken);

        if (!authoritativeOwner.HasValue || authoritativeOwner.Value != request.OwnerUserId)
            throw new UnauthorizedAccessException("The selected site is no longer owned by the bulk operation owner.");

        using var executionIdentity = BackgroundExecutionIdentity.Push(request.OwnerUserId);
        using var mutationAuthorization = BackgroundContentMutationAuthorization.Push();
        var editor = scope.ServiceProvider.GetRequiredService<IWordPressPostEditorService>();
        var syncService = scope.ServiceProvider.GetRequiredService<WordPressSyncWebService>();
        var succeeded = 0;
        var failures = new List<string>();
        var total = Math.Max(1, request.Targets.Count);

        try
        {
            for (var index = 0; index < request.Targets.Count; index++)
            {
                cancellationToken.ThrowIfCancellationRequested();
                if (!await WaitUntilRunnableAsync(request.JobId, cancellationToken)) return;
                var target = request.Targets[index];
                var outcome = await ProcessTargetWithRetryAsync(editor, request, target, index, total, cancellationToken);
                if (outcome.Success) succeeded++; else failures.Add($"{target.Title}: {outcome.Error}");
                tracker.Report(request.JobId, index + 1, total,
                    outcome.Success ? $"Processed {index + 1}/{total}: {target.Title}" : $"Failed after {outcome.Attempts} attempt(s): {target.Title}");
            }

            if (!await WaitUntilRunnableAsync(request.JobId, cancellationToken)) return;

            var reconciliationSucceeded = succeeded == 0;
            string? reconciliationError = null;
            if (succeeded > 0)
            {
                tracker.Report(request.JobId, total, total, "Refreshing local WordPress cache.");
                try
                {
                    await syncService.SynchronizeAsync(request.SiteId, cancellationToken, forceFullRefresh: true);
                    reconciliationSucceeded = true;
                }
                catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
                {
                    throw;
                }
                catch (Exception ex)
                {
                    reconciliationError = $"Remote WordPress changes were applied, but local reconciliation failed: {ex.Message}";
                    logger.LogWarning(ex, "Bulk operation {JobId} requires local reconciliation recovery.", request.JobId);
                }
            }

            var disposition = BulkExecutionOutcomePolicy.Resolve(succeeded, failures.Count, reconciliationSucceeded);
            switch (disposition)
            {
                case BulkExecutionDisposition.NeedsReconciliation:
                {
                    var message = $"WordPress updated {succeeded} item(s), but the local cache is not reconciled. Recovery will synchronize only and will not replay mutations. {reconciliationError}";
                    if (failures.Count > 0) message += $" Mutation failures: {string.Join(" | ", failures.Take(3))}";
                    tracker.NeedsReconciliation(request.JobId, succeeded, total, message);
                    Notify(request.OwnerUserId, request, "Bulk operation needs reconciliation", message, NotificationSeverity.Warning);
                    break;
                }
                case BulkExecutionDisposition.CompletedWithWarnings:
                {
                    var message = $"Bulk operation reconciled with warnings. {succeeded} succeeded, {failures.Count} failed. {string.Join(" | ", failures.Take(3))}";
                    tracker.CompleteWithWarnings(request.JobId, total, total, message);
                    Notify(request.OwnerUserId, request, "Bulk operation completed with warnings", message, NotificationSeverity.Warning);
                    break;
                }
                case BulkExecutionDisposition.Completed:
                {
                    var message = $"Bulk operation completed and reconciled. {succeeded} item(s) updated.";
                    tracker.Complete(request.JobId, total, total, message);
                    Notify(request.OwnerUserId, request, "Bulk operation completed", message, NotificationSeverity.Success);
                    break;
                }
                default:
                {
                    var message = $"All items failed. {string.Join(" | ", failures.Take(3))}";
                    tracker.Fail(request.JobId, message);
                    Notify(request.OwnerUserId, request, "Bulk operation failed", message, NotificationSeverity.Error);
                    break;
                }
            }
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            if (succeeded > 0)
            {
                var message = $"WordPress confirmed {succeeded} requested status change(s) before execution was interrupted. Local reconciliation is required; no mutation will be replayed by recovery.";
                tracker.NeedsReconciliation(request.JobId, succeeded, total, message);
                Notify(request.OwnerUserId, request, "Bulk operation needs reconciliation", message, NotificationSeverity.Warning);
            }
            throw;
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
            if (!await WaitUntilRunnableAsync(request.JobId, cancellationToken)) return new BulkTargetOutcome(false, attempt, "Cancelled by user.");
            tracker.Report(request.JobId, index, total, $"Attempt {attempt}/{maximumAttempts}: {target.ContentType} #{target.WordPressId} - {target.Title}");
            try
            {
                var current = await editor.GetAsync(request.SiteId, target.ContentType, target.WordPressId, cancellationToken);
                if (current.IsFailure) lastError = current.Error.Message;
                else
                {
                    var value = current.Value;
                    if (string.Equals(value.Status, request.TargetStatus, StringComparison.OrdinalIgnoreCase))
                    {
                        tracker.Report(request.JobId, index, total,
                            $"{target.ContentType} #{target.WordPressId} already has status {request.TargetStatus}; duplicate mutation skipped.");
                        return new BulkTargetOutcome(true, attempt, null);
                    }

                    var update = new WordPressContentUpdateRequest(
                        value.ContentType, value.Id, value.Title, value.Slug, request.TargetStatus,
                        value.Content, value.Excerpt, value.DateGmt, value.FeaturedMediaId,
                        value.CategoryIds, value.TagIds, value.Template, value.CommentStatus,
                        value.PingStatus, value.Format, value.Sticky);
                    var result = await editor.UpdateAsync(request.SiteId, update, cancellationToken);
                    if (result.IsSuccess) return new BulkTargetOutcome(true, attempt, null);
                    lastError = result.Error.Message;
                }
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { throw; }
            catch (Exception ex)
            {
                lastError = ex.Message;
                logger.LogWarning(ex, "Bulk item {ContentType} {WordPressId} failed on attempt {Attempt}/{MaximumAttempts}.", target.ContentType, target.WordPressId, attempt, maximumAttempts);
            }

            if (attempt < maximumAttempts)
            {
                var delay = TimeSpan.FromSeconds(Math.Min(10, attempt * 2));
                tracker.Report(request.JobId, index, total, $"Retrying {target.Title} in {delay.TotalSeconds:0} second(s). Last error: {lastError}");
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
            if (currentJob?.Status == "Cancelled") return false;
            if (currentJob?.Status != "Paused") return true;
            await Task.Delay(750, cancellationToken);
        }
    }

    private void NotifyFailureFromExecutionJob(BulkContentOperationRequest request, string message)
    {
        var job = executionCenter.GetJobs().FirstOrDefault(x => x.Id == request.JobId);
        if (!job?.OwnerUserId.HasValue ?? true) return;
        Notify(job!.OwnerUserId!.Value, request, "Bulk operation failed", message, NotificationSeverity.Error, job.SiteId);
    }

    private void Notify(Guid ownerUserId, BulkContentOperationRequest request, string title, string message, NotificationSeverity severity, Guid? siteId = null)
    {
        try
        {
            notifications.Create(ownerUserId, title, message, severity,
                siteId: siteId ?? request.SiteId, executionJobId: request.JobId, source: "BulkContentWorker");
        }
        catch (Exception ex) { logger.LogWarning(ex, "Failed to persist notification for bulk job {JobId}.", request.JobId); }
    }

    private sealed record BulkTargetOutcome(bool Success, int Attempts, string? Error);
}