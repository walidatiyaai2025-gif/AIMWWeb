using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Application.Abstractions.Persistence;

public interface IWordPressContentStore
{
    Task<WordPressSyncSummary> SaveSnapshotAsync(Guid siteId, WordPressExplorerSnapshot snapshot, CancellationToken cancellationToken = default);
}
