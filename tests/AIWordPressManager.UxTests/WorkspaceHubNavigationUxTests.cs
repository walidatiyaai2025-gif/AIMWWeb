using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class WorkspaceHubNavigationUxTests(UxTestHost host)
{
    [Fact]
    public async Task Content_workspace_exposes_actionable_real_routes_instead_of_readiness_mock_cards()
    {
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var response = await page.GotoAsync(
                host.BaseUrl + "/content-workspace",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            await page.GetByTestId("workspace-hub").WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });

            var links = page.GetByTestId("workspace-link");
            (await links.CountAsync()).Should().Be(6);

            var hrefs = await links.EvaluateAllAsync<string[]>("els => els.map(e => e.getAttribute('href'))");
            hrefs.Should().BeEquivalentTo(new[]
            {
                "/module/posts",
                "/module/pages",
                "/module/media",
                "/module/taxonomy",
                "/module/comments",
                "/module/users"
            });

            var body = await page.Locator("body").InnerTextAsync();
            body.Should().NotContain("In Progress");
            body.Should().NotContain("قيد التطوير");
            body.Should().NotContain("Bulk Operations");

            var posts = page.Locator("[data-workspace-key='posts']");
            await posts.ClickAsync();
            await page.WaitForURLAsync(
                url => url.EndsWith("/module/posts", StringComparison.OrdinalIgnoreCase),
                new PageWaitForURLOptions { Timeout = 10000 });

            pageErrors.Should().BeEmpty("WorkspaceHub navigation must reach the real destination without browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("workspace-hub-real-routes");
        }
    }
}
