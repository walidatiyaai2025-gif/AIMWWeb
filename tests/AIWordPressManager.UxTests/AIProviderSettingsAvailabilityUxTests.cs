using System.Reflection;
using AIWordPressManager.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
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

            // Razor is prerendered before the InteractiveServer circuit attaches. Probe the real
            // production save handler until it produces its persisted success state, matching the
            // bounded interaction pattern used by the other mutation acceptance journeys.
            await EnsureInteractiveAsync(page);

            openAiCard = page.Locator("article[data-provider='OpenAI']");
            var openAiToggle = openAiCard.Locator("#ai-provider-openai-enabled");
            var originalEnabled = await openAiToggle.IsCheckedAsync();
            var expectedEnabled = !originalEnabled;
            await openAiToggle.SetCheckedAsync(expectedEnabled);

            var save = page.Locator("button:has-text('Save AI settings')");
            await save.First.ClickAsync(new LocatorClickOptions { Timeout = 5000 });
            await WaitForPersistedOpenAiStateAsync(expectedEnabled);

            await page.ReloadAsync(new PageReloadOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });
            openAiCard = page.Locator("article[data-provider='OpenAI']");
            await openAiCard.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            openAiToggle = openAiCard.Locator("#ai-provider-openai-enabled");
            (await openAiToggle.IsCheckedAsync()).Should().Be(expectedEnabled, "the UI save must reach persisted application settings");

            // Restore the shared browser fixture through the same production path after the new
            // circuit is ready, then prove the database was reconciled back to its original state.
            await EnsureInteractiveAsync(page);
            openAiCard = page.Locator("article[data-provider='OpenAI']");
            openAiToggle = openAiCard.Locator("#ai-provider-openai-enabled");
            await openAiToggle.SetCheckedAsync(originalEnabled);
            save = page.Locator("button:has-text('Save AI settings')");
            await save.First.ClickAsync(new LocatorClickOptions { Timeout = 5000 });
            await WaitForPersistedOpenAiStateAsync(originalEnabled);

            pageErrors.Should().BeEmpty("AI provider settings must remain a real InteractiveServer persistence flow");
        }
        finally
        {
            await context.CloseBoundedAsync("ai-provider-settings-runtime-availability");
        }
    }

    private static async Task EnsureInteractiveAsync(IPage page)
    {
        var save = page.Locator("button:has-text('Save AI settings')");
        var success = page.GetByText("AI provider settings were saved securely.", new PageGetByTextOptions { Exact = true });
        var deadline = DateTime.UtcNow.AddSeconds(8);

        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await save.First.ClickAsync(new LocatorClickOptions { Timeout = 2000 });
                await success.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 750 });
                return;
            }
            catch (TimeoutException)
            {
                await page.WaitForTimeoutAsync(150);
            }
        }

        throw new TimeoutException("AI provider settings did not become InteractiveServer-ready within 8 seconds.");
    }

    private async Task WaitForPersistedOpenAiStateAsync(bool expected)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        while (DateTime.UtcNow < deadline)
        {
            await using var db = CreateDbContext();
            var value = await db.ApplicationSettings.AsNoTracking()
                .Where(x => x.Key == "AI.OpenAI.Enabled")
                .Select(x => x.Value)
                .SingleOrDefaultAsync();
            if (bool.TryParse(value, out var enabled) && enabled == expected) return;
            await Task.Delay(100);
        }

        throw new TimeoutException($"AI.OpenAI.Enabled did not persist as {expected} through the production settings service.");
    }

    private AppDbContext CreateDbContext()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX fixture database context factory.");
        return (AppDbContext)(method.Invoke(host, null)
            ?? throw new InvalidOperationException("UX fixture database context factory returned null."));
    }
}
