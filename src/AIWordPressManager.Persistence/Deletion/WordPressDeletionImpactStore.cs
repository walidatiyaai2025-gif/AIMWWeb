using System.Text.RegularExpressions;
using AIWordPressManager.Application.Deletion;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Deletion;

public sealed partial class WordPressDeletionImpactStore(AppDbContext dbContext) : IWordPressDeletionImpactStore
{
    public async Task<ContentDeletionPreview?> BuildPreviewAsync(
        Guid siteId,
        string contentType,
        int wordPressId,
        CancellationToken cancellationToken = default)
    {
        var target = await dbContext.WordPressContentRecords
            .AsNoTracking()
            .Where(x => x.SiteId == siteId && x.ContentType == contentType && x.WordPressId == wordPressId)
            .Select(x => new ContentDeletionTarget(
                x.SiteId,
                x.WordPressId,
                x.ContentType,
                x.Title,
                x.Status,
                x.Link,
                x.RenderedContent))
            .SingleOrDefaultAsync(cancellationToken);

        if (target is null)
            return null;

        var allContent = await dbContext.WordPressContentRecords
            .AsNoTracking()
            .Where(x => x.SiteId == siteId && x.IsAvailable)
            .Select(x => new { x.WordPressId, x.ContentType, x.Title, x.RenderedContent })
            .ToListAsync(cancellationToken);

        var media = await dbContext.WordPressMediaRecords
            .AsNoTracking()
            .Where(x => x.SiteId == siteId && x.IsAvailable)
            .Select(x => new { x.WordPressId, x.Title, x.SourceUrl, x.MimeType })
            .ToListAsync(cancellationToken);

        var related = new List<MediaDeletionImpact>();
        foreach (var item in media)
        {
            var references = allContent
                .Where(content => ReferencesMedia(content.RenderedContent, item.WordPressId, item.SourceUrl))
                .Select(content => $"{content.ContentType} #{content.WordPressId}: {content.Title}")
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .ToArray();

            var usedByTarget = ReferencesMedia(target.RenderedContent, item.WordPressId, item.SourceUrl);
            if (!usedByTarget)
                continue;

            related.Add(new MediaDeletionImpact(
                item.WordPressId,
                item.Title,
                item.SourceUrl,
                item.MimeType,
                references.Length,
                references,
                true,
                references.Length == 1));
        }

        var shared = related.Count(x => !x.SafeToDeleteWithSelectedContent);
        var exclusive = related.Count(x => x.SafeToDeleteWithSelectedContent);
        var summary = $"The selected {contentType} references {related.Count} media item(s): " +
                      $"{exclusive} exclusive and {shared} shared. Shared media will never be deleted automatically.";

        return new ContentDeletionPreview(target, related, shared, exclusive, summary);
    }

    public async Task<MediaDeletionImpact?> BuildMediaPreviewAsync(
        Guid siteId,
        int mediaWordPressId,
        CancellationToken cancellationToken = default)
    {
        var item = await dbContext.WordPressMediaRecords
            .AsNoTracking()
            .Where(x => x.SiteId == siteId && x.WordPressId == mediaWordPressId)
            .Select(x => new { x.WordPressId, x.Title, x.SourceUrl, x.MimeType })
            .SingleOrDefaultAsync(cancellationToken);

        if (item is null)
            return null;

        var allContent = await dbContext.WordPressContentRecords
            .AsNoTracking()
            .Where(x => x.SiteId == siteId && x.IsAvailable)
            .Select(x => new { x.WordPressId, x.ContentType, x.Title, x.RenderedContent })
            .ToListAsync(cancellationToken);

        var references = allContent
            .Where(content => ReferencesMedia(content.RenderedContent, item.WordPressId, item.SourceUrl))
            .Select(content => $"{content.ContentType} #{content.WordPressId}: {content.Title}")
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();

        return new MediaDeletionImpact(
            item.WordPressId,
            item.Title,
            item.SourceUrl,
            item.MimeType,
            references.Length,
            references,
            false,
            references.Length == 0);
    }

    private static bool ReferencesMedia(string html, int mediaId, string sourceUrl)
    {
        if (string.IsNullOrWhiteSpace(html))
            return false;

        if (!string.IsNullOrWhiteSpace(sourceUrl) &&
            html.Contains(sourceUrl, StringComparison.OrdinalIgnoreCase))
            return true;

        return Regex.IsMatch(
            html,
            $@"(?:wp-image-|attachment_|data-id=[""']?){mediaId}(?:\D|$)",
            RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
    }
}
