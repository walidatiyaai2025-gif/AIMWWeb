using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class SettingsDatabaseProviderUxTests(UxTestHost host)
{
    [Fact]
    public async Task Settings_reports_the_database_provider_from_the_actual_runtime_configuration()
    {
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            var errors = new List<string>();
            page.PageError += (_, message) => errors.Add(message);

            var response = await page.GotoAsync(host.BaseUrl + "/settings",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            var database = page.Locator("section#database");
            await database.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            await database.GetByText("SQLite", new LocatorGetByTextOptions { Exact = true }).First
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            await database.GetByText("Configured", new LocatorGetByTextOptions { Exact = true }).First
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });

            (await database.InnerTextAsync()).Should().Contain("Application data and synchronized WordPress snapshots");
            (await database.InnerTextAsync()).Should().NotContain("Local and portable");
            errors.Should().BeEmpty("Settings must render runtime database configuration without browser failures");
        }
        finally
        {
            await context.CloseBoundedAsync("settings-database-provider");
        }
    }
}
