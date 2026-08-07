using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class EmailTemplateRendererTests
{
    private readonly EmailTemplateRenderer _renderer = new();

    [Fact]
    public void Catalog_Contains_The_Approved_Core_Template_Keys()
    {
        var keys = _renderer.GetCatalog().Select(x => x.Key).ToArray();

        keys.Should().Contain(EmailTemplateKeys.SiteOperationalReport);
        keys.Should().Contain(EmailTemplateKeys.SiteSeoSummary);
        keys.Should().Contain(EmailTemplateKeys.SiteSyncFailure);
        keys.Should().Contain(EmailTemplateKeys.DashboardDigest);
        keys.Should().Contain(EmailTemplateKeys.SecurityAlert);
        keys.Should().Contain(EmailTemplateKeys.BillingEvent);
    }

    [Fact]
    public void Arabic_Render_Uses_Rtl_And_Arabic_Subject()
    {
        var result = _renderer.Render(new EmailTemplateRenderRequest(
            EmailTemplateKeys.SiteOperationalReport,
            "ar-KW",
            new Dictionary<string, string?>
            {
                ["SiteName"] = "الموقع الرئيسي",
                ["Status"] = "سليم",
                ["GeneratedAt"] = "2026-08-08 10:00"
            }));

        result.Culture.Should().Be("ar");
        result.Direction.Should().Be("rtl");
        result.Subject.Should().Contain("تقرير تشغيل");
        result.HtmlBody.Should().Contain("dir=\"rtl\"");
        result.TextBody.Should().Contain("الموقع الرئيسي");
    }

    [Fact]
    public void English_Render_Uses_Ltr()
    {
        var result = _renderer.Render(new EmailTemplateRenderRequest(
            EmailTemplateKeys.DashboardDigest,
            "en-US",
            new Dictionary<string, string?>
            {
                ["AccountName"] = "Operations",
                ["SiteCount"] = "4",
                ["GeneratedAt"] = "2026-08-08 10:00"
            }));

        result.Culture.Should().Be("en");
        result.Direction.Should().Be("ltr");
        result.Subject.Should().Contain("dashboard digest");
        result.HtmlBody.Should().Contain("dir=\"ltr\"");
    }

    [Fact]
    public void Html_Render_Encodes_Dynamic_Values()
    {
        var result = _renderer.Render(new EmailTemplateRenderRequest(
            EmailTemplateKeys.SecurityAlert,
            "en",
            new Dictionary<string, string?>
            {
                ["AccountName"] = "Admin <script>alert(1)</script>",
                ["EventName"] = "Login <b>warning</b>",
                ["OccurredAt"] = "2026-08-08 10:00",
                ["Details"] = "<img src=x onerror=alert(1)>"
            }));

        result.HtmlBody.Should().NotContain("<script>");
        result.HtmlBody.Should().NotContain("<b>warning</b>");
        result.HtmlBody.Should().NotContain("<img src=x");
        result.HtmlBody.Should().Contain("&lt;script&gt;");
        result.HtmlBody.Should().Contain("&lt;b&gt;warning&lt;/b&gt;");
    }

    [Fact]
    public void Subject_Removes_Header_Newlines_From_Dynamic_Values()
    {
        var result = _renderer.Render(new EmailTemplateRenderRequest(
            EmailTemplateKeys.SecurityAlert,
            "en",
            new Dictionary<string, string?>
            {
                ["AccountName"] = "Admin",
                ["EventName"] = "Warning\r\nBcc: attacker@example.com",
                ["OccurredAt"] = "2026-08-08 10:00"
            }));

        result.Subject.Should().NotContain("\r");
        result.Subject.Should().NotContain("\n");
        result.Subject.Should().Contain("Warning  Bcc: attacker@example.com");
    }

    [Fact]
    public void Missing_Required_Value_Is_Rejected()
    {
        var action = () => _renderer.Render(new EmailTemplateRenderRequest(
            EmailTemplateKeys.SiteSeoSummary,
            "en",
            new Dictionary<string, string?>
            {
                ["SiteName"] = "Example",
                ["GeneratedAt"] = "2026-08-08 10:00"
            }));

        action.Should().Throw<InvalidOperationException>()
            .WithMessage("*SeoScore*");
    }

    [Fact]
    public void Unknown_Template_Key_Is_Rejected()
    {
        var action = () => _renderer.Render(new EmailTemplateRenderRequest(
            "unknown.template",
            "en",
            new Dictionary<string, string?>()));

        action.Should().Throw<KeyNotFoundException>();
    }
}
