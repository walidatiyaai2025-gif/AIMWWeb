namespace AIWordPressManager.Application.SiteBrain;

public sealed record SiteBrainProfile(
    Guid SiteId,
    string PrimaryLanguage,
    string WritingTone,
    string TargetAudience,
    string PreferredSeoPlugin,
    string PreferredPageBuilder,
    string BrandColors,
    string PreferredImageSize,
    string InternalLinkStrategy,
    string CategoryStrategy,
    string ContentRules,
    string DesignRules,
    string RejectedPatterns,
    DateTime UpdatedAtUtc,
    string PrimaryGoal = "Increase organic traffic",
    string TargetKeywords = "",
    string Competitors = "",
    string PublishingSchedule = "2 articles per week",
    bool AutopilotEnabled = false)
{
    public static SiteBrainProfile CreateDefault(Guid siteId) => new(
        siteId,
        "Arabic",
        "Professional",
        "General audience",
        "Auto detect",
        "Auto detect",
        "Black, white and readable gold",
        "1200x630",
        "Natural contextual links",
        "Clear parent and child categories",
        "Factual, concise, no invented statistics",
        "Responsive, accessible, consistent spacing",
        string.Empty,
        DateTime.UtcNow,
        "Increase organic traffic",
        string.Empty,
        string.Empty,
        "2 articles per week",
        false);
}
