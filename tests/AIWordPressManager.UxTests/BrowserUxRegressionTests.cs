using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class BrowserUxRegressionTests(UxTestHost host)
{
    private const float DomProbeTimeoutMs = 5000;
    private static readonly TimeSpan DomHardTimeout = TimeSpan.FromMilliseconds(DomProbeTimeoutMs + 1000);
    private static readonly TimeSpan NavigationHardTimeout = TimeSpan.FromSeconds(12);
    private static readonly TimeSpan DiagnosticHardTimeout = TimeSpan.FromSeconds(2);

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

    [Fact]
    public async Task Blazor_bootstrap_asset_is_available_before_authentication()
    {
        Trace("bootstrap:context:start");
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1], authenticated: false);
        try
        {
            Trace("bootstrap:context:ok");
            var page = await context.NewPageAsync();
            Trace("bootstrap:page:ok");

            Trace("bootstrap:goto:start");
            var response = await page.GotoAsync(
                host.BaseUrl + "/_framework/blazor.web.js",
                new PageGotoOptions { WaitUntil = WaitUntilState.Commit, Timeout = 10000 })
                .WaitAsync(NavigationHardTimeout);
            Trace("bootstrap:goto:ok");

            response.Should().NotBeNull();
            response!.Status.Should().Be(200);
            page.Url.Should().NotContain("/login");
            response.Headers.TryGetValue("content-type", out var contentType).Should().BeTrue();
            contentType.Should().Contain("javascript");
            Trace("bootstrap:assertions:complete");
        }
        finally
        {
            await context.CloseBoundedAsync("bootstrap");
        }
    }

    [Theory]
    [MemberData(nameof(PublicRouteCases))]
    public async Task Public_routes_render_without_server_or_browser_failure(UxRouteCase route)
    {
        Trace($"public:{route.Key}:context:start");
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1], authenticated: false);
        try
        {
            Trace($"public:{route.Key}:context:ok");
            var page = await context.NewPageAsync();
            Trace($"public:{route.Key}:page:ok");
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            Trace($"public:{route.Key}:goto:start");
            var response = await NavigateHtmlAsync(page, host.BaseUrl + route.Path);
            Trace($"public:{route.Key}:goto:ok:{response?.Status}");
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);
            Trace($"public:{route.Key}:dom:start");
            await WaitForSelectorWithDiagnosticsAsync(page, "body");
            Trace($"public:{route.Key}:dom:ok");
            Trace($"public:{route.Key}:prepare:start");
            await UxAudit.PrepareAsync(page).WaitAsync(DomHardTimeout);
            Trace($"public:{route.Key}:prepare:ok");
            pageErrors.Should().BeEmpty($"public route {route.Path} must not throw browser page errors");

            Trace($"public:{route.Key}:accessibility:start");
            var issues = await UxAudit.AccessibilityIssuesAsync(page, requireApplicationShell: false).WaitAsync(DomHardTimeout);
            Trace($"public:{route.Key}:accessibility:ok:{issues.Count}");
            issues.Should().BeEmpty($"public route {route.Path} must pass the browser accessibility smoke audit");
            Trace($"public:{route.Key}:assertions:complete");
        }
        finally
        {
            await context.CloseBoundedAsync($"public:{route.Key}");
        }
    }

    [Theory]
    [MemberData(nameof(AuthenticatedRouteCases))]
    public async Task Authenticated_routes_render_and_pass_accessibility_smoke(UxRouteCase route)
    {
        Trace($"authenticated:{route.Key}:context:start");
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            Trace($"authenticated:{route.Key}:context:ok");
            var page = await context.NewPageAsync();
            Trace($"authenticated:{route.Key}:page:ok");
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            Trace($"authenticated:{route.Key}:goto:start");
            var response = await NavigateHtmlAsync(page, host.BaseUrl + route.Path);
            Trace($"authenticated:{route.Key}:goto:ok:{response?.Status}");
            AssertAuthenticatedRoute(page, response, route.Path);
            Trace($"authenticated:{route.Key}:shell:start");
            await WaitForApplicationShellAsync(page);
            Trace($"authenticated:{route.Key}:shell:ok");
            Trace($"authenticated:{route.Key}:prepare:start");
            await UxAudit.PrepareAsync(page).WaitAsync(DomHardTimeout);
            Trace($"authenticated:{route.Key}:prepare:ok");

            pageErrors.Should().BeEmpty($"authenticated route {route.Path} must not throw browser page errors");
            Trace($"authenticated:{route.Key}:accessibility:start");
            var issues = await UxAudit.AccessibilityIssuesAsync(page, requireApplicationShell: true).WaitAsync(DomHardTimeout);
            Trace($"authenticated:{route.Key}:accessibility:ok:{issues.Count}");
            issues.Should().BeEmpty($"authenticated route {route.Path} must pass the browser accessibility smoke audit");
            Trace($"authenticated:{route.Key}:assertions:complete");
        }
        finally
        {
            await context.CloseBoundedAsync($"authenticated:{route.Key}");
        }
    }

    [Theory]
    [MemberData(nameof(HighRiskViewportCases))]
    public async Task High_risk_pages_hold_visual_contract_at_key_breakpoints(UxRouteCase route, UxViewport viewport)
    {
        Trace($"visual:{route.Key}:{viewport.Key}:context:start");
        var context = await host.CreateContextAsync(viewport);
        try
        {
            Trace($"visual:{route.Key}:{viewport.Key}:context:ok");
            var page = await context.NewPageAsync();
            Trace($"visual:{route.Key}:{viewport.Key}:page:ok");
            var response = await NavigateHtmlAsync(page, host.BaseUrl + route.Path);
            Trace($"visual:{route.Key}:{viewport.Key}:goto:ok:{response?.Status}");
            AssertAuthenticatedRoute(page, response, route.Path);
            await WaitForApplicationShellAsync(page);
            Trace($"visual:{route.Key}:{viewport.Key}:shell:ok");
            await UxAudit.PrepareAsync(page).WaitAsync(DomHardTimeout);
            Trace($"visual:{route.Key}:{viewport.Key}:prepare:ok");

            var metrics = await UxAudit.CaptureVisualMetricsAsync(page).WaitAsync(DomHardTimeout);
            Trace($"visual:{route.Key}:{viewport.Key}:metrics:ok");
            UxAudit.AssertMaterialVisualContract(metrics, $"{route.Key}/{viewport.Key}");
            await UxAudit.SaveVisualEvidenceAsync(page, host, route, viewport, metrics).WaitAsync(TimeSpan.FromSeconds(15));
            Trace($"visual:{route.Key}:{viewport.Key}:assertions:complete");
        }
        finally
        {
            await context.CloseBoundedAsync($"visual:{route.Key}:{viewport.Key}");
        }
    }

    [Theory]
    [MemberData(nameof(DirectionCases))]
    public async Task Selected_high_risk_pages_preserve_material_contract_in_arabic_rtl(UxRouteCase route)
    {
        var viewport = UxRouteCatalog.Viewports[^1];
        Trace($"rtl:{route.Key}:context:start");
        var context = await host.CreateContextAsync(viewport);
        try
        {
            Trace($"rtl:{route.Key}:context:ok");
            var page = await context.NewPageAsync();
            Trace($"rtl:{route.Key}:page:ok");
            var response = await NavigateHtmlAsync(page, host.BaseUrl + route.Path);
            Trace($"rtl:{route.Key}:goto:ok:{response?.Status}");
            AssertAuthenticatedRoute(page, response, route.Path);
            await WaitForApplicationShellAsync(page);
            Trace($"rtl:{route.Key}:shell:ok");
            await page.EvaluateAsync("() => localStorage.setItem('aiwp-language', 'ar')").WaitAsync(DomHardTimeout);
            Trace($"rtl:{route.Key}:language-set");
            await ReloadHtmlAsync(page);
            Trace($"rtl:{route.Key}:reload:ok");
            AssertAuthenticatedLocation(page, route.Path);
            await WaitForApplicationShellAsync(page);
            Trace($"rtl:{route.Key}:rtl-shell:ok");
            await UxAudit.PrepareAsync(page).WaitAsync(DomHardTimeout);
            Trace($"rtl:{route.Key}:prepare:ok");

            var metrics = await UxAudit.CaptureVisualMetricsAsync(page).WaitAsync(DomHardTimeout);
            Trace($"rtl:{route.Key}:metrics:ok");
            metrics.Direction.Should().Be("rtl");
            UxAudit.AssertMaterialVisualContract(metrics, route.Key + "/rtl");
            var issues = await UxAudit.AccessibilityIssuesAsync(page, requireApplicationShell: true).WaitAsync(DomHardTimeout);
            Trace($"rtl:{route.Key}:accessibility:ok:{issues.Count}");
            issues.Should().BeEmpty($"{route.Path} must preserve accessibility semantics in RTL");
            Trace($"rtl:{route.Key}:assertions:complete");
        }
        finally
        {
            await context.CloseBoundedAsync($"rtl:{route.Key}");
        }
    }

    [Fact]
    public async Task Keyboard_focus_enters_the_authenticated_application()
    {
        Trace("keyboard:context:start");
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            Trace("keyboard:context:ok");
            var page = await context.NewPageAsync();
            Trace("keyboard:page:ok");
            var response = await NavigateHtmlAsync(page, host.BaseUrl + "/");
            Trace($"keyboard:goto:ok:{response?.Status}");
            AssertAuthenticatedRoute(page, response, "/");
            await WaitForApplicationShellAsync(page);
            Trace("keyboard:shell:ok");
            await page.Keyboard.PressAsync("Tab").WaitAsync(DomHardTimeout);
            Trace("keyboard:tab:ok");
            var active = await page.EvaluateAsync<string>("() => document.activeElement?.tagName?.toLowerCase() || ''").WaitAsync(DomHardTimeout);
            active.Should().NotBe("body");
            active.Should().NotBe("html");
            active.Should().NotBeNullOrWhiteSpace();
            Trace("keyboard:assertions:complete");
        }
        finally
        {
            await context.CloseBoundedAsync("keyboard");
        }
    }

    private static Task<IResponse?> NavigateHtmlAsync(IPage page, string url) =>
        page.GotoAsync(url, new PageGotoOptions
        {
            WaitUntil = WaitUntilState.DOMContentLoaded,
            Timeout = 10000
        }).WaitAsync(NavigationHardTimeout);

    private static Task<IResponse?> ReloadHtmlAsync(IPage page) =>
        page.ReloadAsync(new PageReloadOptions
        {
            WaitUntil = WaitUntilState.DOMContentLoaded,
            Timeout = 10000
        }).WaitAsync(NavigationHardTimeout);

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
        WaitForSelectorWithDiagnosticsAsync(page, "#main-content");

    private static async Task WaitForSelectorWithDiagnosticsAsync(IPage page, string selector)
    {
        try
        {
            await page.Locator(selector).WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Attached,
                Timeout = DomProbeTimeoutMs
            }).WaitAsync(DomHardTimeout);
        }
        catch (Exception ex) when (ex is TimeoutException or PlaywrightException)
        {
            var readyState = await TryEvaluateAsync(page, "() => document.readyState");
            var content = await TryContentAsync(page);
            content = content.Replace("\r", " ").Replace("\n", " ").Trim();
            if (content.Length > 2000) content = content[..2000] + "…";

            throw new TimeoutException(
                $"Browser DOM did not expose '{selector}' within {DomProbeTimeoutMs:0}ms. " +
                $"url={page.Url}; readyState={readyState}; html={content}",
                ex);
        }
    }

    private static async Task<string> TryEvaluateAsync(IPage page, string expression)
    {
        try { return await page.EvaluateAsync<string>(expression).WaitAsync(DiagnosticHardTimeout) ?? "<null>"; }
        catch (Exception ex) { return $"<{ex.GetType().Name}>"; }
    }

    private static async Task<string> TryContentAsync(IPage page)
    {
        try { return await page.ContentAsync().WaitAsync(DiagnosticHardTimeout); }
        catch (Exception ex) { return $"<{ex.GetType().Name}: {ex.Message}>"; }
    }

    private static void Trace(string message) =>
        Console.WriteLine($"[UX-TEST] {DateTime.UtcNow:O} {message}");
}
