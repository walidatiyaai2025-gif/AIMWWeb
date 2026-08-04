namespace AIWordPressManager.Application.SiteBrain;

public interface ISiteBrainService
{
    Task<SiteBrainProfile> GetAsync(Guid siteId, CancellationToken cancellationToken = default);
    Task SaveAsync(SiteBrainProfile profile, CancellationToken cancellationToken = default);
}
