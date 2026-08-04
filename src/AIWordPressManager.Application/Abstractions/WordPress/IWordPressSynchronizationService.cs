using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.Abstractions.WordPress;

public interface IWordPressSynchronizationService
{
    Task<Result<WordPressExplorerSnapshot>> SynchronizeAsync(Guid siteId, IProgress<WordPressSyncProgress>? progress = null, CancellationToken cancellationToken = default);
}

public sealed record WordPressSyncProgress(int Percent, string Step);
