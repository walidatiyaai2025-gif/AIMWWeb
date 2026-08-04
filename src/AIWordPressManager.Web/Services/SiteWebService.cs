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
        return new DashboardSummary(
            sites.Count,
            sites.Count(x => x.ConnectionStatus == SiteConnectionStatus.Connected),
            sites.Count(x => x.ConnectionStatus is SiteConnectionStatus.Unreachable or SiteConnectionStatus.AuthenticationFailed),
            sites.Max(x => (DateTime?)x.LastConnectionTestAtUtc));
    }

    public async Task<Guid> AddSiteAsync(string name, string url, CancellationToken cancellationToken = default)
    {
        var uri = new Uri(url.Trim(), UriKind.Absolute);
        var normalized = uri.GetLeftPart(UriPartial.Authority).TrimEnd('/');
        if (await dbContext.Sites.AnyAsync(x => x.SiteUrl == normalized, cancellationToken))
            throw new InvalidOperationException("الموقع مضاف بالفعل.");

        var site = new Site(name.Trim(), uri, DateTime.UtcNow);
        dbContext.Sites.Add(site);
        await dbContext.SaveChangesAsync(cancellationToken);
        return site.Id;
    }

    public async Task<ConnectionTestViewResult> SaveCredentialAndTestAsync(
        Guid siteId,
        string userName,
        string applicationPassword,
        CancellationToken cancellationToken = default)
    {
        var site = await dbContext.Sites.FirstOrDefaultAsync(x => x.Id == siteId, cancellationToken)
            ?? throw new InvalidOperationException("الموقع غير موجود.");

        var protectedPassword = await secretProtectionService.ProtectAsync(applicationPassword, cancellationToken);
        var credential = await dbContext.SiteCredentials.FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken);
        if (credential is null)
        {
            credential = new SiteCredential(siteId, userName, protectedPassword, DateTime.UtcNow);
            dbContext.SiteCredentials.Add(credential);
        }
        else
        {
            credential.SetUserName(userName, DateTime.UtcNow);
            credential.SetProtectedApplicationPassword(protectedPassword, DateTime.UtcNow);
        }

        var result = await connectionTester.TestAsync(
            new WordPressConnectionRequest(site.SiteUrl, userName, applicationPassword),
            cancellationToken);

        ApplyConnectionResult(site, result);
        await dbContext.SaveChangesAsync(cancellationToken);
        return new ConnectionTestViewResult(result.IsSuccess, result.Message, result.Diagnostics);
    }

    public async Task<ConnectionTestViewResult> RetestAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var site = await dbContext.Sites.FirstOrDefaultAsync(x => x.Id == siteId, cancellationToken)
            ?? throw new InvalidOperationException("الموقع غير موجود.");
        var credential = await dbContext.SiteCredentials.AsNoTracking()
            .FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken);

        if (credential is null)
            return new(false, "أدخل بيانات WordPress واحفظها أولًا.", null);

        string password;
        try
        {
            password = await secretProtectionService.UnprotectAsync(credential.ProtectedApplicationPassword, cancellationToken);
        }
        catch (Exception ex)
        {
            return new(false, "تعذر قراءة كلمة المرور المشفّرة. أعد إدخال بيانات الاعتماد.", ex.Message);
        }

        var result = await connectionTester.TestAsync(
            new WordPressConnectionRequest(site.SiteUrl, credential.UserName, password),
            cancellationToken);
        ApplyConnectionResult(site, result);
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

    private static void ApplyConnectionResult(Site site, WordPressConnectionResult result)
    {
        var now = DateTime.UtcNow;
        site.UpdateDiscovery(result.HomeUrl, result.WordPressVersion, result.LanguageCode, now);
        site.RecordConnectionStatus(
            result.IsSuccess
                ? SiteConnectionStatus.Connected
                : result.Message.Contains("اسم المستخدم", StringComparison.Ordinal)
                    ? SiteConnectionStatus.AuthenticationFailed
                    : SiteConnectionStatus.Unreachable,
            now);
    }
}

public sealed record DashboardSummary(int TotalSites, int ConnectedSites, int ProblemSites, DateTime? LastConnectionTestAtUtc);
public sealed record SiteCredentialSummary(string UserName, bool HasSavedPassword);
public sealed record ConnectionTestViewResult(bool IsSuccess, string Message, string? Diagnostics);
