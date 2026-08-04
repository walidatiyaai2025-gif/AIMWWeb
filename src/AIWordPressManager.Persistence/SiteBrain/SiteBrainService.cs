using System.Text.Json;
using AIWordPressManager.Application.SiteBrain;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.SiteBrain;

public sealed class SiteBrainService(AppDbContext dbContext) : ISiteBrainService
{
    private static string Key(Guid siteId) => $"SiteBrain.{siteId:N}";

    public async Task<SiteBrainProfile> GetAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var key = Key(siteId);
        var value = await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == key)
            .Select(x => x.Value)
            .SingleOrDefaultAsync(cancellationToken);
        if (string.IsNullOrWhiteSpace(value)) return SiteBrainProfile.CreateDefault(siteId);
        try
        {
            return JsonSerializer.Deserialize<SiteBrainProfile>(value) ?? SiteBrainProfile.CreateDefault(siteId);
        }
        catch (JsonException)
        {
            return SiteBrainProfile.CreateDefault(siteId);
        }
    }

    public async Task SaveAsync(SiteBrainProfile profile, CancellationToken cancellationToken = default)
    {
        var key = Key(profile.SiteId);
        var value = JsonSerializer.Serialize(profile with { UpdatedAtUtc = DateTime.UtcNow });
        var row = await dbContext.ApplicationSettings.SingleOrDefaultAsync(x => x.Key == key, cancellationToken);
        if (row is null) dbContext.ApplicationSettings.Add(new ApplicationSetting(key, value, DateTime.UtcNow));
        else row.SetValue(key, value, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }
}
