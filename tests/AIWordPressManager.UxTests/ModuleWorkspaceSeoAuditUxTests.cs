using System.Reflection;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class ModuleWorkspaceSeoAuditUxTests(UxTestHost host)
{
    [Fact]
    public async Task Generic_SEO_module_uses_real_owned_sites_and_never_renders_mock_findings()
    {
        var siteId = await SeedAdminSiteAsync();
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var response = await page.GotoAsync(
                host.BaseUrl + "/module/seo-audit",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            var picker = page.GetByTestId("seo-audit-site-picker");
            await picker.WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });

            var body = await page.Locator("body").InnerTextAsync();
            body.Should().Contain("SEO Gateway Real Site");
            body.Should().NotContain("Missing meta description on 12 pages");
            body.Should().NotContain("7 images without alt text");
            body.Should().NotContain("Weak internal links in 9 posts");
            body.Should().NotContain("2 titles exceed recommended length");
            body.Should().NotContain("وصف ميتا مفقود في 12 صفحة");
            (await page.Locator(".seo-ring").CountAsync()).Should().Be(0, "the generic module must not render a fabricated SEO score");

            var link = page.GetByTestId("seo-audit-site-link").Filter(new() { HasText = "Open SEO audit" }).First;
            var href = await link.GetAttributeAsync("href");
            href.Should().Be($"/sites/{siteId}/seo");

            await link.ClickAsync();
            await page.WaitForURLAsync(
                url => url.EndsWith($"/sites/{siteId}/seo", StringComparison.OrdinalIgnoreCase),
                new PageWaitForURLOptions { Timeout = 10000 });
            await page.GetByTestId("seo-workspace").WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });

            pageErrors.Should().BeEmpty("routing from the generic SEO entry point to the real workspace must not emit runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("module-seo-real-gateway");
        }
    }

    private async Task<Guid> SeedAdminSiteAsync()
    {
        await using var dbContext = OpenFixtureDb();
        var admin = await dbContext.AuthUsers.SingleAsync(x => x.NormalizedUserName == "ADMIN");
        var site = new Site(
            "SEO Gateway Real Site",
            new Uri("https://seo-gateway.example.test"),
            DateTime.UtcNow,
            admin.Id);
        dbContext.Sites.Add(site);
        await dbContext.SaveChangesAsync();
        return site.Id;
    }

    private AppDbContext OpenFixtureDb()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic);
        method.Should().NotBeNull("the shared UX fixture owns the isolated SQLite database used by the running browser host");
        return (AppDbContext)method!.Invoke(host, null)!;
    }
}
