using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Executes approval-backed external jobs against the real WordPress editor service.
/// Approval and execution-center state advance only after the remote mutation and local
/// WordPress reconciliation have succeeded.
/// </summary>
public sealed class ApprovedChangeExecutionWorker(
    ExecutionCenterService executionCenter,
    ApprovalWorkflowService approvals,
    IServiceScopeFactory scopeFactory,
    ILogger<ApprovedChangeExecutionWorker> logger) : BackgroundService
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(2);

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            var jobs = executionCenter.GetPendingExternalJobs(20);
            foreach (var job in jobs)
            {
                if (stoppingToken.IsCancellationRequested) break;
                if (!job.OwnerUserId.HasValue || !job.SiteId.HasValue) continue;
                if (!executionCenter.TryStartExternal(job.Id, job.OwnerUserId.Value)) continue;

                await ExecuteJobAsync(job, stoppingToken);
            }

            try
            {
                await Task.Delay(PollInterval, stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
        }
    }

    private async Task ExecuteJobAsync(ExecutionJob job, CancellationToken cancellationToken)
    {
        var ownerUserId = job.OwnerUserId!.Value;
        var siteId = job.SiteId!.Value;
        ApprovalItem? approval = null;

        try
        {
            approval = approvals.GetByExecutionJobId(ownerUserId, job.Id)
                ?? throw new InvalidOperationException("The external execution job is not linked to an owned approval.");
            if (approval.Status != ApprovalStatus.Approved || approval.ExecutionJobId != job.Id)
                throw new InvalidOperationException("The approval is no longer queued for this execution job.");
            if (!ApprovedChangePolicy.TryGetRequest(approval, out var request, out var policyError))
                throw new InvalidOperationException(policyError);

            using var identity = BackgroundExecutionIdentity.Push(ownerUserId);
            using var mutationAuthorization = BackgroundContentMutationAuthorization.Push();
            using var scope = scopeFactory.CreateScope();
            var editor = scope.ServiceProvider.GetRequiredService<IWordPressPostEditorService>();
            var sync = scope.ServiceProvider.GetRequiredService<WordPressSyncWebService>();

            var remote = await editor.GetAsync(siteId, request.ContentType, request.Id, cancellationToken);
            var alreadyApplied = remote.IsSuccess && ApprovedChangePolicy.RemoteMatches(remote.Value, request);
            if (!alreadyApplied)
            {
                var update = await editor.UpdateAsync(siteId, request, cancellationToken);
                if (!update.IsSuccess)
                    throw new InvalidOperationException(update.Error.Message);
                if (!update.Value.Succeeded)
                    throw new InvalidOperationException(update.Value.Message);
            }

            var synchronized = await sync.SynchronizeAsync(siteId, cancellationToken, forceFullRefresh: true);
            if (!synchronized.IsSuccess)
                throw new InvalidOperationException(synchronized.Message);

            executionCenter.CompleteExternal(
                job.Id,
                ownerUserId,
                alreadyApplied
                    ? "Approved WordPress change was already applied; local state was reconciled."
                    : "Approved WordPress change executed and local state was reconciled.");
            approvals.MarkExecutionSucceeded(
                ownerUserId,
                approval.Id,
                job.Id,
                alreadyApplied
                    ? "WordPress already matched the approved payload; reconciliation completed."
                    : "WordPress mutation and reconciliation completed successfully.");
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            // ExecutionCenterService recovers interrupted External jobs back to Waiting on restart.
            throw;
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Approved change execution {ExecutionJobId} failed for owner {OwnerUserId}.", job.Id, ownerUserId);
            try
            {
                executionCenter.FailExternal(job.Id, ownerUserId, ex.Message);
            }
            catch (Exception stateEx)
            {
                logger.LogError(stateEx, "Failed to persist execution-center failure state for {ExecutionJobId}.", job.Id);
            }

            if (approval is not null)
            {
                try
                {
                    approvals.RecordExecutionFailed(ownerUserId, approval.Id, job.Id, ex.Message);
                }
                catch (Exception auditEx)
                {
                    logger.LogError(auditEx, "Failed to persist approval execution failure for {ApprovalId}.", approval.Id);
                }
            }
        }
    }
}
