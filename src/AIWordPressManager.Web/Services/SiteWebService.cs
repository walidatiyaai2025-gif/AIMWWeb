using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Domain.Enums;
using AIWordPressManager.Persistence;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class SiteWebService(
    AppDbContext dbContext,
    IWordPressConnectionTester connectionTester,
    ISecretProtectionService secretProtectionService,
    CurrentUserContext currentUser,
    IAccountEntitlementEnforcementService entitlementEnforcement)
{
    private Guid OwnerId => currentUser.UserId;

    public Task<List<Site>> GetSitesAsync(CancellationToken cancellationToken = default) =>
        dbContext.Sites.AsNoTracking().Where(x => x.OwnerUserId == OwnerId).OrderBy(x => x.Name).ToListAsync(cancellationToken);

    public Task<Site?> GetSiteAsync(Guid id, CancellationToken cancellationToken = default) =>
        dbContext.Sites.AsNoTracking().FirstOrDefaultAsync(x => x.Id == id && x.OwnerUserId == OwnerId, cancellationToken);

    public async Task<SiteCredentialSummary?> GetCredentialSummaryAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        await RequireOwnedSiteAsync(siteId, false, cancellationToken);
        var credential = await dbContext.SiteCredentials.AsNoTracking().FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken);
        return credential is null ? null : new SiteCredentialSummary(credential.UserName, true);
    }

    public async Task<DashboardSummary> GetSummaryAsync(CancellationToken cancellationToken = default)
    {
        var sites = await dbContext.Sites.AsNoTracking().Where(x => x.OwnerUserId == OwnerId).ToListAsync(cancellationToken);
        var lastCheck = sites.Where(x => x.LastConnectionTestAtUtc.HasValue).Select(x => x.LastConnectionTestAtUtc).OrderByDescending(x => x).FirstOrDefault();
        return new DashboardSummary(sites.Count, sites.Count(x => x.ConnectionStatus == SiteConnectionStatus.Connected), sites.Count(x => x.ConnectionStatus is SiteConnectionStatus.Unreachable or SiteConnectionStatus.AuthenticationFailed or SiteConnectionStatus.LimitedPermissions), lastCheck);
    }

    public async Task<Guid> AddSiteAsync(string name, string url, CancellationToken cancellationToken = default)
    {
        RequireManagePermission();
        ValidateName(name);
        var normalizedUri = NormalizeSiteUri(url);
        var currentUsage = await dbContext.Sites.AsNoTracking().LongCountAsync(x => x.OwnerUserId == OwnerId, cancellationToken);
        await entitlementEnforcement.RequireAdditionalUsageAsync(
            OwnerId,
            EntitlementDefinitionCatalog.SitesMax,
            currentUsage,
            1,
            cancellationToken);
        var site = new Site(name.Trim(), normalizedUri, DateTime.UtcNow, OwnerId);
        dbContext.Sites.Add(site);

        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
        }
        catch (DbUpdateException ex) when (IsSqliteUniqueConstraintViolation(ex))
        {
            throw new InvalidOperationException("The site profile could not be created because of a database uniqueness constraint. Apply the latest database migration and try again.", ex);
        }

        return site.Id;
    }

    public async Task UpdateSiteAsync(Guid siteId, string name, string url, CancellationToken cancellationToken = default)
    {
        RequireManagePermission();
        ValidateName(name);
        var normalizedUri = NormalizeSiteUri(url);
        var site = await RequireOwnedSiteAsync(siteId, true, cancellationToken);
        var now = DateTime.UtcNow;
        site.SetName(name.Trim(), now);
        site.SetSiteUrl(normalizedUri, now);

        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
        }
        catch (DbUpdateException ex) when (IsSqliteUniqueConstraintViolation(ex))
        {
            throw new InvalidOperationException("The site profile could not be updated because of a database uniqueness constraint. Apply the latest database migration and try again.", ex);
        }
    }

    public async Task SetDisabledAsync(Guid siteId, bool disabled, CancellationToken cancellationToken = default)
    {
        RequireManagePermission();
        var site = await RequireOwnedSiteAsync(siteId, true, cancellationToken);
        site.RecordConnectionStatus(disabled ? SiteConnectionStatus.Disabled : SiteConnectionStatus.Unknown, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task RemoveCredentialAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        RequireManagePermission();
        var site = await RequireOwnedSiteAsync(siteId, true, cancellationToken);
        var credential = await dbContext.SiteCredentials.FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken);
        if (credential is not null) dbContext.SiteCredentials.Remove(credential);
        site.RecordConnectionStatus(SiteConnectionStatus.AuthenticationFailed, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<ConnectionTestViewResult> SaveCredentialAndTestAsync(Guid siteId, string userName, string applicationPassword, CancellationToken cancellationToken = default)
    {
        RequireManagePermission();
        if (string.IsNullOrWhiteSpace(userName)) throw new InvalidOperationException("WordPress username is required.");
        if (string.IsNullOrWhiteSpace(applicationPassword)) throw new InvalidOperationException("Application Password is required.");
        var cleanUserName = userName.Trim();
        var cleanPassword = applicationPassword.Trim();
        if (cleanUserName.Length > 100) throw new InvalidOperationException("WordPress username is too long.");
        if (cleanPassword.Length < 8) throw new InvalidOperationException("Application Password is too short.");

        var site = await RequireOwnedSiteAsync(siteId, true, cancellationToken);
        var result = await connectionTester.TestAsync(new WordPressConnectionRequest(site.SiteUrl, cleanUserName, cleanPassword), cancellationToken);
        var status = ClassifyConnectionStatus(result);
        ApplyConnectionResult(site, result, status);

        if (status is SiteConnectionStatus.Connected or SiteConnectionStatus.LimitedPermissions)
        {
            var protectedPassword = await secretProtectionService.ProtectAsync(cleanPassword, cancellationToken);
            var credential = await dbContext.SiteCredentials.FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken);
            if (credential is null) dbContext.SiteCredentials.Add(new SiteCredential(siteId, cleanUserName, protectedPassword, DateTime.UtcNow));
            else
            {
                credential.SetUserName(cleanUserName, DateTime.UtcNow);
                credential.SetProtectedApplicationPassword(protectedPassword, DateTime.UtcNow);
            }
        }

        await dbContext.SaveChangesAsync(cancellationToken);
        return new ConnectionTestViewResult(result.IsSuccess, result.Message, result.Diagnostics);
    }

    public async Task<ConnectionTestViewResult> RetestAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        RequireManagePermission();
        var site = await RequireOwnedSiteAsync(siteId, true, cancellationToken);
        var credential = await dbContext.SiteCredentials.AsNoTracking().FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken);
        if (credential is null) return new(false, "Enter and save the WordPress credentials first.", null);

        string password;
        try
        {
            password = await secretProtectionService.UnprotectAsync(credential.ProtectedApplicationPassword, cancellationToken);
        }
        catch (Exception ex)
        {
            site.RecordConnectionStatus(SiteConnectionStatus.AuthenticationFailed, DateTime.UtcNow);
            await dbContext.SaveChangesAsync(cancellationToken);
            return new(false, "The encrypted Application Password could not be read. Re-enter the credentials.", ex.Message);
        }

        var result = await connectionTester.TestAsync(new WordPressConnectionRequest(site.SiteUrl, credential.UserName, password), cancellationToken);
        ApplyConnectionResult(site, result, ClassifyConnectionStatus(result));
        await dbContext.SaveChangesAsync(cancellationToken);
        return new ConnectionTestViewResult(result.IsSuccess, result.Message, result.Diagnostics);
    }

    public async Task<SiteBulkRetestResult> RetestSitesAsync(IEnumerable<Guid> siteIds, CancellationToken cancellationToken = default)
    {
        RequireManagePermission();
        var ids = SiteBulkOperationPolicy.NormalizeIds(siteIds);
        var ownedSites = await RequireOwnedSitesAsync(ids, tracked: false, cancellationToken);
        var names = ownedSites.ToDictionary(x => x.Id, x => x.Name);
        var items = new List<SiteBulkRetestItem>(ids.Count);

        foreach (var siteId in ids)
        {
            cancellationToken.ThrowIfCancellationRequested();
            try
            {
                var result = await RetestAsync(siteId, cancellationToken);
                items.Add(new SiteBulkRetestItem(siteId, names[siteId], result.IsSuccess, result.Message));
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                items.Add(new SiteBulkRetestItem(siteId, names[siteId], false, ex.Message));
            }
        }

        return new SiteBulkRetestResult(ids.Count, items.Count(x => x.IsSuccess), items.Count(x => !x.IsSuccess), items);
    }

    public async Task<int> SetSitesDisabledAsync(IEnumerable<Guid> siteIds, bool disabled, CancellationToken cancellationToken = default)
    {
        RequireManagePermission();
        var ids = SiteBulkOperationPolicy.NormalizeIds(siteIds);
        var sites = await RequireOwnedSitesAsync(ids, tracked: true, cancellationToken);
        var now = DateTime.UtcNow;
        var status = disabled ? SiteConnectionStatus.Disabled : SiteConnectionStatus.Unknown;

        foreach (var site in sites)
            site.RecordConnectionStatus(status, now);

        await dbContext.SaveChangesAsync(cancellationToken);
        return sites.Count;
    }

    public async Task<int> DeleteSitesAsync(IEnumerable<Guid> siteIds, CancellationToken cancellationToken = default)
    {
        RequireManagePermission();
        var ids = SiteBulkOperationPolicy.NormalizeIds(siteIds);
        var sites = await RequireOwnedSitesAsync(ids, tracked: true, cancellationToken);
        var now = DateTime.UtcNow;

        foreach (var site in sites)
            site.SoftDelete(now);

        await dbContext.SaveChangesAsync(cancellationToken);
        return sites.Count;
    }

    public async Task DeleteSiteAsync(Guid id, CancellationToken cancellationToken = default)
    {
        RequireManagePermission();
        var site = await dbContext.Sites.FirstOrDefaultAsync(x => x.Id == id && x.OwnerUserId == OwnerId, cancellationToken);
        if (site is null) return;
        site.SoftDelete(DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task EnsureOwnershipAsync(Guid siteId, CancellationToken cancellationToken = default) =>
        _ = await RequireOwnedSiteAsync(siteId, false, cancellationToken);

    private void RequireManagePermission() =>
        currentUser.RequirePermission(ApplicationPermissionCatalog.SitesManage);

    private async Task<Site> RequireOwnedSiteAsync(Guid siteId, bool tracked, CancellationToken cancellationToken)
    {
        var query = tracked ? dbContext.Sites.AsQueryable() : dbContext.Sites.AsNoTracking();
        return await query.FirstOrDefaultAsync(x => x.Id == siteId && x.OwnerUserId == OwnerId, cancellationToken)
            ?? throw new UnauthorizedAccessException("The requested WordPress site does not belong to the signed-in user.");
    }

    private async Task<List<Site>> RequireOwnedSitesAsync(IReadOnlyList<Guid> siteIds, bool tracked, CancellationToken cancellationToken)
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

    private static void ValidateName(string? name)
    {
        if (string.IsNullOrWhiteSpace(name)) throw new InvalidOperationException("Site name is required.");
        if (name.Trim().Length > 150) throw new InvalidOperationException("Site name cannot exceed 150 characters.");
    }

    private static Uri NormalizeSiteUri(string? value)
    {
        if (!Uri.TryCreate(value?.Trim(), UriKind.Absolute, out var uri) || uri.Scheme is not ("http" or "https"))
            throw new InvalidOperationException("Enter a valid site URL starting with http or https.");
        return new UriBuilder(uri) { Query = string.Empty, Fragment = string.Empty, Host = uri.Host.ToLowerInvariant(), Path = "/" }.Uri;
    }

    private static void ApplyConnectionResult(Site site, WordPressConnectionResult result, SiteConnectionStatus status)
    {
        var now = DateTime.UtcNow;
        site.UpdateDiscovery(result.HomeUrl, result.WordPressVersion, result.LanguageCode, now);
        site.RecordConnectionStatus(status, now);
    }

    private static SiteConnectionStatus ClassifyConnectionStatus(WordPressConnectionResult result)
    {
        if (result.IsSuccess) return SiteConnectionStatus.Connected;
        var details = $"{result.Message} {result.Diagnostics}";
        if (ContainsAny(details, "401", "unauthorized", "authentication", "invalid credentials", "incorrect password", "invalid username", "application password", "اسم المستخدم", "كلمة المرور")) return SiteConnectionStatus.AuthenticationFailed;
        if (ContainsAny(details, "403", "forbidden", "permission", "capability", "insufficient privileges", "rest_cannot", "صلاحيات", "غير مسموح")) return SiteConnectionStatus.LimitedPermissions;
        return SiteConnectionStatus.Unreachable;
    }

    private static bool ContainsAny(string value, params string[] terms) => terms.Any(term => value.Contains(term, StringComparison.OrdinalIgnoreCase));

    private static bool IsSqliteUniqueConstraintViolation(DbUpdateException exception) =>
        exception.InnerException is SqliteException { SqliteErrorCode: 19 };
}

public sealed record DashboardSummary(int TotalSites, int ConnectedSites, int ProblemSites, DateTime? LastConnectionTestAtUtc);
public sealed record SiteCredentialSummary(string UserName, bool HasSavedPassword);
public sealed record ConnectionTestViewResult(bool IsSuccess, string Message, string? Diagnostics);
public sealed record SiteBulkRetestResult(int Requested, int Succeeded, int Failed, IReadOnlyList<SiteBulkRetestItem> Items);
public sealed record SiteBulkRetestItem(Guid SiteId, string SiteName, bool IsSuccess, string Message);
