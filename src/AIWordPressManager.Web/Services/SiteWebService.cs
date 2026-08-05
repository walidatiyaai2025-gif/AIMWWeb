using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Domain.Enums;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class SiteWebService(
    AppDbContext dbContext,
    IWordPressConnectionTester connectionTester,
    ISecretProtectionService secretProtectionService)
{
    public Task<List<Site>> GetSitesAsync(CancellationToken cancellationToken = default) =>
        dbContext.Sites.AsNoTracking().OrderBy(x => x.Name).ToListAsync(cancellationToken);

    public Task<Site?> GetSiteAsync(Guid id, CancellationToken cancellationToken = default) =>
        dbContext.Sites.AsNoTracking().FirstOrDefaultAsync(x => x.Id == id, cancellationToken);

    public async Task<SiteCredentialSummary?> GetCredentialSummaryAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var credential = await dbContext.SiteCredentials.AsNoTracking()
            .FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken);
        return credential is null ? null : new SiteCredentialSummary(credential.UserName, true);
    }

    public async Task<DashboardSummary> GetSummaryAsync(CancellationToken cancellationToken = default)
    {
        var sites = await dbContext.Sites.AsNoTracking().ToListAsync(cancellationToken);
        var lastCheck = sites.Where(x => x.LastConnectionTestAtUtc.HasValue)
            .Select(x => x.LastConnectionTestAtUtc).OrderByDescending(x => x).FirstOrDefault();

        return new DashboardSummary(
            sites.Count,
            sites.Count(x => x.ConnectionStatus == SiteConnectionStatus.Connected),
            sites.Count(x => x.ConnectionStatus is SiteConnectionStatus.Unreachable or SiteConnectionStatus.AuthenticationFailed or SiteConnectionStatus.LimitedPermissions),
            lastCheck);
    }

    public async Task<Guid> AddSiteAsync(string name, string url, CancellationToken cancellationToken = default)
    {
        ValidateName(name);
        var normalizedUri = NormalizeSiteUri(url);
        await EnsureUniqueUrlAsync(normalizedUri, null, cancellationToken);

        var site = new Site(name.Trim(), normalizedUri, DateTime.UtcNow);
        dbContext.Sites.Add(site);
        await dbContext.SaveChangesAsync(cancellationToken);
        return site.Id;
    }

    public async Task UpdateSiteAsync(Guid siteId, string name, string url, CancellationToken cancellationToken = default)
    {
        ValidateName(name);
        var normalizedUri = NormalizeSiteUri(url);
        await EnsureUniqueUrlAsync(normalizedUri, siteId, cancellationToken);

        var site = await dbContext.Sites.FirstOrDefaultAsync(x => x.Id == siteId, cancellationToken)
            ?? throw new InvalidOperationException("Site not found.");

        var now = DateTime.UtcNow;
        site.SetName(name.Trim(), now);
        site.SetSiteUrl(normalizedUri, now);
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

        var site = await dbContext.Sites.FirstOrDefaultAsync(x => x.Id == siteId, cancellationToken)
            ?? throw new InvalidOperationException("Site not found.");

        var result = await connectionTester.TestAsync(new WordPressConnectionRequest(site.SiteUrl, cleanUserName, cleanPassword), cancellationToken);
        var classifiedStatus = ClassifyConnectionStatus(result);
        ApplyConnectionResult(site, result, classifiedStatus);

        if (classifiedStatus is SiteConnectionStatus.Connected or SiteConnectionStatus.LimitedPermissions)
        {
            var protectedPassword = await secretProtectionService.ProtectAsync(cleanPassword, cancellationToken);
            var credential = await dbContext.SiteCredentials.FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken);
            if (credential is null)
            {
                credential = new SiteCredential(siteId, cleanUserName, protectedPassword, DateTime.UtcNow);
                dbContext.SiteCredentials.Add(credential);
            }
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
        var site = await dbContext.Sites.FirstOrDefaultAsync(x => x.Id == siteId, cancellationToken)
            ?? throw new InvalidOperationException("Site not found.");
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
        var site = await dbContext.Sites.FirstOrDefaultAsync(x => x.Id == id, cancellationToken);
        if (site is null) return;
        site.SoftDelete(DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private async Task EnsureUniqueUrlAsync(Uri uri, Guid? exceptSiteId, CancellationToken cancellationToken)
    {
        var normalizedKey = uri.GetLeftPart(UriPartial.Authority).TrimEnd('/').ToLowerInvariant();
        var sites = await dbContext.Sites.AsNoTracking().Select(x => new { x.Id, x.SiteUrl }).ToListAsync(cancellationToken);
        if (sites.Any(x => x.Id != exceptSiteId && x.SiteUrl.TrimEnd('/').ToLowerInvariant() == normalizedKey))
            throw new InvalidOperationException("This site has already been added.");
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

        var builder = new UriBuilder(uri) { Query = string.Empty, Fragment = string.Empty, Host = uri.Host.ToLowerInvariant() };
        builder.Path = "/";
        return builder.Uri;
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
        if (ContainsAny(details, "401", "unauthorized", "authentication", "invalid credentials", "incorrect password", "invalid username", "application password", "اسم المستخدم", "كلمة المرور"))
            return SiteConnectionStatus.AuthenticationFailed;
        if (ContainsAny(details, "403", "forbidden", "permission", "capability", "insufficient privileges", "rest_cannot", "صلاحيات", "غير مسموح"))
            return SiteConnectionStatus.LimitedPermissions;
        return SiteConnectionStatus.Unreachable;
    }

    private static bool ContainsAny(string value, params string[] terms) => terms.Any(term => value.Contains(term, StringComparison.OrdinalIgnoreCase));
}

public sealed record DashboardSummary(int TotalSites, int ConnectedSites, int ProblemSites, DateTime? LastConnectionTestAtUtc);
public sealed record SiteCredentialSummary(string UserName, bool HasSavedPassword);
public sealed record ConnectionTestViewResult(bool IsSuccess, string Message, string? Diagnostics);
