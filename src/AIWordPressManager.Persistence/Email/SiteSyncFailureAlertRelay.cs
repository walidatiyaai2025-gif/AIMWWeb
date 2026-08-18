using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Email;

public sealed class SiteSyncFailureAlertRelay(
    AppDbContext dbContext,
    OperationalEmailAlertService alertService,
    ILogger<SiteSyncFailureAlertRelay> logger)
{
    private const int MaxCandidatesPerPass = 500;

    public async Task<SiteSyncFailureRelayResult> RelayPendingAsync(
        DateTime sinceUtc,
        IReadOnlySet<Guid>? alreadyHandled = null,
        CancellationToken cancellationToken = default)
    {
        sinceUtc = sinceUtc.Kind == DateTimeKind.Utc ? sinceUtc : sinceUtc.ToUniversalTime();

        var candidates = await (
            from run in dbContext.Set<SiteSyncRun>().AsNoTracking()
            join site in dbContext.Sites.AsNoTracking() on run.SiteId equals site.Id
            where run.Status == "Failed" &&
                  run.CompletedAtUtc != null &&
                  run.CompletedAtUtc >= sinceUtc &&
                  site.OwnerUserId != null
            orderby run.CompletedAtUtc, run.Id
            select new SiteSyncFailureCandidate(
                run.Id,
                run.SiteId,
                site.OwnerUserId!.Value,
                run.Message,
                run.CompletedAtUtc!.Value,
                site.LanguageCode))
            .Take(MaxCandidatesPerPass)
            .ToListAsync(cancellationToken);

        var handled = new List<Guid>(candidates.Count);
        var enqueued = 0;
        var skipped = 0;
        var failed = 0;

        foreach (var candidate in candidates)
        {
            if (alreadyHandled?.Contains(candidate.SyncRunId) == true)
                continue;

            try
            {
                var culture = candidate.LanguageCode?.StartsWith("ar", StringComparison.OrdinalIgnoreCase) == true
                    ? "ar"
                    : "en";
                var result = await alertService.EnqueueSiteSyncFailureAsync(
                    candidate.OwnerUserId,
                    candidate.SiteId,
                    candidate.SyncRunId,
                    candidate.FailureReason,
                    candidate.OccurredAtUtc,
                    culture,
                    cancellationToken);

                if (result.Enqueued) enqueued++;
                else skipped++;
                handled.Add(candidate.SyncRunId);
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
                    "Could not relay WordPress synchronization failure {SyncRunId} for site {SiteId}. The relay will retry on a later pass.",
                    candidate.SyncRunId,
                    candidate.SiteId);
            }
        }

        return new SiteSyncFailureRelayResult(candidates.Count, enqueued, skipped, failed, handled);
    }

    private sealed record SiteSyncFailureCandidate(
        Guid SyncRunId,
        Guid SiteId,
        Guid OwnerUserId,
        string FailureReason,
        DateTime OccurredAtUtc,
        string? LanguageCode);
}

public sealed record SiteSyncFailureRelayResult(
    int Scanned,
    int Enqueued,
    int Skipped,
    int Failed,
    IReadOnlyList<Guid> HandledRunIds);
