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
            await addButton.ClickAsync();

            var nameInput = page.Locator(".site-create-grid input").Nth(0);
            var urlInput = page.Locator(".site-create-grid input").Nth(1);
            await nameInput.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 5000 });

            (await nameInput.IsEditableAsync()).Should().BeTrue("the Add Site name field must not be disabled or readonly");
            (await urlInput.IsEditableAsync()).Should().BeTrue("the Add Site URL field must not be disabled or readonly");

            await nameInput.FocusAsync();
            (await nameInput.EvaluateAsync<bool>("element => document.activeElement === element"))
                .Should().BeTrue("focus must remain on the Site Name input so physical keyboard typing works");

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
}
