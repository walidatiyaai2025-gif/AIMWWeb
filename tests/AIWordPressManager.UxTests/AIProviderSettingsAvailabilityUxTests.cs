using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class AIProviderSettingsAvailabilityUxTests(UxTestHost host)
{
    [Fact]
    public async Task Unsupported_providers_are_noninteractive_while_supported_enablement_persists_through_real_settings_service()
    {
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var response = await page.GotoAsync(
                host.BaseUrl + "/settings/ai-providers",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            var openAiCard = page.Locator("article[data-provider='OpenAI']");
            await openAiCard.WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });

            foreach (var provider in new[] { "OpenAI", "Gemini", "Puter" })
            {
                var card = page.Locator($"article[data-provider='{provider}']");
                (await card.Locator("input[id$='-enabled']").CountAsync()).Should().Be(1, $"{provider} has a concrete runtime adapter");
                (await card.Locator("[data-runtime-unavailable]").CountAsync()).Should().Be(0);
            }

            foreach (var provider in new[] { "Groq", "OpenRouter", "Ollama" })
            {
                var card = page.Locator($"article[data-provider='{provider}']");
                (await card.CountAsync()).Should().Be(1);
                (await card.Locator("input[id$='-enabled']").CountAsync()).Should().Be(0, $"{provider} cannot execute in this server build");
                (await card.Locator($"[data-runtime-unavailable='{provider}']").CountAsync()).Should().Be(1);
                var text = await card.InnerTextAsync();
                text.Should().Contain("Runtime unavailable");
                text.Should().Contain("cannot be enabled until a real runtime adapter is installed");
            }

            // The page is prerendered before its InteractiveServer circuit is attached. Prove the
            // production save handler is interactive first, then mutate provider state. This avoids
            // accepting a DOM-only checkbox change that Blazor never observed.
            var save = page.Locator("button:has-text('Save AI settings')");
            (await save.CountAsync()).Should().BeGreaterThan(0);
            await save.First.ClickAsync();
            await page.GetByText("AI provider settings were saved securely.", new PageGetByTextOptions { Exact = true })
                .WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

            openAiCard = page.Locator("article[data-provider='OpenAI']");
            var openAiToggle = openAiCard.Locator("#ai-provider-openai-enabled");
            var originalEnabled = await openAiToggle.IsCheckedAsync();
            await openAiToggle.SetCheckedAsync(!originalEnabled);

            await save.First.ClickAsync();
            await page.GetByText("AI provider settings were saved securely.", new PageGetByTextOptions { Exact = true })
                .WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

            await page.ReloadAsync(new PageReloadOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });
            openAiCard = page.Locator("article[data-provider='OpenAI']");
            await openAiCard.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            openAiToggle = openAiCard.Locator("#ai-provider-openai-enabled");
            (await openAiToggle.IsCheckedAsync()).Should().Be(!originalEnabled, "the UI save must reach persisted application settings");

            // Establish the new circuit after reload before restoring the fixture state.
            save = page.Locator("button:has-text('Save AI settings')");
            await save.First.ClickAsync();
            await page.GetByText("AI provider settings were saved securely.", new PageGetByTextOptions { Exact = true })
                .WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

            openAiCard = page.Locator("article[data-provider='OpenAI']");
            openAiToggle = openAiCard.Locator("#ai-provider-openai-enabled");
            await openAiToggle.SetCheckedAsync(originalEnabled);
            await save.First.ClickAsync();
            await page.GetByText("AI provider settings were saved securely.", new PageGetByTextOptions { Exact = true })
                .WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

            pageErrors.Should().BeEmpty("AI provider settings must remain a real InteractiveServer persistence flow");
        }
        finally
        {
            await context.CloseBoundedAsync("ai-provider-settings-runtime-availability");
        }
    }
}
