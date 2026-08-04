namespace AIWordPressManager.Application.Abstractions.WordPress;

public sealed record WordPressExplorerSnapshot(
    IReadOnlyList<WordPressContentItem> Posts,
    IReadOnlyList<WordPressContentItem> Pages,
    IReadOnlyList<WordPressCategoryItem> Categories,
    IReadOnlyList<WordPressTagItem> Tags,
    IReadOnlyList<WordPressMediaItem> Media,
    int TotalPosts,
    int TotalPages,
    int TotalCategories,
    int TotalTags,
    int TotalMedia,
    DateTimeOffset LoadedAt,
    WordPressSyncSummary SyncSummary);

public sealed record WordPressContentItem(
    int Id, string Title, string Slug, string Status, string Link, DateTimeOffset? ModifiedAt, string RenderedContent, string RenderedExcerpt);

public sealed record WordPressCategoryItem(int Id, string Name, string Slug, int Count);
public sealed record WordPressTagItem(int Id, string Name, string Slug, int Count);
public sealed record WordPressMediaItem(int Id, string Title, string Slug, string MediaType, string MimeType, string SourceUrl, DateTimeOffset? ModifiedAt);

public sealed record WordPressSyncSummary(
    int ContentInserted, int ContentUpdated, int ContentUnavailable,
    int CategoriesInserted, int CategoriesUpdated, int CategoriesUnavailable,
    int TagsInserted, int TagsUpdated, int TagsUnavailable,
    int MediaInserted, int MediaUpdated, int MediaUnavailable)
{
    public static WordPressSyncSummary Empty { get; } = new(0,0,0,0,0,0,0,0,0,0,0,0);
    public int TotalChanged => ContentInserted + ContentUpdated + CategoriesInserted + CategoriesUpdated + TagsInserted + TagsUpdated + MediaInserted + MediaUpdated;
}
