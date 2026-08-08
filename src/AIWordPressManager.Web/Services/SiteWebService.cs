using AIWordPressManager.Application.Abstractions;
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
    CurrentUserContext currentUser)
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
        ValidateName(name);
        var normalizedUri = NormalizeSiteUri(url);
        var normalizedKey = GetNormalizedUrlKey(normalizedUri);

        // The database currently has a global UNIQUE index on Sites.SiteUrl.
        // Therefore the duplicate check must also see soft-deleted rows and rows
        // belonging to another owner. Otherwise the query filter can hide a row
        // that SQLite still considers unique and SaveChanges will fail with
        // SQLite error 19.
        var existingSite = await dbContext.Sites
            .IgnoreQueryFilters()
            .SingleOrDefaultAsync(x => x.SiteUrl.TrimEnd('/').ToLower() == normalizedKey, cancellationToken);

        if (existingSite is not null)
        {
            if (existingSite.OwnerUserId != OwnerId)
                throw new InvalidOperationException("This website URL is already registered.");

            if (!existingSite.IsDeleted)
                throw new InvalidOperationException("This site has already been added to your account.");

            existingSite.Restore(DateTime.UtcNow);
            existingSite.SetName(name.Trim(), DateTime.UtcNow);
            existingSite.SetSiteUrl(normalizedUri, DateTime.UtcNow);

            try
            {
                await dbContext.SaveChangesAsync(cancellationToken);
            }
            catch (DbUpdateException ex) when (IsSqliteUniqueConstraintViolation(ex))
            {
                throw new InvalidOperationException("This website URL is already registered.", ex);
            }

            return existingSite.Id;
        }

        var site = new Site(name.Trim(), normalizedUri, DateTime.UtcNow, OwnerId);
        dbContext.Sites.Add(site);

        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
        }
        catch (DbUpdateException ex) when (IsSqliteUniqueConstraintViolation(ex))
        {
            throw new InvalidOperationException("This website URL is already registered.", ex);
        }

        return site.Id;
    }

    public async Task UpdateSiteAsync(Guid siteId, string name, string url, CancellationToken cancellationToken = default)
    {
        ValidateName(name);
        var normalizedUri = NormalizeSiteUri(url);
        await EnsureUniqueUrlAsync(normalizedUri, siteId, cancellationToken);
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
            throw new InvalidOperationException("This website URL is already registered.", ex);
        }
    }

    public async Task SetDisabledAsync(Guid siteId, bool disabled, CancellationToken cancellationToken = default)
    {
        var site = await RequireOwnedSiteAsync(siteId, true, cancellationToken);
        site.RecordConnectionStatus(disabled ? SiteConnectionStatus.Disabled : SiteConnectionStatus.Unknown, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task RemoveCredentialAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var site = await RequireOwnedSiteAsync(siteId, true, cancellationToken);
        var credential = await dbContext.SiteCredentials.FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken);
        if (credential is not null) dbContext.SiteCredentials.Remove(credential);
        site.RecordConnectionStatus(SiteConnectionStatus.AuthenticationFailed, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<ConnectionTestViewResult> SaveCredentialAndTestAsync(Guid siteId, string userName, string applicationPassword, CancellationToken cancellationToken = default)
    {
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

    public async Task DeleteSiteAsync(Guid id, CancellationToken cancellationToken = default)
    {
        var site = await dbContext.Sites.FirstOrDefaultAsync(x => x.Id == id && x.OwnerUserId == OwnerId, cancellationToken);
        if (site is null) return;
        site.SoftDelete(DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task EnsureOwnershipAsync(Guid siteId, CancellationToken cancellationToken = default) =>
        _ = await RequireOwnedSiteAsync(siteId, false, cancellationToken);

    private async Task<Site> RequireOwnedSiteAsync(Guid siteId, bool tracked, CancellationToken cancellationToken)
    {
        var query = tracked ? dbContext.Sites.AsQueryable() : dbContext.Sites.AsNoTracking();
        return await query.FirstOrDefaultAsync(x => x.Id == siteId && x.OwnerUserId == OwnerId, cancellationToken)
            ?? throw new UnauthorizedAccessException("The requested WordPress site does not belong to the signed-in user.");
    }

    private async Task EnsureUniqueUrlAsync(Uri uri, Guid? exceptSiteId, CancellationToken cancellationToken)
    {
        var normalizedKey = GetNormalizedUrlKey(uri);
        var sites = await dbContext.Sites
            .IgnoreQueryFilters()
            .AsNoTracking()
            .Select(x => new { x.Id, x.OwnerUserId, x.IsDeleted, x.SiteUrl })
            .ToListAsync(cancellationToken);

        var conflict = sites.FirstOrDefault(x => x.Id != exceptSiteId && GetNormalizedUrlKey(x.SiteUrl) == normalizedKey);
        if (conflict is null)
            return;

        if (conflict.OwnerUserId != OwnerId)
            throw new InvalidOperationException("This website URL is already registered.");

        throw new InvalidOperationException("This site has already been added to your account.");
    }

    private static string GetNormalizedUrlKey(Uri uri) =>
        uri.GetLeftPart(UriPartial.Authority).TrimEnd('/').ToLowerInvariant();

    private static string GetNormalizedUrlKey(string value) =>
        value.TrimEnd('/').ToLowerInvariant();

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
