using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class AccessibilityHardeningBatch2RegressionTests(UxTestHost host)
{
    public static IEnumerable<object[]> PublicRouteCases =>
        UxRouteCatalog.PublicRoutes.Select(route => new object[] { route });

    public static IEnumerable<object[]> AuthenticatedRouteCases =>
        UxRouteCatalog.AuthenticatedRoutes.Select(route => new object[] { route });

    [Theory]
    [MemberData(nameof(PublicRouteCases))]
    public async Task Public_routes_pass_accessibility_hardening_batch_2(UxRouteCase route)
    {
        await using var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1], authenticated: false);
        var page = await context.NewPageAsync();
        var response = await page.GotoAsync(host.BaseUrl + route.Path, new PageGotoOptions { WaitUntil = WaitUntilState.Commit });

        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400, $"public route {route.Path} must render successfully");
        await page.Locator("body").WaitForAsync(new LocatorWaitForOptions
        {
            State = WaitForSelectorState.Attached,
            Timeout = 15000
        });
        await UxAudit.PrepareAsync(page);

        var issues = await UxAccessibilityHardeningBatch2.IssuesAsync(page);
        issues.Should().BeEmpty($"public route {route.Path} must pass UX-010 accessibility hardening batch 2");
    }

    [Theory]
    [MemberData(nameof(AuthenticatedRouteCases))]
    public async Task Authenticated_routes_pass_accessibility_hardening_batch_2(UxRouteCase route)
    {
        await using var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        var page = await context.NewPageAsync();
        var response = await page.GotoAsync(host.BaseUrl + route.Path, new PageGotoOptions { WaitUntil = WaitUntilState.Commit });

        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400, $"authenticated route {route.Path} must render successfully");
        page.Url.Should().NotContain("/login", $"authenticated route {route.Path} must retain the seeded administrator session");
        page.Url.Should().NotContain("/setup", $"authenticated route {route.Path} must use the isolated completed setup");
        await page.Locator("#main-content").WaitForAsync(new LocatorWaitForOptions
        {
            State = WaitForSelectorState.Attached,
            Timeout = 15000
        });
        await UxAudit.PrepareAsync(page);

        var issues = await UxAccessibilityHardeningBatch2.IssuesAsync(page);
        issues.Should().BeEmpty($"authenticated route {route.Path} must pass UX-010 accessibility hardening batch 2");
    }
}
