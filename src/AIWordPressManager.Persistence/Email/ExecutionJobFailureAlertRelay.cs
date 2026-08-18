using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Email;

public sealed class ExecutionJobFailureAlertRelay(
    AppDbContext dbContext,
    OperationalEmailAlertService alertService,
    ILogger<ExecutionJobFailureAlertRelay> logger)
{
    private const int MaxCandidatesPerPass = 500;

    public async Task<ExecutionJobFailureRelayResult> RelayPendingAsync(
        DateTime sinceUtc,
        IReadOnlySet<Guid>? alreadyHandled = null,
        CancellationToken cancellationToken = default)
    {
        sinceUtc = sinceUtc.Kind == DateTimeKind.Utc ? sinceUtc : sinceUtc.ToUniversalTime();

        var candidates = await (
            from job in dbContext.ExecutionJobs.AsNoTracking()
            join site in dbContext.Sites.AsNoTracking() on job.SiteId equals site.Id
            where job.Status == "Failed" &&
                  job.CompletedAtUtc != null &&
                  job.CompletedAtUtc >= sinceUtc &&
                  site.OwnerUserId != null
            orderby job.CompletedAtUtc, job.Id
            select new ExecutionJobFailureCandidate(
                job.Id,
                job.SiteId,
                site.OwnerUserId!.Value,
                job.JobType,
                job.ErrorDetails,
                job.CompletedAtUtc!.Value,
                site.LanguageCode))
            .Take(MaxCandidatesPerPass)
            .ToListAsync(cancellationToken);

        var handled = new List<Guid>(candidates.Count);
        var enqueued = 0;
        var skipped = 0;
        var failed = 0;

        foreach (var candidate in candidates)
        {
            if (alreadyHandled?.Contains(candidate.ExecutionJobId) == true)
                continue;

            try
            {
                var culture = candidate.LanguageCode?.StartsWith("ar", StringComparison.OrdinalIgnoreCase) == true
                    ? "ar"
                    : "en";
                var result = await alertService.EnqueueSiteJobFailureAsync(
                    candidate.OwnerUserId,
                    candidate.SiteId,
                    candidate.ExecutionJobId,
                    candidate.JobType,
                    candidate.FailureReason,
                    candidate.OccurredAtUtc,
                    culture,
                    cancellationToken);

                if (result.Enqueued) enqueued++;
                else skipped++;
                handled.Add(candidate.ExecutionJobId);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                failed++;
                logger.LogWarning(
                    ex,
                    "Could not relay execution job failure {ExecutionJobId} for site {SiteId}. The relay will retry on a later pass.",
                    candidate.ExecutionJobId,
                    candidate.SiteId);
            }
        }

        return new ExecutionJobFailureRelayResult(candidates.Count, enqueued, skipped, failed, handled);
    }

    private sealed record ExecutionJobFailureCandidate(
        Guid ExecutionJobId,
        Guid SiteId,
        Guid OwnerUserId,
        string JobType,
        string? FailureReason,
        DateTime OccurredAtUtc,
        string? LanguageCode);
}

public sealed record ExecutionJobFailureRelayResult(
    int Scanned,
    int Enqueued,
    int Skipped,
    int Failed,
    IReadOnlyList<Guid> HandledJobIds);
