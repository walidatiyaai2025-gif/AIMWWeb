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
        var lastCheck = sites
            .Where(x => x.LastConnectionTestAtUtc.HasValue)
            .Select(x => x.LastConnectionTestAtUtc)
            .OrderByDescending(x => x)
            .FirstOrDefault();

        return new DashboardSummary(
            sites.Count,
            sites.Count(x => x.ConnectionStatus == SiteConnectionStatus.Connected),
            sites.Count(x => x.ConnectionStatus is SiteConnectionStatus.Unreachable
                or SiteConnectionStatus.AuthenticationFailed
                or SiteConnectionStatus.LimitedPermissions),
            lastCheck);
    }

    public async Task<Guid> AddSiteAsync(string name, string url, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(name))
            throw new InvalidOperationException("اسم الموقع مطلوب.");
        if (name.Trim().Length > 150)
            throw new InvalidOperationException("اسم الموقع طويل جدًا.");
        if (!Uri.TryCreate(url?.Trim(), UriKind.Absolute, out var uri) || uri.Scheme is not ("http" or "https"))
            throw new InvalidOperationException("رابط الموقع غير صالح ويجب أن يبدأ بـ http أو https.");

        var normalized = uri.GetLeftPart(UriPartial.Authority).TrimEnd('/');
        if (await dbContext.Sites.AnyAsync(x => x.SiteUrl == normalized, cancellationToken))
            throw new InvalidOperationException("الموقع مضاف بالفعل.");

        var site = new Site(name.Trim(), new Uri(normalized), DateTime.UtcNow);
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
        if (string.IsNullOrWhiteSpace(userName))
            throw new InvalidOperationException("اسم مستخدم WordPress مطلوب.");
        if (string.IsNullOrWhiteSpace(applicationPassword))
            throw new InvalidOperationException("كلمة مرور التطبيق مطلوبة.");

        var site = await dbContext.Sites.FirstOrDefaultAsync(x => x.Id == siteId, cancellationToken)
            ?? throw new InvalidOperationException("الموقع غير موجود.");

        var cleanUserName = userName.Trim();
        var cleanPassword = applicationPassword.Trim();
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

        var result = await connectionTester.TestAsync(
            new WordPressConnectionRequest(site.SiteUrl, cleanUserName, cleanPassword),
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
            site.RecordConnectionStatus(SiteConnectionStatus.AuthenticationFailed, DateTime.UtcNow);
            await dbContext.SaveChangesAsync(cancellationToken);
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
        site.RecordConnectionStatus(ClassifyConnectionStatus(result), now);
    }

    private static SiteConnectionStatus ClassifyConnectionStatus(WordPressConnectionResult result)
    {
        if (result.IsSuccess) return SiteConnectionStatus.Connected;

        var details = $"{result.Message} {result.Diagnostics}";
        if (ContainsAny(details, "401", "unauthorized", "incorrect password", "invalid username", "اسم المستخدم", "كلمة المرور"))
            return SiteConnectionStatus.AuthenticationFailed;
        if (ContainsAny(details, "403", "forbidden", "permission", "capability", "صلاحيات", "غير مسموح"))
            return SiteConnectionStatus.LimitedPermissions;

        return SiteConnectionStatus.Unreachable;
    }

    private static bool ContainsAny(string value, params string[] terms) =>
        terms.Any(term => value.Contains(term, StringComparison.OrdinalIgnoreCase));
}

public sealed record DashboardSummary(int TotalSites, int ConnectedSites, int ProblemSites, DateTime? LastConnectionTestAtUtc);
public sealed record SiteCredentialSummary(string UserName, bool HasSavedPassword);
public sealed record ConnectionTestViewResult(bool IsSuccess, string Message, string? Diagnostics);
