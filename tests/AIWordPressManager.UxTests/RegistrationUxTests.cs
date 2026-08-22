using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class RegistrationUxTests(UxTestHost host)
{
    private const string SyntheticPassword = "ValidPass123!";

    [Fact]
    public async Task Registration_accepts_valid_username_and_creates_free_trial()
    {
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1280, 900), authenticated: false);
        try
        {
            var page = await context.NewPageAsync();
            var response = await page.GotoAsync(host.BaseUrl + "/register", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            (await page.Locator(".sidebar").CountAsync()).Should().Be(0, "anonymous registration must not render authenticated navigation");
            (await page.Locator(".topbar").CountAsync()).Should().Be(0, "anonymous registration must not render the authenticated header");
            (await page.Locator(".landing-layout").CountAsync()).Should().Be(1);

            await page.Locator("#register-user").FillAsync("lido825");
            await page.Locator("#register-password").FillAsync(SyntheticPassword);
            await page.Locator("#register-confirm").FillAsync(SyntheticPassword);

            (await page.Locator("#register-user").InputValueAsync()).Should().Be("lido825");

            await SubmitUntilRedirectedAsync(page, page.Locator("button.auth-submit"), "/login?registered=true");
            page.Url.Should().Contain("/login?registered=true");
        }
        finally
        {
            await context.CloseAsync();
        }
    }

    [Fact]
    public async Task Registration_maps_form_values_when_javascript_is_not_available_yet()
    {
        await using var context = await host.Browser.NewContextAsync(new BrowserNewContextOptions
        {
            ViewportSize = new ViewportSize { Width = 1280, Height = 900 },
            JavaScriptEnabled = false
        });
        context.SetDefaultTimeout(10000);
        context.SetDefaultNavigationTimeout(10000);

        var page = await context.NewPageAsync();
        var response = await page.GotoAsync(host.BaseUrl + "/register", new PageGotoOptions
        {
            WaitUntil = WaitUntilState.DOMContentLoaded,
            Timeout = 10000
        });

        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);
        (await page.Locator(".sidebar").CountAsync()).Should().Be(0);
        (await page.Locator(".topbar").CountAsync()).Should().Be(0);

        await page.Locator("#register-user").FillAsync("lido825-static");
        await page.Locator("#register-password").FillAsync(SyntheticPassword);
        await page.Locator("#register-confirm").FillAsync(SyntheticPassword);
        await page.Locator("button.auth-submit").ClickAsync();

        await page.WaitForURLAsync("**/login?registered=true", new PageWaitForURLOptions
        {
            Timeout = 15000,
            WaitUntil = WaitUntilState.DOMContentLoaded
        });

        page.Url.Should().Contain("/login?registered=true");
    }

    private static async Task SubmitUntilRedirectedAsync(IPage page, ILocator submit, string expectedPath)
    {
        var deadline = DateTime.UtcNow.AddSeconds(8);
        while (DateTime.UtcNow < deadline)
        {
            await submit.ClickAsync(new LocatorClickOptions { Timeout = 2000 });
            try
            {
                await page.WaitForURLAsync(
                    url => url.Contains(expectedPath, StringComparison.OrdinalIgnoreCase),
                    new PageWaitForURLOptions
                    {
                        Timeout = 750,
                        WaitUntil = WaitUntilState.Commit
                    });
                return;
            }
            catch (TimeoutException)
            {
                // InteractiveServer can expose the prerendered form before the circuit
                // has attached. Repeat the same browser submit until the observable
                // redirect occurs; do not call the component handler directly.
                await page.WaitForTimeoutAsync(150);
            }
        }

        throw new TimeoutException($"Registration did not redirect to '{expectedPath}' within 8 seconds.");
    }
}
