using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.Deletion;

public sealed record WordPressDeletionResult(
    bool Succeeded,
    string Message,
    string? BackupPath = null,
    IReadOnlyList<int>? DeletedMediaIds = null);

public interface IWordPressDeletionService
{
    Task<Result<WordPressDeletionResult>> MoveContentToTrashAsync(
        Guid siteId,
        string contentType,
        int wordPressId,
        CancellationToken cancellationToken = default);

    Task<Result<WordPressDeletionResult>> RestoreContentAsync(
        Guid siteId,
        string contentType,
        int wordPressId,
        string restoreStatus,
        CancellationToken cancellationToken = default);

    Task<Result<WordPressDeletionResult>> DeleteContentPermanentlyAsync(
        Guid siteId,
        string contentType,
        int wordPressId,
        CancellationToken cancellationToken = default);

    Task<Result<WordPressDeletionResult>> DeleteMediaPermanentlyAsync(
        Guid siteId,
        int mediaWordPressId,
        CancellationToken cancellationToken = default);

    Task<Result<WordPressDeletionResult>> DeleteContentAndExclusiveMediaAsync(
        Guid siteId,
        string contentType,
        int wordPressId,
        CancellationToken cancellationToken = default);
}
