using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class MediaCacheStateService(
    AppDbContext dbContext,
    SiteWebService siteService)
{
    public async Task MarkUnavailableAsync(
        Guid siteId,
        int wordPressMediaId,
        CancellationToken cancellationToken = default)
    {
        if (wordPressMediaId <= 0)
            throw new ArgumentOutOfRangeException(nameof(wordPressMediaId));

        await siteService.EnsureOwnershipAsync(siteId, cancellationToken);
        var record = await dbContext.WordPressMediaRecords
            .SingleOrDefaultAsync(
                x => x.SiteId == siteId && x.WordPressId == wordPressMediaId,
                cancellationToken);

        if (record is null) return;

        // Preserve the last synchronization cursor. A local delete reconciliation must not
        // advance the delta-sync watermark and accidentally hide unrelated remote changes.
        var synchronizationCursor = record.LastSynchronizedAtUtc == default
            ? DateTime.UtcNow
            : record.LastSynchronizedAtUtc;
        record.MarkUnavailable(synchronizationCursor);
        await dbContext.SaveChangesAsync(cancellationToken);
    }
}
