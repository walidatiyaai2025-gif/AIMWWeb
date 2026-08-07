using System.Text.Encodings.Web;
using AIWordPressManager.Application.Abstractions.Email;

namespace AIWordPressManager.Web.Services;

public sealed class EmailTemplateRenderer : IEmailTemplateRenderer
{
    private static readonly IReadOnlyDictionary<string, TemplateDefinition> Definitions =
        new Dictionary<string, TemplateDefinition>(StringComparer.OrdinalIgnoreCase)
        {
            [EmailTemplateKeys.SiteOperationalReport] = new(
                "Site operational report",
                "تقرير تشغيل الموقع",
                ["SiteName", "Status", "GeneratedAt"],
                ["Summary", "SiteUrl"],
                "[{SiteName}] Operational report - {Status}",
                "تقرير تشغيل [{SiteName}] - {Status}",
                "Site: {SiteName}\nStatus: {Status}\nGenerated: {GeneratedAt}\n{Summary}\n{SiteUrl}",
                "الموقع: {SiteName}\nالحالة: {Status}\nوقت الإنشاء: {GeneratedAt}\n{Summary}\n{SiteUrl}"),

            [EmailTemplateKeys.SiteSeoSummary] = new(
                "Site SEO summary",
                "ملخص SEO للموقع",
                ["SiteName", "SeoScore", "GeneratedAt"],
                ["CriticalIssues", "Summary", "SiteUrl"],
                "[{SiteName}] SEO summary - score {SeoScore}",
                "ملخص SEO [{SiteName}] - النتيجة {SeoScore}",
                "Site: {SiteName}\nSEO score: {SeoScore}\nCritical issues: {CriticalIssues}\nGenerated: {GeneratedAt}\n{Summary}\n{SiteUrl}",
                "الموقع: {SiteName}\nنتيجة SEO: {SeoScore}\nالمشكلات الحرجة: {CriticalIssues}\nوقت الإنشاء: {GeneratedAt}\n{Summary}\n{SiteUrl}"),

            [EmailTemplateKeys.SiteSyncFailure] = new(
                "Site synchronization failure",
                "فشل مزامنة الموقع",
                ["SiteName", "FailureReason", "OccurredAt"],
                ["CorrelationId", "SiteUrl"],
                "[{SiteName}] Synchronization failed",
                "فشل مزامنة [{SiteName}]",
                "Site: {SiteName}\nFailure: {FailureReason}\nOccurred: {OccurredAt}\nCorrelation: {CorrelationId}\n{SiteUrl}",
                "الموقع: {SiteName}\nسبب الفشل: {FailureReason}\nوقت الحدوث: {OccurredAt}\nرقم التتبع: {CorrelationId}\n{SiteUrl}"),

            [EmailTemplateKeys.DashboardDigest] = new(
                "Dashboard digest",
                "ملخص الداشبورد",
                ["AccountName", "SiteCount", "GeneratedAt"],
                ["HealthySites", "WarningSites", "FailedSites", "Summary"],
                "AI WordPress Manager dashboard digest - {GeneratedAt}",
                "ملخص داشبورد AI WordPress Manager - {GeneratedAt}",
                "Account: {AccountName}\nSites: {SiteCount}\nHealthy: {HealthySites}\nWarnings: {WarningSites}\nFailed: {FailedSites}\nGenerated: {GeneratedAt}\n{Summary}",
                "الحساب: {AccountName}\nالمواقع: {SiteCount}\nالسليمة: {HealthySites}\nتحذيرات: {WarningSites}\nفشل: {FailedSites}\nوقت الإنشاء: {GeneratedAt}\n{Summary}"),

            [EmailTemplateKeys.SecurityAlert] = new(
                "Security alert",
                "تنبيه أمني",
                ["AccountName", "EventName", "OccurredAt"],
                ["Source", "CorrelationId", "Details"],
                "Security alert - {EventName}",
                "تنبيه أمني - {EventName}",
                "Account: {AccountName}\nEvent: {EventName}\nOccurred: {OccurredAt}\nSource: {Source}\nCorrelation: {CorrelationId}\n{Details}",
                "الحساب: {AccountName}\nالحدث: {EventName}\nوقت الحدوث: {OccurredAt}\nالمصدر: {Source}\nرقم التتبع: {CorrelationId}\n{Details}"),

            [EmailTemplateKeys.BillingEvent] = new(
                "Billing event",
                "حدث فوترة",
                ["AccountName", "BillingStatus", "OccurredAt"],
                ["PlanName", "Amount", "Currency", "Reference", "Details"],
                "Billing update - {BillingStatus}",
                "تحديث الفوترة - {BillingStatus}",
                "Account: {AccountName}\nStatus: {BillingStatus}\nPlan: {PlanName}\nAmount: {Amount} {Currency}\nReference: {Reference}\nOccurred: {OccurredAt}\n{Details}",
                "الحساب: {AccountName}\nالحالة: {BillingStatus}\nالخطة: {PlanName}\nالقيمة: {Amount} {Currency}\nالمرجع: {Reference}\nوقت الحدوث: {OccurredAt}\n{Details}")
        };

    public IReadOnlyList<EmailTemplateDescriptor> GetCatalog() => Definitions
        .Select(x => new EmailTemplateDescriptor(
            x.Key,
            x.Value.NameEn,
            x.Value.NameAr,
            x.Value.RequiredTokens,
            x.Value.OptionalTokens))
        .OrderBy(x => x.Key, StringComparer.Ordinal)
        .ToArray();

