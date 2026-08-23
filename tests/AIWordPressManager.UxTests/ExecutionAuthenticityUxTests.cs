using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class ExecutionAuthenticityUxTests(UxTestHost host)
{
    [Fact]
    public async Task Automation_and_execution_surfaces_do_not_offer_synthetic_runtime_capabilities()
    {
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var automationResponse = await page.GotoAsync(
                host.BaseUrl + "/automation-center",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });
            automationResponse.Should().NotBeNull();
            automationResponse!.Status.Should().BeLessThan(400);

            var unavailable = page.Locator("[data-runtime-unavailable='Content Operation']");
            await unavailable.WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });
            var unavailableText = await unavailable.InnerTextAsync();
            unavailableText.Should().Contain("Content Operation is unavailable as a generic automation");
            unavailableText.Should().Contain("real bulk worker requires explicit targets and a target action");

            var typeSelect = page.Locator(".automation-form label").Filter(new LocatorFilterOptions { HasText = "Job type" }).Locator("select");
            (await typeSelect.CountAsync()).Should().Be(1);
            var options = await typeSelect.Locator("option").AllTextContentsAsync();
            options.Should().Contain(["Synchronization", "SEO Audit"]);
            options.Should().NotContain("Content Operation");

            var executionResponse = await page.GotoAsync(
                host.BaseUrl + "/execution-center",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });
            executionResponse.Should().NotBeNull();
            executionResponse!.Status.Should().BeLessThan(400);

            var hero = page.Locator(".execution-hero");
            await hero.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var heroText = await hero.InnerTextAsync();
            heroText.Should().Contain("never manufactures progress or success");
            heroText.Should().Contain("actual runtime reports them");

            pageErrors.Should().BeEmpty("execution surfaces must render without browser runtime failures after synthetic behavior is removed");
        }
        finally
        {
            await context.CloseBoundedAsync("execution-runtime-authenticity");
        }
    }
}
