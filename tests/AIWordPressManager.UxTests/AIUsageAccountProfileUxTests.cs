using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class AIUsageAccountProfileUxTests(UxTestHost host)
{
    [Fact]
    public async Task AI_usage_renders_real_account_scoped_snapshot_and_refreshes_without_fabricated_activity()
    {
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var response = await page.GotoAsync(
                host.BaseUrl + "/module/ai-usage",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);
            var pageTitle = page.Locator("#page-title");
            await pageTitle.WaitForAsync();
            (await pageTitle.InnerTextAsync()).Should().Be("AI Usage & Cost");

            var body = await page.Locator("body").InnerTextAsync();
            body.Should().Contain("Account-scoped observability");
            body.Should().Contain("within the latest 10,000 retained records");
            body.Should().NotContain("Sample provider");
            body.Should().NotContain("Demo provider");
            body.Should().NotContain("Ready to spend");

            var refresh = page.Locator(".usage-actions button");
            await refresh.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            await refresh.ClickAsync();
            await page.WaitForFunctionAsync("() => !document.body.innerText.includes('Refreshing usage data')");

            pageErrors.Should().BeEmpty("AI Usage refresh must execute through the real InteractiveServer service path without browser runtime errors");
            (await page.Locator("body").InnerTextAsync()).Should().Contain("All my sites");
        }
        finally
        {
            await context.CloseBoundedAsync("ai-usage-account-snapshot");
        }
    }

    [Fact]
    public async Task Account_profile_loads_persisted_identity_and_wrong_current_password_never_reports_success()
    {
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var response = await page.GotoAsync(
                host.BaseUrl + "/account/profile",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);
            var pageTitle = page.Locator("#page-title");
            await pageTitle.WaitForAsync();
            (await pageTitle.InnerTextAsync()).Should().Be("My Account");

            var summary = page.GetByLabel("Account summary");
            await summary.WaitForAsync();
            (await summary.InnerTextAsync()).Should().Contain("Admin");
            (await summary.InnerTextAsync()).Should().Contain("Administrator");

            var currentPassword = page.Locator("#current-password");
            var newPassword = page.Locator("#new-password");
            var confirmation = page.Locator("#confirm-password");
            await currentPassword.FillAsync("DefinitelyWrong@123");
            await currentPassword.DispatchEventAsync("change");
            await newPassword.FillAsync("Temporary9Password");
            await newPassword.DispatchEventAsync("change");
            await confirmation.FillAsync("Temporary9Password");
            await confirmation.DispatchEventAsync("change");

            var save = page.GetByRole(AriaRole.Button, new() { Name = "Save new password", Exact = true });
            await page.WaitForFunctionAsync("() => { const button = document.querySelector('button[aria-label=\"Save new password\"]'); return !!button && !button.disabled; }");
            await save.ClickAsync();

            var failure = page.GetByText("The current password is incorrect.", new() { Exact = true });
            await failure.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            (await page.Locator("body").InnerTextAsync()).Should().NotContain("Password changed successfully.");
            pageErrors.Should().BeEmpty("Account password validation must reach the real account service and surface failure without client-only success");
        }
        finally
        {
            await context.CloseBoundedAsync("account-profile-password-failure");
        }
    }

    [Fact]
    public async Task Account_surfaces_do_not_render_to_an_anonymous_browser()
    {
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1], authenticated: false);
        try
        {
            var page = await context.NewPageAsync();
            await page.GotoAsync(host.BaseUrl + "/account/profile", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 12000
            });

            page.Url.Should().Contain("/login");
            (await page.Locator("body").InnerTextAsync()).Should().NotContain("Change password");
        }
        finally
        {
            await context.CloseBoundedAsync("account-profile-anonymous-denial");
        }
    }
}