using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class AICenterReadinessUxTests(UxTestHost host)
{
    [Fact]
    public async Task AI_center_uses_neutral_idle_state_and_refreshes_real_metadata_without_false_readiness()
    {
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var response = await page.GotoAsync(
                host.BaseUrl + "/ai-center",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            var composer = page.Locator(".ai-composer");
            await composer.WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });

            var composerText = await composer.InnerTextAsync();
            composerText.Should().NotContain("Ready");
            composerText.Should().NotContain("جاهز");
            (composerText.Contains("Idle", StringComparison.Ordinal) || composerText.Contains("خامل", StringComparison.Ordinal))
                .Should().BeTrue("an idle composer is not proof that the configured AI provider is ready");

            var refresh = page.Locator("button:has-text('Refresh data'), button:has-text('تحديث البيانات')");
            (await refresh.CountAsync()).Should().BeGreaterThan(0);
            await refresh.First.ClickAsync();

            composerText = await composer.InnerTextAsync();
            composerText.Should().NotContain("Ready");
            composerText.Should().NotContain("جاهز");
            pageErrors.Should().BeEmpty("AI Center metadata refresh must use the real InteractiveServer handler without browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("ai-center-neutral-readiness");
        }
    }
}
