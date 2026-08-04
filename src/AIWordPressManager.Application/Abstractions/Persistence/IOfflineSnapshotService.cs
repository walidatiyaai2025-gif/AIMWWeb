using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Application.Abstractions.Persistence;

public interface IOfflineSnapshotService
{
    Task<WordPressExplorerSnapshot> LoadAsync(Guid siteId, CancellationToken cancellationToken = default);
    Task<DateTimeOffset?> GetLastSyncAsync(Guid siteId, CancellationToken cancellationToken = default);
}
