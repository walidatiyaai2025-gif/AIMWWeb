using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Domain.Entities;

namespace AIWordPressManager.Web.Services;

public sealed class AIUsageWebService(
    IAIUsageLog usageLog,
    SiteWebService siteService,
    CurrentUserContext currentUser)
{
    public async Task<AIUsageDashboardSnapshot> GetAsync(
        Guid? siteId = null,
        int take = 5_000,
        CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.UserId;
        var sites = await siteService.GetSitesAsync(cancellationToken);
        ValidateOwnedSite(siteId, sites);

        var entries = usageLog.GetRecent(take, siteId, ownerUserId.ToString("D"));
        var totalCalls = entries.Count;
        var successfulCalls = entries.Count(x => x.IsSuccess);
        var inputTokens = entries.Sum(x => (long)x.InputTokens);
        var outputTokens = entries.Sum(x => (long)x.OutputTokens);
        var estimatedCost = entries.Sum(x => x.EstimatedCost);

        return new AIUsageDashboardSnapshot(
            totalCalls,
            successfulCalls,
            totalCalls == 0 ? 0 : (double)successfulCalls / totalCalls,
            inputTokens,
            outputTokens,
            estimatedCost,
            entries
                .GroupBy(x => string.IsNullOrWhiteSpace(x.Provider) ? "unknown" : x.Provider, StringComparer.OrdinalIgnoreCase)
                .Select(group => ToBreakdown(group.Key, group))
                .OrderByDescending(x => x.Calls)
                .ThenBy(x => x.Name, StringComparer.OrdinalIgnoreCase)
                .ToArray(),
            entries
                .GroupBy(x => string.IsNullOrWhiteSpace(x.Operation) ? "unspecified" : x.Operation!, StringComparer.OrdinalIgnoreCase)
                .Select(group => ToBreakdown(group.Key, group))
                .OrderByDescending(x => x.Calls)
                .ThenBy(x => x.Name, StringComparer.OrdinalIgnoreCase)
                .ToArray(),
            sites.OrderBy(x => x.Name, StringComparer.OrdinalIgnoreCase).ToArray(),
            entries);
    }

    public async Task<IReadOnlyList<AIUsageEntry>> GetRecentAsync(
        int take = 100,
        Guid? siteId = null,
        CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.UserId;
        var sites = await siteService.GetSitesAsync(cancellationToken);
        ValidateOwnedSite(siteId, sites);
        return usageLog.GetRecent(take, siteId, ownerUserId.ToString("D"));
    }

    private static AIUsageBreakdown ToBreakdown(string name, IEnumerable<AIUsageEntry> values)
    {
        var entries = values.ToArray();
        return new(
            name,
            entries.Length,
            entries.Count(x => x.IsSuccess),
            entries.Sum(x => (long)x.InputTokens),
            entries.Sum(x => (long)x.OutputTokens),
            entries.Sum(x => x.EstimatedCost));
    }

    private static void ValidateOwnedSite(Guid? siteId, IReadOnlyList<Site> sites)
    {
        if (!siteId.HasValue) return;
        if (sites.All(x => x.Id != siteId.Value))
            throw new InvalidOperationException("Selected site is unavailable.");
    }
}

public sealed record AIUsageDashboardSnapshot(
    int TotalCalls,
    int SuccessfulCalls,
    double SuccessRate,
    long InputTokens,
    long OutputTokens,
    decimal EstimatedCost,
    IReadOnlyList<AIUsageBreakdown> Providers,
    IReadOnlyList<AIUsageBreakdown> Operations,
    IReadOnlyList<Site> Sites,
    IReadOnlyList<AIUsageEntry> Recent);

public sealed record AIUsageBreakdown(
    string Name,
    int Calls,
    int SuccessfulCalls,
    long InputTokens,
    long OutputTokens,
    decimal EstimatedCost);
