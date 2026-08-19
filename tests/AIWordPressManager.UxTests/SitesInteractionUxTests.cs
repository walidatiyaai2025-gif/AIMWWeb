using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class SitesInteractionUxTests(UxTestHost host)
{
    [Fact]
    public async Task Add_site_form_accepts_keyboard_input()
    {
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var response = await page.GotoAsync(
                host.BaseUrl + "/sites",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);
            await page.Locator("#main-content").WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Attached,
                Timeout = 5000
            });

            var addButton = page.GetByRole(AriaRole.Button, new() { Name = "Add Site", Exact = true }).First;
            var form = page.Locator(".site-create-grid");
            await OpenInteractiveFormAsync(page, addButton, form);

            var nameInput = form.Locator("input").Nth(0);
            var urlInput = form.Locator("input").Nth(1);
            await nameInput.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 5000 });

            (await nameInput.IsEditableAsync()).Should().BeTrue("the Add Site name field must not be disabled or readonly");
            (await urlInput.IsEditableAsync()).Should().BeTrue("the Add Site URL field must not be disabled or readonly");

            await nameInput.FocusAsync();
            var nameHasFocus = await nameInput.EvaluateAsync<bool>("element => document.activeElement === element");
            var focusDiagnostics = await page.EvaluateAsync<string>("""
                () => {
                    const active = document.activeElement;
                    const describe = element => element ? {
                        tag: element.tagName,
                        id: element.id || null,
                        className: typeof element.className === 'string' ? element.className : null,
                        role: element.getAttribute?.('role'),
                        ariaModal: element.getAttribute?.('aria-modal'),
                        ariaHidden: element.getAttribute?.('aria-hidden'),
                        text: (element.textContent || '').trim().slice(0, 120)
                    } : null;
                    const dialogs = Array.from(document.querySelectorAll('[role="dialog"][aria-modal="true"]')).map(dialog => {
                        const style = getComputedStyle(dialog);
                        const rect = dialog.getBoundingClientRect();
                        return {
                            ...describe(dialog),
                            display: style.display,
                            visibility: style.visibility,
                            opacity: style.opacity,
                            pointerEvents: style.pointerEvents,
                            rect: [rect.x, rect.y, rect.width, rect.height],
                            clientRects: dialog.getClientRects().length
                        };
                    });
                    return JSON.stringify({ active: describe(active), dialogs });
                }
                """);

            nameHasFocus.Should().BeTrue($"focus must remain on the Site Name input so physical keyboard typing works. DOM focus diagnostics: {focusDiagnostics}");

            await page.Keyboard.InsertTextAsync("Runtime Test Site");
            (await nameInput.InputValueAsync()).Should().Be("Runtime Test Site");

            await urlInput.FocusAsync();
            (await urlInput.EvaluateAsync<bool>("element => document.activeElement === element"))
                .Should().BeTrue("focus must remain on the Site URL input so physical keyboard typing works");

            await page.Keyboard.InsertTextAsync("https://example.com");
            (await urlInput.InputValueAsync()).Should().Be("https://example.com");

            pageErrors.Should().BeEmpty("opening and typing in the Add Site form must not cause browser errors");
        }
        finally
        {
            await context.CloseBoundedAsync("sites-input-interaction");
        }
    }

    private static async Task OpenInteractiveFormAsync(IPage page, ILocator addButton, ILocator form)
    {
        var deadline = DateTime.UtcNow.AddSeconds(8);
        while (DateTime.UtcNow < deadline)
        {
            await addButton.ClickAsync(new LocatorClickOptions { Timeout = 2000 });
            try
            {
                await form.WaitForAsync(new LocatorWaitForOptions
                {
                    State = WaitForSelectorState.Visible,
                    Timeout = 750
                });
                return;
            }
            catch (TimeoutException)
            {
                // Server-rendered markup can be visible just before the interactive
                // Blazor circuit finishes attaching. Retry the user action until the
                // circuit is ready, but still fail quickly if interactivity never arrives.
                await page.WaitForTimeoutAsync(150);
            }
        }

        throw new TimeoutException("The Add Site form did not become interactive within 8 seconds.");
    }
}