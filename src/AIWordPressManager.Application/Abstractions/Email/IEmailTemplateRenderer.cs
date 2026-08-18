namespace AIWordPressManager.Application.Abstractions.Email;

public interface IEmailTemplateRenderer
{
    IReadOnlyList<EmailTemplateDescriptor> GetCatalog();

    EmailTemplateRenderResult Render(EmailTemplateRenderRequest request);
}

public static class EmailTemplateKeys
{
    public const string SiteOperationalReport = "site.operational-report";
    public const string SiteSeoSummary = "site.seo-summary";
    public const string SiteSyncFailure = "site.sync-failure";
    public const string SiteJobFailure = "site.job-failure";
    public const string DashboardDigest = "account.dashboard-digest";
    public const string SecurityAlert = "account.security-alert";
    public const string BillingEvent = "account.billing-event";
}

public sealed record EmailTemplateRenderRequest(
    string TemplateKey,
    string Culture,
    IReadOnlyDictionary<string, string?> Values);

public sealed record EmailTemplateRenderResult(
    string TemplateKey,
    string Culture,
    string Direction,
    string Subject,
    string HtmlBody,
    string TextBody);

public sealed record EmailTemplateDescriptor(
    string Key,
    string NameEn,
    string NameAr,
    IReadOnlyList<string> RequiredTokens,
    IReadOnlyList<string> OptionalTokens);
