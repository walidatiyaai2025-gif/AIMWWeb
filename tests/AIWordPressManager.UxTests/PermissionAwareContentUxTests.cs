using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class PermissionAwareContentUxTests(UxTestHost host)
{
    [Fact]
    public async Task Content_view_only_role_can_read_owned_content_without_mutation_affordances()
    {
        var viewport = UxRouteCatalog.Viewports[^1];
        var (context, siteId) = await host.CreateContentViewerContextAsync(viewport);

        try
        {
            var page = context.Pages.Single();
            await page.Locator("#main-content").WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Attached,
                Timeout = 5000
            });

            page.Url.Should().Contain($"/sites/{siteId}/explorer");
            page.Url.Should().NotContain("/login");

            var alerts = await page.Locator(".alert").AllInnerTextsAsync();
            alerts.Should().Contain(text => text.Contains("Read-only mode", StringComparison.OrdinalIgnoreCase));

            var rows = page.Locator(".explorer-table-row");
            (await rows.CountAsync()).Should().Be(1);
            (await rows.First.InnerTextAsync()).Should().Contain("View-only test post");

            var selection = page.Locator(".explorer-table-panel input[type='checkbox']");
            (await selection.CountAsync()).Should().BeGreaterThanOrEqualTo(2);
            for (var index = 0; index < await selection.CountAsync(); index++)
                (await selection.Nth(index).IsDisabledAsync()).Should().BeTrue();

            (await page.Locator(".bulk-action-bar").CountAsync()).Should().Be(0);
            (await page.GetByRole(AriaRole.Link, new PageGetByRoleOptions { Name = "Edit", Exact = true }).CountAsync()).Should().Be(0);

            var viewLink = page.GetByRole(AriaRole.Link, new PageGetByRoleOptions { Name = "View", Exact = true });
            (await viewLink.CountAsync()).Should().Be(1);
            (await viewLink.GetAttributeAsync("href")).Should().Be($"/sites/{siteId}/content/post/501/edit");

            var mediaResponse = await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}/media", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            mediaResponse.Should().NotBeNull();
            mediaResponse!.Status.Should().BeLessThan(400);
            page.Url.Should().NotContain("/login");
            await page.Locator("#main-content").WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Attached,
                Timeout = 5000
            });

            var mediaAlerts = await page.Locator(".alert").AllInnerTextsAsync();
            mediaAlerts.Should().Contain(text => text.Contains("Read-only mode", StringComparison.OrdinalIgnoreCase));
            (await page.Locator("input[type='file']").CountAsync()).Should().Be(0);
            (await page.GetByRole(AriaRole.Button, new PageGetByRoleOptions { Name = "Optimize", Exact = true }).CountAsync()).Should().Be(0);
            (await page.GetByRole(AriaRole.Button, new PageGetByRoleOptions { Name = "Delete", Exact = true }).CountAsync()).Should().Be(0);
        }
        finally
        {
            await context.CloseAsync();
        }
    }
}