    public EmailTemplateRenderResult Render(EmailTemplateRenderRequest request)
    {
        ArgumentNullException.ThrowIfNull(request);
        if (string.IsNullOrWhiteSpace(request.TemplateKey))
            throw new ArgumentException("Template key is required.", nameof(request));

        if (!Definitions.TryGetValue(request.TemplateKey.Trim(), out var definition))
            throw new KeyNotFoundException($"Email template '{request.TemplateKey}' is not registered.");

        var culture = NormalizeCulture(request.Culture);
        var values = request.Values ?? new Dictionary<string, string?>();
        ValidateRequiredTokens(definition, values);

        var isArabic = culture == "ar";
        var subjectTemplate = isArabic ? definition.SubjectAr : definition.SubjectEn;
        var bodyTemplate = isArabic ? definition.BodyAr : definition.BodyEn;

        var subject = RenderSubject(subjectTemplate, definition, values);
        var textBody = RenderText(bodyTemplate, definition, values);
        var htmlContent = RenderHtml(bodyTemplate, definition, values);
        var direction = isArabic ? "rtl" : "ltr";
        var language = isArabic ? "ar" : "en";

        var htmlBody = $"""
<!doctype html>
<html lang="{language}" dir="{direction}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{HtmlEncoder.Default.Encode(subject)}</title>
</head>
<body style="margin:0;background:#f3f4f6;font-family:Segoe UI,Arial,sans-serif;color:#111827;direction:{direction};text-align:{(isArabic ? "right" : "left")}">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:12px;border:1px solid #e5e7eb">
<tr><td style="padding:22px 26px;border-bottom:1px solid #e5e7eb;font-weight:700;font-size:18px">AI WordPress Manager</td></tr>
<tr><td style="padding:26px;white-space:normal;line-height:1.65;font-size:14px">{htmlContent}</td></tr>
<tr><td style="padding:16px 26px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px">{(isArabic ? "تم إنشاء هذه الرسالة تلقائيًا بواسطة AI WordPress Manager." : "This message was generated automatically by AI WordPress Manager.")}</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
""";

        return new EmailTemplateRenderResult(
            request.TemplateKey.Trim(),
            culture,
            direction,
            subject,
            htmlBody,
            textBody);
    }

    private static string NormalizeCulture(string? culture) =>
        culture?.Trim().StartsWith("ar", StringComparison.OrdinalIgnoreCase) == true ? "ar" : "en";

    private static void ValidateRequiredTokens(
        TemplateDefinition definition,
        IReadOnlyDictionary<string, string?> values)
    {
        foreach (var token in definition.RequiredTokens)
        {
            if (!TryGetValue(values, token, out var value) || string.IsNullOrWhiteSpace(value))
                throw new InvalidOperationException($"Required email template value '{token}' was not provided.");
        }
    }

    private static string RenderSubject(
        string template,
        TemplateDefinition definition,
        IReadOnlyDictionary<string, string?> values)
    {
        var result = template;
        foreach (var token in AllTokens(definition))
        {
            TryGetValue(values, token, out var value);
            var safe = (value ?? string.Empty).Replace('\r', ' ').Replace('\n', ' ').Trim();
            result = result.Replace("{" + token + "}", safe, StringComparison.Ordinal);
        }
        return result.Trim();
    }

    private static string RenderText(
        string template,
        TemplateDefinition definition,
        IReadOnlyDictionary<string, string?> values)
    {
        var result = template;
        foreach (var token in AllTokens(definition))
        {
            TryGetValue(values, token, out var value);
            var safe = NormalizeTextValue(value);
            result = result.Replace("{" + token + "}", safe, StringComparison.Ordinal);
        }
        return CleanupText(result);
    }

    private static string RenderHtml(
        string template,
        TemplateDefinition definition,
        IReadOnlyDictionary<string, string?> values)
    {
        var result = template;
        foreach (var token in AllTokens(definition))
        {
            TryGetValue(values, token, out var value);
            var encoded = HtmlEncoder.Default.Encode(NormalizeTextValue(value));
            result = result.Replace("{" + token + "}", encoded, StringComparison.Ordinal);
        }

        var lines = result.Split('\n')
            .Select(x => x.Trim())
            .Where(x => x.Length > 0)
            .Select(x => $"<div style=\"margin:0 0 8px\">{x}</div>");
        return string.Join(string.Empty, lines);
    }

    private static IEnumerable<string> AllTokens(TemplateDefinition definition) =>
        definition.RequiredTokens.Concat(definition.OptionalTokens).Distinct(StringComparer.OrdinalIgnoreCase);

    private static bool TryGetValue(
        IReadOnlyDictionary<string, string?> values,
        string key,
        out string? value)
    {
        if (values.TryGetValue(key, out value)) return true;
        foreach (var item in values)
        {
            if (string.Equals(item.Key, key, StringComparison.OrdinalIgnoreCase))
            {
                value = item.Value;
                return true;
            }
        }
        value = null;
        return false;
    }

    private static string NormalizeTextValue(string? value) =>
        (value ?? string.Empty).Replace("\r\n", "\n", StringComparison.Ordinal).Replace('\r', '\n').Trim();

    private static string CleanupText(string value)
    {
        var lines = value.Split('\n').Select(x => x.TrimEnd()).ToArray();
        var compact = new List<string>(lines.Length);
        var previousBlank = false;
        foreach (var line in lines)
        {
            var blank = string.IsNullOrWhiteSpace(line);
            if (blank && previousBlank) continue;
            compact.Add(line);
            previousBlank = blank;
        }
        return string.Join(Environment.NewLine, compact).Trim();
    }

    private sealed record TemplateDefinition(
        string NameEn,
        string NameAr,
        IReadOnlyList<string> RequiredTokens,
        IReadOnlyList<string> OptionalTokens,
        string SubjectEn,
        string SubjectAr,
        string BodyEn,
        string BodyAr);
}
