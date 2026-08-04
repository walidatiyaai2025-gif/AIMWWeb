namespace AIWordPressManager.Application.Planning;

public interface ICategoryPlannerService
{
    Task<CategoryPlanResult> AnalyzeAsync(Guid siteId, CancellationToken cancellationToken = default);
}

public sealed record CategoryPlanResult(IReadOnlyList<CategoryPlanItem> Items, int EmptyCategories, int WeakCategories, int HealthyCategories);
public sealed record CategoryPlanItem(int WordPressId, string Name, string Slug, int PostCount, string Recommendation, string RiskLevel);
