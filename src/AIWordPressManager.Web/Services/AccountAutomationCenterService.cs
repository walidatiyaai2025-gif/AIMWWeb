using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
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
    IAccountEntitlementEnforcementService entitlementEnforcement)
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
        var jobs = await GetJobsAsync(cancellationToken);
        var ownedJobIds = jobs.Select(x => x.Id).ToHashSet();
        return automation.GetHistory(Math.Clamp(take, 1, 500))
            .Where(x => ownedJobIds.Contains(x.JobId))
            .ToList();
    }

    public async Task<Guid> SaveAsync(AutomationJobEditModel model, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(model);
        AutomationCenterService.RequireSupportedType(model.Type);
        var site = await RequireOwnedSiteAsync(model.SiteId, cancellationToken);
        var existing = model.Id == Guid.Empty
            ? null
            : automation.GetJobs().FirstOrDefault(x => x.Id == model.Id);

        if (existing is not null)
            await RequireOwnedSiteAsync(existing.SiteId, cancellationToken);
        else
        {
            var currentUsage = (await GetJobsAsync(cancellationToken)).LongCount();
            await entitlementEnforcement.RequireAdditionalUsageAsync(
                OwnerId,
                EntitlementDefinitionCatalog.AutomationSchedulesMax,
                currentUsage,
                1,
                cancellationToken);
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
        var job = automation.GetJobs().FirstOrDefault(x => x.Id == id)
            ?? throw new KeyNotFoundException("Automation job was not found.");
        _ = await RequireOwnedSiteAsync(job.SiteId, cancellationToken);
        return job;
    }

    private async Task<Site> RequireOwnedSiteAsync(Guid siteId, CancellationToken cancellationToken)
    {
        if (siteId == Guid.Empty)
            throw new InvalidOperationException("Select a WordPress site.");
        return await dbContext.Sites.AsNoTracking()
            .FirstOrDefaultAsync(x => x.Id == siteId && x.OwnerUserId == OwnerId, cancellationToken)
            ?? throw new UnauthorizedAccessException("The selected site does not belong to the signed-in user.");
    }

    private async Task<HashSet<Guid>> GetOwnedSiteIdsAsync(CancellationToken cancellationToken) =>
        (await dbContext.Sites.AsNoTracking()
            .Where(x => x.OwnerUserId == OwnerId)
            .Select(x => x.Id)
            .ToListAsync(cancellationToken))
        .ToHashSet();
}
