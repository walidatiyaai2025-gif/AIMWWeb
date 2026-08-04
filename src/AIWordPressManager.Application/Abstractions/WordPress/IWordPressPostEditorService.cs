using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.Abstractions.WordPress;

public interface IWordPressPostEditorService
{
    Task<Result<WordPressEditableContent>> GetAsync(Guid siteId, string contentType, int wordPressId, CancellationToken cancellationToken = default);
    Task<Result<WordPressContentUpdateResult>> UpdateAsync(Guid siteId, WordPressContentUpdateRequest request, CancellationToken cancellationToken = default);
}

public sealed record WordPressEditableContent(
    string ContentType,
    int Id,
    string Title,
    string Slug,
    string Status,
    string Content,
    string Excerpt,
    string Link,
    DateTimeOffset? DateGmt,
    DateTimeOffset? ModifiedGmt,
    int FeaturedMediaId,
    IReadOnlyList<int> CategoryIds,
    IReadOnlyList<int> TagIds,
    string Template,
    int AuthorId,
    string CommentStatus,
    string PingStatus,
    string Format,
    bool Sticky,
    bool PasswordProtected,
    string RawJson);

public sealed record WordPressContentUpdateRequest(
    string ContentType,
    int Id,
    string Title,
    string Slug,
    string Status,
    string Content,
    string Excerpt,
    DateTimeOffset? DateGmt,
    int FeaturedMediaId,
    IReadOnlyList<int> CategoryIds,
    IReadOnlyList<int> TagIds,
    string Template,
    string CommentStatus,
    string PingStatus,
    string Format,
    bool Sticky);

public sealed record WordPressContentUpdateResult(
    bool Succeeded,
    string Message,
    string BackupPath,
    int WordPressId,
    string Status,
    string Link,
    DateTimeOffset? ModifiedGmt);
