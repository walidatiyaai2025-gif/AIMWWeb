using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class AIPromptTemplatesUxTests(UxTestHost host)
{
    [Fact]
    public async Task Prompt_templates_require_authentication_and_persist_create_update_and_restore_revisions()
    {
        var viewport = UxRouteCatalog.Viewports[^1];

        await using (var anonymous = await host.CreateContextAsync(viewport, authenticated: false))
        {
            var anonymousPage = await anonymous.NewPageAsync();
            await anonymousPage.GotoAsync(
                host.BaseUrl + "/settings/ai-prompts",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });

            anonymousPage.Url.Should().Contain("/login", "prompt administration is Administrator-only and anonymous deep links must not render the editor");
        }

        var key = $"ux.prompt.{Guid.NewGuid():N}"[..26];
        const string titleEn = "UX closure prompt";
        const string titleAr = "قالب اختبار الإغلاق";
        const string promptV1 = "Write a concise production-safe summary for {{title}}.";
        const string promptV2 = "Write a verified production-safe summary for {{title}} and cite the source state.";

        await using var context = await host.CreateContextAsync(viewport);
        var page = await context.NewPageAsync();
        var pageErrors = new List<string>();
        page.PageError += (_, message) => pageErrors.Add(message);

        var response = await page.GotoAsync(
            host.BaseUrl + "/settings/ai-prompts",
            new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });

        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);

        var newTemplate = page.GetByRole(AriaRole.Button, new() { Name = "New template" });
        await newTemplate.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

        var keyInput = page.Locator("input[placeholder='content.rewrite']");
        await keyInput.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
        await ClickUntilAsync(
            newTemplate,
            async () => await keyInput.IsEnabledAsync(),
            "New template never became interactive after InteractiveServer hydration.");
        await keyInput.FillAsync(key);

        var titleInputs = page.Locator(".settings-content input[maxlength='120']");
        (await titleInputs.CountAsync()).Should().Be(2);
        await titleInputs.Nth(0).FillAsync(titleEn);
        await titleInputs.Nth(1).FillAsync(titleAr);

        var prompts = page.Locator(".settings-content textarea");
        (await prompts.CountAsync()).Should().Be(2);
        await prompts.Nth(0).FillAsync(promptV1);
        await prompts.Nth(1).FillAsync("اكتب ملخصًا إنتاجيًا موجزًا وآمنًا لـ {{title}}.");

        await page.GetByRole(AriaRole.Button, new() { Name = "Save", Exact = true }).ClickAsync();
        await page.GetByText($"Saved {key} as revision r1.", new() { Exact = true }).WaitForAsync(
            new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

        await page.ReloadAsync(new PageReloadOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });
        var templateChoice = page.Locator(".settings-choice").Filter(new LocatorFilterOptions { HasTextString = key });
        await templateChoice.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
        await ClickUntilAsync(
            templateChoice,
            async () => string.Equals(await prompts.Nth(0).InputValueAsync(), promptV1, StringComparison.Ordinal),
            "Persisted template selection never became interactive after reload.");
        (await prompts.Nth(0).InputValueAsync()).Should().Be(promptV1, "the durable prompt store must survive a browser reload");

        await prompts.Nth(0).FillAsync(promptV2);
        await page.GetByRole(AriaRole.Button, new() { Name = "Save", Exact = true }).ClickAsync();
        await page.GetByText($"Saved {key} as revision r2.", new() { Exact = true }).WaitForAsync(
            new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

        var revisionOneMarker = page.Locator(".ai-history-list bdi[data-bidi-mode='numeric']")
            .GetByText("r1", new() { Exact = true });
        await revisionOneMarker.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
        var revisionOne = revisionOneMarker.Locator("xpath=ancestor::article[1]");

        page.Dialog += async (_, dialog) => await dialog.AcceptAsync();
        await revisionOne.GetByRole(AriaRole.Button, new() { Name = "Restore" }).ClickAsync();
        await page.GetByText("Restored as revision r3.", new() { Exact = true }).WaitForAsync(
            new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

        await page.ReloadAsync(new PageReloadOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 12000 });
        templateChoice = page.Locator(".settings-choice").Filter(new LocatorFilterOptions { HasTextString = key });
        await templateChoice.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
        await ClickUntilAsync(
            templateChoice,
            async () => string.Equals(await prompts.Nth(0).InputValueAsync(), promptV1, StringComparison.Ordinal),
            "Restored template selection never became interactive after reload.");

        (await prompts.Nth(0).InputValueAsync()).Should().Be(promptV1, "restore must create a new revision from the selected historical content");
        (await page.Locator(".ai-history-list article").CountAsync()).Should().BeGreaterThanOrEqualTo(3);
        pageErrors.Should().BeEmpty("prompt create/update/restore must complete through the real InteractiveServer store without browser runtime errors");
    }

    private static async Task ClickUntilAsync(ILocator control, Func<Task<bool>> condition, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(12);
        Exception? last = null;
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await control.ClickAsync(new LocatorClickOptions { Timeout = 1500 });
                if (await condition())
                    return;
            }
            catch (Exception ex) when (ex is PlaywrightException or InvalidOperationException)
            {
                last = ex;
            }

            await Task.Delay(100);
        }

        throw new TimeoutException(failure, last);
    }
}