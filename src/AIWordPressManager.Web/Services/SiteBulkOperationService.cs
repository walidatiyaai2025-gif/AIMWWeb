using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Domain.Enums;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class SiteBulkOperationService(
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    SiteWebService siteService)
{
    private Guid OwnerId => currentUser.UserId;

    public async Task<SiteBulkRetestResult> RetestAsync(IEnumerable<Guid> siteIds, CancellationToken cancellationToken = default)
    {
        var ids = SiteBulkOperationPolicy.NormalizeIds(siteIds);
        var ownedSites = await LoadOwnedSitesAsync(ids, tracked: false, cancellationToken);
        var names = ownedSites.ToDictionary(x => x.Id, x => x.Name);
        var results = new List<SiteBulkRetestItem>(ids.Count);

        foreach (var siteId in ids)
        {
            cancellationToken.ThrowIfCancellationRequested();
            try
            {
                var result = await siteService.RetestAsync(siteId, cancellationToken);
                results.Add(new SiteBulkRetestItem(siteId, names[siteId], result.IsSuccess, result.Message));
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                results.Add(new SiteBulkRetestItem(siteId, names[siteId], false, ex.Message));
            }
        }

        return new SiteBulkRetestResult(
            ids.Count,
            results.Count(x => x.IsSuccess),
            results.Count(x => !x.IsSuccess),
            results);
    }

    public async Task<int> SetDisabledAsync(IEnumerable<Guid> siteIds, bool disabled, CancellationToken cancellationToken = default)
    {
        var ids = SiteBulkOperationPolicy.NormalizeIds(siteIds);
        var sites = await LoadOwnedSitesAsync(ids, tracked: true, cancellationToken);
        var now = DateTime.UtcNow;
        var status = disabled ? SiteConnectionStatus.Disabled : SiteConnectionStatus.Unknown;

        foreach (var site in sites)
            site.RecordConnectionStatus(status, now);

        await dbContext.SaveChangesAsync(cancellationToken);
        return sites.Count;
    }

    public async Task<int> DeleteAsync(IEnumerable<Guid> siteIds, CancellationToken cancellationToken = default)
    {
        var ids = SiteBulkOperationPolicy.NormalizeIds(siteIds);
        var sites = await LoadOwnedSitesAsync(ids, tracked: true, cancellationToken);
        var now = DateTime.UtcNow;

        foreach (var site in sites)
            site.SoftDelete(now);

        await dbContext.SaveChangesAsync(cancellationToken);
        return sites.Count;
    }

    private async Task<List<Site>> LoadOwnedSitesAsync(
        IReadOnlyList<Guid> siteIds,
        bool tracked,
        CancellationToken cancellationToken)
    {
        var ids = siteIds.ToArray();
        IQueryable<Site> query = dbContext.Sites.Where(x => x.OwnerUserId == OwnerId && ids.Contains(x.Id));
        if (!tracked)
            query = query.AsNoTracking();

        var sites = await query.ToListAsync(cancellationToken);
        if (sites.Count != ids.Length)
            throw new UnauthorizedAccessException("One or more selected WordPress sites do not belong to the signed-in user.");

        return sites;
    }
}

public sealed record SiteBulkRetestResult(
    int Requested,
    int Succeeded,
    int Failed,
    IReadOnlyList<SiteBulkRetestItem> Items);

public sealed record SiteBulkRetestItem(
    Guid SiteId,
    string SiteName,
    bool IsSuccess,
    string Message);
