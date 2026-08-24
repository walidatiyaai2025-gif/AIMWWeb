using System.Reflection;
using System.Text;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class ReportsExportsUxTests(UxTestHost host)
{
    private const string SiteName = "UX Reports Export Site";
    private static readonly Uri SiteUri = new("https://reports-export.example.test");

    [Fact]
    public async Task Sites_report_renders_owned_application_data_and_downloads_real_CSV()
    {
        await SeedOwnedSiteAsync();
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            var errors = new List<string>();
            page.PageError += (_, message) => errors.Add(message);

            var response = await page.GotoAsync(host.BaseUrl + "/reports",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            var siteCell = page.GetByRole(AriaRole.Cell, new() { Name = SiteName, Exact = true });
            await siteCell.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var siteRow = siteCell.Locator("xpath=ancestor::tr[1]");
            await siteRow.GetByText(SiteUri.Host, new LocatorGetByTextOptions { Exact = false })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });

            var card = page.Locator("article.report-card")
                .Filter(new LocatorFilterOptions { HasText = "Sites report" });
            var csvButton = card.GetByRole(AriaRole.Button, new() { Name = "CSV", Exact = true });
            await WaitUntilEnabledAsync(page, csvButton);
            var download = await page.RunAndWaitForDownloadAsync(
                () => csvButton.ClickAsync(new LocatorClickOptions { Timeout = 5000 }),
                new PageRunAndWaitForDownloadOptions { Timeout = 10000 });

            download.SuggestedFilename.Should().Be("sites-report.csv");
            var path = await download.PathAsync();
            path.Should().NotBeNullOrWhiteSpace();
            var bytes = await File.ReadAllBytesAsync(path!);
            bytes.Should().StartWith(Encoding.UTF8.GetPreamble(), "report CSVs are emitted with the UTF-8 BOM for spreadsheet compatibility");
            var content = Encoding.UTF8.GetString(bytes.AsSpan(Encoding.UTF8.GetPreamble().Length));
            content.Should().StartWith("\"Name\",\"Url\",\"Status\"");
            content.Should().Contain($"\"{SiteName}\"");
            content.Should().Contain($"\"{SiteUri}\"");

            errors.Should().BeEmpty("real reports and CSV download must not produce browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("reports-exports");
        }
    }

    private static async Task WaitUntilEnabledAsync(IPage page, ILocator button)
    {
        await button.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
        var deadline = DateTime.UtcNow.AddSeconds(15);
        while (DateTime.UtcNow < deadline)
        {
            if (await button.IsEnabledAsync()) return;
            await page.WaitForTimeoutAsync(100);
        }

        throw new TimeoutException("Reports export did not become interactive.");
    }

    private async Task SeedOwnedSiteAsync()
    {
        await using var db = CreateDbContext();
        var existing = await db.Sites.SingleOrDefaultAsync(site => site.Name == SiteName);
        if (existing is not null) return;

        var admin = await db.AuthUsers.SingleAsync(user => user.NormalizedUserName == "ADMIN");
        db.Sites.Add(new Site(SiteName, SiteUri, DateTime.UtcNow, admin.Id));
        await db.SaveChangesAsync();
    }

    private AppDbContext CreateDbContext()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX database context factory.");
        return (AppDbContext)(method.Invoke(host, null)
            ?? throw new InvalidOperationException("UX database context factory returned null."));
    }
}
