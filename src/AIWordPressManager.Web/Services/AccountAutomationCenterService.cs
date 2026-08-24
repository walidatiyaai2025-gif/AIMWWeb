using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Account-scoped boundary around the automation store. All interactive reads and mutations
/// flow through this service so ownership, plan limits, and executable-type contracts are
/// enforced server-side rather than relying on UI filtering.
/// </summary>
public sealed class AccountAutomationCenterService(
    AutomationCenterService automation,
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    IAccountEntitlementEnforcementService entitlementEnforcement,
    IApplicationPathService? paths = null)
{
    private Guid OwnerId => currentUser.RequireUserId();

    public async Task<IReadOnlyList<AutomationJob>> GetJobsAsync(CancellationToken cancellationToken = default)
    {
        var ownedSiteIds = await GetOwnedSiteIdsAsync(cancellationToken);
        return automation.GetJobs()
            .Where(x => ownedSiteIds.Contains(x.SiteId))
            .ToList();
    }

    public async Task<IReadOnlyList<AutomationHistoryItem>> GetHistoryAsync(
        int take = 50,
        CancellationToken cancellationToken = default)
    {
        var boundedTake = Math.Clamp(take, 1, 500);
        var ownedSiteIds = await GetOwnedSiteIdsAsync(cancellationToken);
        if (ownedSiteIds.Count == 0)
            return [];

        // Production DI supplies IApplicationPathService. Filter by the server-resolved owned
        // sites in SQLite BEFORE LIMIT so another tenant's newer history can neither leak into
        // the response nor suppress this account's own rows/counts.
        if (paths is not null)
            return ReadOwnedHistory(ownedSiteIds, boundedTake);

        // Compatibility for legacy/manual construction. This path remains tenant-filtered, but
        // production interactive consumers are registered through DI and use the scoped SQL path.
        var ownedJobIds = automation.GetJobs()
            .Where(x => ownedSiteIds.Contains(x.SiteId))
            .Select(x => x.Id)
            .ToHashSet();
        return automation.GetHistory(500)
            .Where(x => ownedJobIds.Contains(x.JobId))
            .Take(boundedTake)
            .ToList();
    }

    public async Task<AutomationAccountSnapshot> GetSnapshotAsync(
        int historyTake = 100,
        CancellationToken cancellationToken = default)
    {
        var jobs = await GetJobsAsync(cancellationToken);
        var history = await GetHistoryAsync(historyTake, cancellationToken);
        return new AutomationAccountSnapshot(jobs, history);
    }

    public async Task<Guid> SaveAsync(AutomationJobEditModel model, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(model);
        AutomationCenterService.RequireSupportedType(model.Type);
        var site = await RequireOwnedSiteAsync(model.SiteId, cancellationToken);

        if (model.Id == Guid.Empty)
        {
            var currentUsage = (await GetJobsAsync(cancellationToken)).LongCount();
            await entitlementEnforcement.RequireAdditionalUsageAsync(
                OwnerId,
                EntitlementDefinitionCatalog.AutomationSchedulesMax,
                currentUsage,
                1,
                cancellationToken);
        }
        else
        {
            // A caller-supplied ID may update only an existing owned job. Unknown and other-owner
            // IDs intentionally have the same result so existence cannot be inferred.
            _ = await RequireOwnedJobAsync(model.Id, cancellationToken);
        }

        if (string.Equals(model.Type, AutomationCenterService.SeoAuditType, StringComparison.OrdinalIgnoreCase))
        {
            await entitlementEnforcement.RequireBooleanCapabilityAsync(
                OwnerId,
                EntitlementDefinitionCatalog.PremiumSeo,
                cancellationToken);
        }

        model.SiteName = site.Name;
        return automation.Save(model);
    }

    public async Task SetEnabledAsync(Guid id, bool enabled, CancellationToken cancellationToken = default)
    {
        var job = await RequireOwnedJobAsync(id, cancellationToken);
        if (enabled)
        {
            AutomationCenterService.RequireSupportedType(job.Type);
            if (string.Equals(job.Type, AutomationCenterService.SeoAuditType, StringComparison.OrdinalIgnoreCase))
                await entitlementEnforcement.RequireBooleanCapabilityAsync(OwnerId, EntitlementDefinitionCatalog.PremiumSeo, cancellationToken);
        }
        automation.SetEnabled(id, enabled);
    }

    public async Task QueueNowAsync(Guid id, CancellationToken cancellationToken = default)
    {
        var job = await RequireOwnedJobAsync(id, cancellationToken);
        AutomationCenterService.RequireSupportedType(job.Type);
        if (string.Equals(job.Type, AutomationCenterService.SeoAuditType, StringComparison.OrdinalIgnoreCase))
            await entitlementEnforcement.RequireBooleanCapabilityAsync(OwnerId, EntitlementDefinitionCatalog.PremiumSeo, cancellationToken);
        automation.QueueNow(id);
    }

    public async Task DeleteAsync(Guid id, CancellationToken cancellationToken = default)
    {
        _ = await RequireOwnedJobAsync(id, cancellationToken);
        automation.Delete(id);
    }

    private async Task<AutomationJob> RequireOwnedJobAsync(Guid id, CancellationToken cancellationToken)
    {
        var ownedSiteIds = await GetOwnedSiteIdsAsync(cancellationToken);
        return automation.GetJobs()
            .FirstOrDefault(x => x.Id == id && ownedSiteIds.Contains(x.SiteId))
            ?? throw new KeyNotFoundException("Automation job was not found.");
    }

    private async Task<Site> RequireOwnedSiteAsync(Guid siteId, CancellationToken cancellationToken)
    {
        if (siteId == Guid.Empty)
            throw new InvalidOperationException("Select a WordPress site.");
        return await dbContext.Sites.AsNoTracking()
            .FirstOrDefaultAsync(x => x.Id == siteId && x.OwnerUserId == OwnerId, cancellationToken)
            ?? throw new UnauthorizedAccessException("The selected site is unavailable.");
    }

    private async Task<HashSet<Guid>> GetOwnedSiteIdsAsync(CancellationToken cancellationToken) =>
        (await dbContext.Sites.AsNoTracking()
            .Where(x => x.OwnerUserId == OwnerId)
            .Select(x => x.Id)
            .ToListAsync(cancellationToken))
        .ToHashSet();

    private IReadOnlyList<AutomationHistoryItem> ReadOwnedHistory(HashSet<Guid> ownedSiteIds, int take)
    {
        var databasePath = Path.Combine(paths!.GetApplicationDataDirectory(), "automation-center.db");
        using var connection = new SqliteConnection(new SqliteConnectionStringBuilder
        {
            DataSource = databasePath,
            Mode = SqliteOpenMode.ReadWriteCreate,
            Cache = SqliteCacheMode.Shared
        }.ToString());
        connection.Open();

        using var command = connection.CreateCommand();
        var parameters = ownedSiteIds
            .Select((siteId, index) => (siteId, name: $"$site{index}"))
            .ToArray();
        command.CommandText = $"""
            SELECT h.Id,h.JobId,h.JobName,h.StartedAtUtc,h.FinishedAtUtc,h.Status,h.Message
            FROM AutomationHistory h
            INNER JOIN AutomationJobs j ON j.Id = h.JobId
            WHERE j.SiteId IN ({string.Join(",", parameters.Select(x => x.name))})
            ORDER BY h.StartedAtUtc DESC
            LIMIT $take;
            """;
        foreach (var parameter in parameters)
            command.Parameters.AddWithValue(parameter.name, parameter.siteId.ToString());
        command.Parameters.AddWithValue("$take", take);

        using var reader = command.ExecuteReader();
        var rows = new List<AutomationHistoryItem>();
        while (reader.Read())
        {
            rows.Add(new AutomationHistoryItem(
                Guid.Parse(reader.GetString(0)),
                Guid.Parse(reader.GetString(1)),
                reader.GetString(2),
                ParseDate(reader.GetString(3)),
                reader.IsDBNull(4) ? null : ParseDate(reader.GetString(4)),
                reader.GetString(5),
                reader.GetString(6)));
        }
        return rows;
    }

    private static DateTime ParseDate(string value) =>
        DateTime.Parse(value, null, System.Globalization.DateTimeStyles.RoundtripKind).ToUniversalTime();
}

public sealed record AutomationAccountSnapshot(
    IReadOnlyList<AutomationJob> Jobs,
    IReadOnlyList<AutomationHistoryItem> History);
