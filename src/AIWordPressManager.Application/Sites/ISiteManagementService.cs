using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.Sites;

public interface ISiteManagementService
{
    Task<IReadOnlyList<SiteListItemDto>> GetSitesAsync(CancellationToken cancellationToken = default);
    Task<SiteDetailsDto?> GetDetailsAsync(Guid siteId, CancellationToken cancellationToken = default);
    Task<SiteConnectionDataDto?> GetConnectionDataAsync(Guid siteId, CancellationToken cancellationToken = default);
    Task<Result<Guid>> CreateAsync(CreateSiteRequest request, CancellationToken cancellationToken = default);
    Task<Result> UpdateConnectionResultAsync(
        Guid siteId,
        bool succeeded,
        string? homeUrl,
        string? wordPressVersion,
        string? languageCode,
        CancellationToken cancellationToken = default);
    Task<Result> DeleteAsync(Guid siteId, CancellationToken cancellationToken = default);
}
