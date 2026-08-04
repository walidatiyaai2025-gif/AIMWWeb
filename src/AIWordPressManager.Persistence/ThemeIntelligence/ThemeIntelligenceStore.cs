using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.ThemeIntelligence;

public sealed class ThemeIntelligenceStore(AppDbContext dbContext) : IThemeIntelligenceStore
{
    private static string Key(Guid siteId) => $"ThemeIntelligence.{siteId:N}";

    public async Task<ThemeIntelligenceProfile?> GetAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var value = await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == Key(siteId))
            .Select(x => x.Value)
            .SingleOrDefaultAsync(cancellationToken);

        if (string.IsNullOrWhiteSpace(value)) return null;
        try { return JsonSerializer.Deserialize<ThemeIntelligenceProfile>(value); }
        catch (JsonException) { return null; }
    }

    public async Task SaveAsync(ThemeIntelligenceProfile profile, CancellationToken cancellationToken = default)
    {
        var key = Key(profile.SiteId);
        var value = JsonSerializer.Serialize(profile with { UpdatedAtUtc = DateTime.UtcNow });
        var row = await dbContext.ApplicationSettings.SingleOrDefaultAsync(x => x.Key == key, cancellationToken);
        if (row is null) dbContext.ApplicationSettings.Add(new ApplicationSetting(key, value, DateTime.UtcNow));
        else row.SetValue(key, value, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }
}
