using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class BrowserUxRegressionTests(UxTestHost host)
{
    public static IEnumerable<object[]> PublicRouteCases =>
        UxRouteCatalog.PublicRoutes.Select(route => new object[] { route });

    public static IEnumerable<object[]> AuthenticatedRouteCases =>
        UxRouteCatalog.AuthenticatedRoutes.Select(route => new object[] { route });

    public static IEnumerable<object[]> HighRiskViewportCases =>
        from route in UxRouteCatalog.ScreenshotRoutes
        from viewport in UxRouteCatalog.Viewports
        select new object[] { route, viewport };

    public static IEnumerable<object[]> DirectionCases => new[]
    {
        new object[] { UxRouteCatalog.AuthenticatedRoutes.Single(x => x.Key == "dashboard") },
        new object[] { UxRouteCatalog.AuthenticatedRoutes.Single(x => x.Key == "ai-center") },
        new object[] { UxRouteCatalog.AuthenticatedRoutes.Single(x => x.Key == "account-profile") }
    };

    [Theory]
    [MemberData(nameof(PublicRouteCases))]
    public async Task Public_routes_render_without_server_or_browser_failure(UxRouteCase route)
    {
        await using var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1], authenticated: false);
        var page = await context.NewPageAsync();
        var pageErrors = new List<string>();
        page.PageError += (_, message) => pageErrors.Add(message);

        var response = await page.GotoAsync(host.BaseUrl + route.Path, new PageGotoOptions { WaitUntil = WaitUntilState.Commit });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);
        await page.Locator("body").WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Attached, Timeout = 10000 });
        await UxAudit.PrepareAsync(page);
        pageErrors.Should().BeEmpty($"public route {route.Path} must not throw browser page errors");

        var issues = await UxAudit.AccessibilityIssuesAsync(page, requireApplicationShell: false);
        issues.Should().BeEmpty($"public route {route.Path} must pass the browser accessibility smoke audit");
    }

    [Theory]
    [MemberData(nameof(AuthenticatedRouteCases))]
    public async Task Authenticated_routes_render_and_pass_accessibility_smoke(UxRouteCase route)
    {
        await using var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        var page = await context.NewPageAsync();
        var pageErrors = new List<string>();
        page.PageError += (_, message) => pageErrors.Add(message);

        var response = await page.GotoAsync(host.BaseUrl + route.Path, new PageGotoOptions { WaitUntil = WaitUntilState.Commit });
        AssertAuthenticatedRoute(page, response, route.Path);
        await WaitForApplicationShellAsync(page);
        await UxAudit.PrepareAsync(page);

        pageErrors.Should().BeEmpty($"authenticated route {route.Path} must not throw browser page errors");
        var issues = await UxAudit.AccessibilityIssuesAsync(page, requireApplicationShell: true);
        issues.Should().BeEmpty($"authenticated route {route.Path} must pass the browser accessibility smoke audit");
    }

    [Theory]
    [MemberData(nameof(HighRiskViewportCases))]
    public async Task High_risk_pages_hold_visual_contract_at_key_breakpoints(UxRouteCase route, UxViewport viewport)
    {
        await using var context = await host.CreateContextAsync(viewport);
        var page = await context.NewPageAsync();
        var response = await page.GotoAsync(host.BaseUrl + route.Path, new PageGotoOptions { WaitUntil = WaitUntilState.Commit });
        AssertAuthenticatedRoute(page, response, route.Path);
        await WaitForApplicationShellAsync(page);
        await UxAudit.PrepareAsync(page);

        var metrics = await UxAudit.CaptureVisualMetricsAsync(page);
        UxAudit.AssertMaterialVisualContract(metrics, $"{route.Key}/{viewport.Key}");
        await UxAudit.SaveVisualEvidenceAsync(page, host, route, viewport, metrics);
    }

    [Theory]
    [MemberData(nameof(DirectionCases))]
    public async Task Selected_high_risk_pages_preserve_material_contract_in_arabic_rtl(UxRouteCase route)
    {
        var viewport = UxRouteCatalog.Viewports[^1];
        await using var context = await host.CreateContextAsync(viewport);
        var page = await context.NewPageAsync();
        var response = await page.GotoAsync(host.BaseUrl + route.Path, new PageGotoOptions { WaitUntil = WaitUntilState.Commit });
        AssertAuthenticatedRoute(page, response, route.Path);
        await WaitForApplicationShellAsync(page);
        await page.EvaluateAsync("() => localStorage.setItem('aiwp-language', 'ar')");
        await page.ReloadAsync(new PageReloadOptions { WaitUntil = WaitUntilState.Commit });
        AssertAuthenticatedLocation(page, route.Path);
        await WaitForApplicationShellAsync(page);
        await UxAudit.PrepareAsync(page);

        var metrics = await UxAudit.CaptureVisualMetricsAsync(page);
        metrics.Direction.Should().Be("rtl");
        UxAudit.AssertMaterialVisualContract(metrics, route.Key + "/rtl");
        var issues = await UxAudit.AccessibilityIssuesAsync(page, requireApplicationShell: true);
        issues.Should().BeEmpty($"{route.Path} must preserve accessibility semantics in RTL");
    }

    [Fact]
    public async Task Keyboard_focus_enters_the_authenticated_application()
    {
        await using var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        var page = await context.NewPageAsync();
        var response = await page.GotoAsync(host.BaseUrl + "/", new PageGotoOptions { WaitUntil = WaitUntilState.Commit });
        AssertAuthenticatedRoute(page, response, "/");
        await WaitForApplicationShellAsync(page);
        await page.Keyboard.PressAsync("Tab");
        var active = await page.EvaluateAsync<string>("() => document.activeElement?.tagName?.toLowerCase() || ''");
        active.Should().NotBe("body");
        active.Should().NotBe("html");
        active.Should().NotBeNullOrWhiteSpace();
    }

    private static void AssertAuthenticatedRoute(IPage page, IResponse? response, string routePath)
    {
        response.Should().NotBeNull($"authenticated route {routePath} must produce an HTTP response");
        response!.Status.Should().BeLessThan(400, $"authenticated route {routePath} must not return an HTTP error");
        AssertAuthenticatedLocation(page, routePath);
    }

    private static void AssertAuthenticatedLocation(IPage page, string routePath)
    {
        page.Url.Should().NotContain("/login", $"authenticated route {routePath} must retain the seeded administrator session");
        page.Url.Should().NotContain("/setup", $"authenticated route {routePath} must use the isolated completed database setup");
    }

    private static Task WaitForApplicationShellAsync(IPage page) =>
        page.Locator("#main-content").WaitForAsync(new LocatorWaitForOptions
        {
            State = WaitForSelectorState.Visible,
            Timeout = 10000
        });
}
