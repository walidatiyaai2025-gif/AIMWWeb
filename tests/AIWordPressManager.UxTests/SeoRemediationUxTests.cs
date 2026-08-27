using System.Reflection;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

/// <summary>
/// Browser-level acceptance for the AI remediation surface. These tests deliberately
/// enter through the InteractiveServer page: they do not invoke component handlers or
/// the remediation service directly.
/// </summary>
[Collection(UxRegressionCollection.Name)]
public sealed class SeoRemediationUxTests(UxTestHost host)
{
    [Fact]
    public async Task Provider_unavailable_is_visible_and_never_fabricates_a_proposal()
    {
        var siteId = await SeedAdminSeoSiteAsync("Provider unavailable SEO article");
        await using var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        var page = await context.NewPageAsync();
        var pageErrors = new List<string>();
        page.PageError += (_, message) => pageErrors.Add(message);

        await OpenWorkspaceAsync(page, siteId);
        var generate = page.GetByTestId("seo-generate-all");
        await generate.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10_000 });
        await ClickUntilObservableAsync(page, generate, page.GetByTestId("seo-remediation-feedback"));

        await Assertions.Expect(page.GetByTestId("seo-remediation-feedback"))
            .ToContainTextAsync("provider", new() { IgnoreCase = true, Timeout = 10_000 });
        var rows = page.Locator("[data-testid^='proposal-row-']");
        (await rows.CountAsync()).Should().BeGreaterThan(0,
            "each attempted field must retain an explicit terminal failure instead of disappearing");
        foreach (var suggested in await page.Locator("[data-testid^='proposal-suggested-']").AllAsync())
            (await suggested.Locator("p").InnerTextAsync()).Should().BeNullOrWhiteSpace(
                "an unavailable provider must not be replaced with locally fabricated suggestions");
        foreach (var status in await page.Locator("[data-testid^='proposal-status-']").AllAsync())
            (await status.InnerTextAsync()).Should().ContainEquivalentOf("failed");
        (await page.GetByTestId("seo-apply-selected").IsEnabledAsync()).Should().BeFalse();
        (await page.GetByTestId("seo-apply-all-safe").IsEnabledAsync()).Should().BeFalse();
        pageErrors.Should().BeEmpty();
    }

    private async Task OpenWorkspaceAsync(IPage page, Guid siteId)
    {
        var response = await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}/seo", new PageGotoOptions
        {
            WaitUntil = WaitUntilState.DOMContentLoaded,
            Timeout = 12_000
        });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);
        await page.GetByTestId("seo-remediation-workspace").WaitForAsync(new()
        {
            State = WaitForSelectorState.Visible,
            Timeout = 10_000
        });
    }

    private async Task<Guid> SeedAdminSeoSiteAsync(string title)
    {
        await using var db = OpenFixtureDb();
        var admin = await db.AuthUsers.SingleAsync(x => x.NormalizedUserName == "ADMIN");
        var now = DateTime.UtcNow;
        var site = new Site("SEO remediation browser acceptance", new Uri("https://seo-remediation.example.test"), now, admin.Id);
        db.Sites.Add(site);
        var content = new WordPressContentRecord(site.Id, 9701, "post", now);
        content.Update(title, "provider-unavailable-seo-article", "publish",
            "https://seo-remediation.example.test/provider-unavailable-seo-article",
            "<p>Persisted WordPress source used by browser remediation acceptance.</p>",
            "Persisted source excerpt", now.AddDays(-1), now);
        db.WordPressContentRecords.Add(content);
        await db.SaveChangesAsync();
        return site.Id;
    }

    private AppDbContext OpenFixtureDb()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic);
        method.Should().NotBeNull();
        return (AppDbContext)method!.Invoke(host, null)!;
    }

    private static async Task ClickUntilObservableAsync(IPage page, ILocator action, ILocator result)
    {
        var deadline = DateTime.UtcNow.AddSeconds(8);
        while (DateTime.UtcNow < deadline)
        {
            await action.ClickAsync(new() { Timeout = 2_000 });
            try
            {
                await result.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 750 });
                return;
            }
            catch (TimeoutException)
            {
                await page.WaitForTimeoutAsync(150);
            }
        }
        throw new TimeoutException("The remediation action did not produce visible browser feedback.");
    }
}
