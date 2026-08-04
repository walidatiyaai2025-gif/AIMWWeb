namespace AIWordPressManager.Application.Deletion;

public sealed record ContentDeletionTarget(
    Guid SiteId,
    int WordPressId,
    string ContentType,
    string Title,
    string Status,
    string Link,
    string RenderedContent);

public sealed record MediaDeletionImpact(
    int WordPressId,
    string Title,
    string SourceUrl,
    string MimeType,
    int ReferenceCount,
    IReadOnlyList<string> ReferencedBy,
    bool UsedBySelectedContent,
    bool SafeToDeleteWithSelectedContent);

public sealed record ContentDeletionPreview(
    ContentDeletionTarget Target,
    IReadOnlyList<MediaDeletionImpact> RelatedMedia,
    int SharedMediaCount,
    int ExclusiveMediaCount,
    string Summary);

public interface IWordPressDeletionImpactStore
{
    Task<ContentDeletionPreview?> BuildPreviewAsync(
        Guid siteId,
        string contentType,
        int wordPressId,
        CancellationToken cancellationToken = default);

    Task<MediaDeletionImpact?> BuildMediaPreviewAsync(
        Guid siteId,
        int mediaWordPressId,
        CancellationToken cancellationToken = default);
}
