using System.Reflection;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class SeoAuditUxTests(UxTestHost host)
{
    [Fact]
    public async Task Run_full_audit_from_UI_persists_history_and_surfaces_execution_center_job()
    {
        var siteId = await SeedAdminSeoSiteAsync();
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var response = await page.GotoAsync(
                host.BaseUrl + $"/sites/{siteId}/seo",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);
            await page.GetByTestId("seo-workspace").WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });

            (await page.GetByTestId("seo-results").InnerTextAsync()).Should().Contain("SEO Browser Article 01");
            var pagination = page.Locator(".seo-pagination");
            await Assertions.Expect(pagination).ToContainTextAsync("Page 1 of 2", new LocatorAssertionsToContainTextOptions { Timeout = 5000 });

            await ClickUntilTextChangesAsync(
                page,
                page.GetByRole(AriaRole.Button, new() { Name = "Next", Exact = true }),
                pagination,
                "Page 2 of 2");
            await page.GetByRole(AriaRole.Button, new() { Name = "Previous", Exact = true }).ClickAsync();
            await Assertions.Expect(pagination).ToContainTextAsync("Page 1 of 2", new LocatorAssertionsToContainTextOptions { Timeout = 5000 });

            var details = page.GetByRole(AriaRole.Button, new() { Name = "Details", Exact = true }).First;
            await details.ClickAsync();
            await page.Locator(".seo-detail-row").First.WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 5000
            });

            var firstExternalLink = page.Locator(".table-actions a[target='_blank']").First;
            (await firstExternalLink.GetAttributeAsync("href")).Should().StartWith("https://seo-browser.example.test/");

            var fixLink = page.Locator(".table-actions a.primary").First;
            var fixHref = await fixLink.GetAttributeAsync("href");
            fixHref.Should().NotBeNull();
            fixHref.Should().Contain($"/sites/{siteId}/content/").And.EndWith("/edit");
            await fixLink.ClickAsync();
            await page.WaitForURLAsync(url => url.Contains($"/sites/{siteId}/content/", StringComparison.OrdinalIgnoreCase) && url.EndsWith("/edit", StringComparison.OrdinalIgnoreCase), new PageWaitForURLOptions { Timeout = 10000 });

            await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}/seo", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 12000
            });
            await page.GetByTestId("seo-workspace").WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

            var search = page.Locator(".seo-search input");
            await search.FillAsync("SEO Browser Article 01");
            await page.GetByRole(AriaRole.Button, new() { Name = "Analyze", Exact = true }).ClickAsync();
            await page.GetByText("SEO Browser Article 01", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 5000 });
            (await page.GetByTestId("seo-results").InnerTextAsync()).Should().NotContain("SEO Browser Article 12");
            await page.GetByRole(AriaRole.Button, new() { Name = "Reset", Exact = true }).ClickAsync();
            await page.GetByText("SEO Browser Article 12", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 5000 });

            (await page.GetByTestId("seo-audit-history").InnerTextAsync()).Should().Contain("No saved audit history yet");
            var runAudit = page.GetByTestId("seo-run-full-audit");
            (await runAudit.IsEnabledAsync()).Should().BeTrue();
            await runAudit.ClickAsync();

            var feedback = page.GetByTestId("seo-audit-feedback");
            await feedback.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            await Assertions.Expect(feedback).ToContainTextAsync("SEO audit completed", new LocatorAssertionsToContainTextOptions { Timeout = 10000 });
            (await page.GetByTestId("seo-audit-history-point").CountAsync()).Should().BeGreaterThan(0);
            (await runAudit.IsEnabledAsync()).Should().BeTrue("the audit command must return to an actionable state after completion");

            await page.ReloadAsync(new PageReloadOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });
            await page.GetByTestId("seo-workspace").WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            (await page.GetByTestId("seo-audit-history-point").CountAsync()).Should().BeGreaterThan(0, "audit history must survive a browser refresh");

            await page.GetByTestId("seo-execution-center").ClickAsync();
            await page.WaitForURLAsync(url => url.Contains("/module/execution", StringComparison.OrdinalIgnoreCase), new PageWaitForURLOptions { Timeout = 10000 });
            await page.GetByText("Run SEO audit", new() { Exact = true }).First.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            (await page.Locator(".execution-jobs").InnerTextAsync()).Should().Contain("Completed");

            pageErrors.Should().BeEmpty("the SEO audit user journey must not emit browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("seo-audit-success");
        }
    }

    [Fact]
    public async Task Owned_site_without_Premium_SEO_shows_entitlement_error_instead_of_false_not_found()
    {
        var fixture = await host.CreateContentViewerContextAsync(UxRouteCatalog.Viewports[^1]);
        var context = fixture.Context;
        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var response = await page.GotoAsync(
                host.BaseUrl + $"/sites/{fixture.SiteId}/seo",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);
            var denied = page.GetByTestId("seo-load-error");
            await denied.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            (await denied.InnerTextAsync()).Should().Contain("Premium SEO subscription");
            (await page.GetByTestId("seo-site-unavailable").CountAsync()).Should().Be(0, "an entitlement denial must not be presented as a missing site");
            (await page.GetByTestId("seo-run-full-audit").CountAsync()).Should().Be(0, "a denied user must not receive the execution control");
            pageErrors.Should().BeEmpty("a denied SEO workspace must fail closed without browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("seo-audit-entitlement-denied");
        }
    }

    private async Task<Guid> SeedAdminSeoSiteAsync()
    {
        await using var dbContext = OpenFixtureDb();
        var admin = await dbContext.AuthUsers.SingleAsync(x => x.NormalizedUserName == "ADMIN");
        var now = DateTime.UtcNow;
        var site = new Site("SEO Browser Acceptance", new Uri("https://seo-browser.example.test"), now, admin.Id);
        dbContext.Sites.Add(site);

        for (var index = 1; index <= 12; index++)
        {
            var type = index % 3 == 0 ? "page" : "post";
            var content = new WordPressContentRecord(site.Id, 9100 + index, type, now.AddMinutes(-index));
            content.Update(
                $"SEO Browser Article {index:00}",
                $"seo-browser-article-{index:00}",
                "publish",
                $"https://seo-browser.example.test/seo-browser-article-{index:00}",
                $"<h2>Article {index:00}</h2><p>Short synchronized WordPress content for browser SEO acceptance.</p>",
                $"SEO browser acceptance excerpt {index:00}",
                now.AddDays(-index),
                now.AddMinutes(-index));
            dbContext.WordPressContentRecords.Add(content);
        }

        await dbContext.SaveChangesAsync();
        return site.Id;
    }

    private static async Task ClickUntilTextChangesAsync(IPage page, ILocator action, ILocator state, string expectedText)
    {
        var deadline = DateTime.UtcNow.AddSeconds(8);
        while (DateTime.UtcNow < deadline)
        {
            await action.ClickAsync(new LocatorClickOptions { Timeout = 2000 });
            try
            {
                await Assertions.Expect(state).ToContainTextAsync(expectedText, new LocatorAssertionsToContainTextOptions { Timeout = 750 });
                return;
            }
            catch (PlaywrightException)
            {
                // The first server-rendered frame can become visible before the
                // InteractiveServer circuit has attached. Repeat the same browser
                // action until its UI effect is observable, matching the repository's
                // established interaction-test pattern without calling handlers directly.
                await page.WaitForTimeoutAsync(150);
            }
        }

        throw new TimeoutException($"The browser action did not produce the expected UI state '{expectedText}' within 8 seconds.");
    }

    private AppDbContext OpenFixtureDb()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic);
        method.Should().NotBeNull("the shared UX fixture owns the isolated SQLite database used by the running browser host");
        return (AppDbContext)method!.Invoke(host, null)!;
    }
}
