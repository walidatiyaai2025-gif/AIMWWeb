using AIWordPressManager.Application.Planning;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Planning;

public sealed class CategoryPlannerService(AppDbContext dbContext) : ICategoryPlannerService
{
    public async Task<CategoryPlanResult> AnalyzeAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var categories = await dbContext.WordPressCategoryRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable).OrderBy(x => x.Name).ToListAsync(cancellationToken);
        var items = categories.Select(x => x.PostCount switch
        {
            0 => new CategoryPlanItem(x.WordPressId, x.Name, x.Slug, x.PostCount, "Empty category: review whether it should be removed, merged, or populated.", "Medium"),
            < 3 => new CategoryPlanItem(x.WordPressId, x.Name, x.Slug, x.PostCount, "Weak category: add supporting content or merge with a closely related category.", "Low"),
            _ => new CategoryPlanItem(x.WordPressId, x.Name, x.Slug, x.PostCount, "Healthy category. Review naming and parent/child structure during semantic clustering.", "Low")
        }).ToArray();
        return new(items, items.Count(x => x.PostCount == 0), items.Count(x => x.PostCount is > 0 and < 3), items.Count(x => x.PostCount >= 3));
    }
}
