using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.Abstractions.WordPress;

public interface IWordPressExplorerService
{
    Task<Result<WordPressExplorerSnapshot>> LoadAsync(Guid siteId, CancellationToken cancellationToken = default);
}
