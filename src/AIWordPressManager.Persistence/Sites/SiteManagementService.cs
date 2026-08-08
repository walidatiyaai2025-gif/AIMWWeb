using System.Security.Cryptography;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Common.Results;
using AIWordPressManager.Application.Sites;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Domain.Enums;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Sites;

public sealed class SiteManagementService(
    AppDbContext dbContext,
    ISecretProtectionService secretProtectionService,
    IClock clock) : ISiteManagementService
{
    public async Task<IReadOnlyList<SiteListItemDto>> GetSitesAsync(CancellationToken cancellationToken = default)
    {
        return await dbContext.Sites.AsNoTracking()
            .OrderBy(x => x.Name)
            .Select(x => new SiteListItemDto(x.Id, x.Name, x.SiteUrl, x.ConnectionStatus.ToString(), x.LastConnectionTestAtUtc))
            .ToListAsync(cancellationToken);
    }

    public async Task<SiteDetailsDto?> GetDetailsAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        return await dbContext.Sites.AsNoTracking()
            .Where(x => x.Id == siteId)
            .Select(x => new SiteDetailsDto(
                x.Id,
                x.Name,
                x.SiteUrl,
                x.HomeUrl,
                x.WordPressVersion,
                x.LanguageCode,
                x.ConnectionStatus.ToString(),
                x.LastConnectionTestAtUtc,
                dbContext.SiteCredentials.Where(c => c.SiteId == x.Id).Select(c => c.UserName).FirstOrDefault() ?? string.Empty))
            .SingleOrDefaultAsync(cancellationToken);
    }

    public async Task<SiteConnectionDataDto?> GetConnectionDataAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var record = await dbContext.Sites.AsNoTracking()
            .Where(x => x.Id == siteId)
            .Select(x => new
            {
                x.Id,
                x.Name,
                x.SiteUrl,
                Credential = dbContext.SiteCredentials
                    .Where(c => c.SiteId == x.Id)
                    .Select(c => new { c.UserName, c.ProtectedApplicationPassword })
                    .FirstOrDefault()
            })
            .SingleOrDefaultAsync(cancellationToken);

        if (record?.Credential is null)
            return null;

        string password;
        try
        {
            password = await secretProtectionService.UnprotectAsync(record.Credential.ProtectedApplicationPassword, cancellationToken);
        }
        catch (CryptographicException)
        {
            return null;
        }
        catch (FormatException)
        {
            return null;
        }

        return new SiteConnectionDataDto(record.Id, record.Name, record.SiteUrl, record.Credential.UserName, password);
    }

    public async Task<Result<Guid>> CreateAsync(CreateSiteRequest request, CancellationToken cancellationToken = default)
    {
        if (!Uri.TryCreate(request.SiteUrl.Trim(), UriKind.Absolute, out var siteUri) || siteUri.Scheme is not ("http" or "https"))
            return Result.Failure<Guid>(new Error("Sites.InvalidUrl", "Enter a valid HTTP or HTTPS website URL."));

        var normalizedUrl = siteUri.GetLeftPart(UriPartial.Authority).TrimEnd('/');
        var now = clock.UtcNow;

        // A URL is a connection target, not a unique profile. Every CreateAsync
        // call intentionally creates a new Site row, including when a matching
        // active or soft-deleted URL already exists. Credentials and settings
        // therefore remain isolated by Site.Id.
        var site = new Site(request.Name, new Uri(normalizedUrl), now);
        site.UpdateDiscovery(request.HomeUrl, request.WordPressVersion, request.LanguageCode, now);
        site.RecordConnectionStatus(SiteConnectionStatus.Connected, now);

        var protectedPassword = await secretProtectionService.ProtectAsync(request.ApplicationPassword, cancellationToken);
        var credential = new SiteCredential(site.Id, request.UserName, protectedPassword, now);

        dbContext.Sites.Add(site);
        dbContext.SiteCredentials.Add(credential);

        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
        }
        catch (DbUpdateException ex) when (IsSqliteUniqueConstraintViolation(ex))
        {
            return Result.Failure<Guid>(new Error("Sites.CreateFailed", "The site profile could not be created because the database still has a uniqueness constraint on the site URL. Apply the latest database migration and retry."));
        }

        return Result.Success(site.Id);
    }

    public async Task<Result> UpdateConnectionResultAsync(
        Guid siteId,
        bool succeeded,
        string? homeUrl,
        string? wordPressVersion,
        string? languageCode,
        CancellationToken cancellationToken = default)
    {
        var site = await dbContext.Sites.SingleOrDefaultAsync(x => x.Id == siteId, cancellationToken);
        if (site is null)
            return Result.Failure(Error.NotFound("The selected site no longer exists."));

        var now = clock.UtcNow;
        if (succeeded)
            site.UpdateDiscovery(homeUrl, wordPressVersion, languageCode, now);
        site.RecordConnectionStatus(succeeded ? SiteConnectionStatus.Connected : SiteConnectionStatus.Unreachable, now);
        await dbContext.SaveChangesAsync(cancellationToken);
        return Result.Success();
    }

    public async Task<Result> DeleteAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var site = await dbContext.Sites.SingleOrDefaultAsync(x => x.Id == siteId, cancellationToken);
        if (site is null)
            return Result.Failure(Error.NotFound("The selected site no longer exists."));

        site.SoftDelete(clock.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return Result.Success();
    }

    private static bool IsSqliteUniqueConstraintViolation(DbUpdateException exception) =>
        exception.InnerException is SqliteException { SqliteErrorCode: 19 };
}
