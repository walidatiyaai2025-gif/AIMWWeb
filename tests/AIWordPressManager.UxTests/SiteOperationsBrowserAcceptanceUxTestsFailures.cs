using System.Reflection;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class SiteOperationsBrowserAcceptanceUxTestsFailures(UxTestHost host)
{
    private const string SiteName = "UX Operations Failure Site";
    private const string OperationName = "Clipboard denial operation";
    private const string Marker = "UX-OPERATIONS-FAILURE-MARKER";

    [Fact]
    public async Task Clipboard_write_denial_is_visible_retryable_and_never_reported_as_success()
    {
        var fixture = await SeedOwnedHistoryAsync();
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            // Do not grant clipboard-write. Clearing permissions makes the real browser permission
            // boundary authoritative; production JS is not patched and no test endpoint is used.
            await context.ClearPermissionsAsync();
            var page = await context.NewPageAsync();
            await page.GotoAsync(host.BaseUrl + $"/site-operations/{fixture.OperationId}", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByRole(AriaRole.Heading, new() { Name = OperationName, Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });

            var copy = page.GetByRole(AriaRole.Button, new() { Name = "Copy report", Exact = true });
            await ClickUntilAsync(
                copy,
                async () => await page.GetByText(
                    "The report could not be written to the clipboard. No copy success was reported; check browser clipboard access and retry.",
                    new() { Exact = true }).CountAsync() == 1,
                "Denied browser clipboard access never surfaced the explicit copy failure state.");

            var body = await page.Locator("body").InnerTextAsync();
            body.Should().Contain("The report could not be written to the clipboard.");
            body.Should().NotContain("Report copied to the clipboard.");
            (await copy.IsEnabledAsync()).Should().BeTrue("a failed clipboard write must remain retryable");
        }
        finally
        {
            await context.CloseBoundedAsync("site-operations-clipboard-denial");
        }
    }

    [Fact]
    public async Task Corrupt_history_fails_closed_on_hub_and_reliability_and_recovers_after_storage_is_restored()
    {
        _ = await SeedOwnedHistoryAsync();
        var path = HistoryPath();
        var original = await File.ReadAllBytesAsync(path);
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            await page.GotoAsync(host.BaseUrl + "/operations", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByText(Marker, new() { Exact = true }).WaitForAsync(
                new() { State = WaitForSelectorState.Visible, Timeout = 10000 });

            const string corrupt = "{ definitely-not-valid-site-operation-history";
            await File.WriteAllTextAsync(path, corrupt);
            await page.ReloadAsync(new() { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            await page.GetByRole(AriaRole.Heading, new() { Name = "Operation history could not be loaded", Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var hubFailure = await page.Locator("body").InnerTextAsync();
            hubFailure.Should().Contain("The retained operation-history source could not be read.");
            hubFailure.Should().NotContain("Recorded 30-day operations");
            hubFailure.Should().NotContain("Recorded success rate");
            hubFailure.Should().NotContain("No operations have been recorded yet.");

            await File.WriteAllBytesAsync(path, original);
            var hubRetry = page.GetByRole(AriaRole.Button, new() { Name = "Retry", Exact = true });
            await ClickUntilAsync(
                hubRetry,
                async () => await page.GetByText(Marker, new() { Exact = true }).CountAsync() == 1,
                "Retry did not recover the operations hub after history was repaired.");
            await page.GetByText(Marker, new() { Exact = true }).WaitForAsync(
                new() { State = WaitForSelectorState.Visible, Timeout = 10000 });

            await File.WriteAllTextAsync(path, corrupt);
            await page.GotoAsync(host.BaseUrl + "/site-reliability", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByRole(AriaRole.Heading, new() { Name = "Reliability history could not be loaded", Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var reliabilityFailure = await page.Locator("body").InnerTextAsync();
            reliabilityFailure.Should().Contain("The retained operation-history source could not be read.");
            reliabilityFailure.Should().NotContain("Total operations");
            reliabilityFailure.Should().NotContain("Recorded success rate");
            reliabilityFailure.Should().NotContain("Excellent");
            reliabilityFailure.Should().NotContain("Critical");

            await File.WriteAllBytesAsync(path, original);
            var reliabilityRetry = page.GetByRole(AriaRole.Button, new() { Name = "Retry", Exact = true });
            var reliabilityTableSite = page.Locator(".site-reliability-page tbody")
                .GetByText(SiteName, new() { Exact = true });
            await ClickUntilAsync(
                reliabilityRetry,
                async () =>
                    await page.GetByRole(AriaRole.Heading, new() { Name = "WordPress Site Reliability", Exact = true }).CountAsync() == 1
                    && await reliabilityTableSite.CountAsync() == 1,
                "Retry did not recover reliability after history was repaired.");
            await page.GetByRole(AriaRole.Heading, new() { Name = "WordPress Site Reliability", Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            await reliabilityTableSite.WaitForAsync(
                new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
        }
        finally
        {
            await File.WriteAllBytesAsync(path, original);
            await context.CloseBoundedAsync("site-operations-hub-reliability-corrupt-history");
        }
    }

    private async Task<FailureFixture> SeedOwnedHistoryAsync()
    {
        ResetHistoryStorage();
        await using var db = CreateDbContext();
        var admin = await db.AuthUsers.SingleAsync(x => x.NormalizedUserName == "ADMIN");
        var site = await EnsureSiteAsync(db, admin.Id);

        var history = new SiteOperationHistoryService(HistoryPath());
        var started = DateTime.UtcNow.AddMinutes(-2);
        history.Record(
            admin.Id,
            site.Id,
            OperationName,
            true,
            Marker,
            "real retained-history browser failure fixture",
            started,
            started.AddSeconds(1),
            1,
            Guid.NewGuid());

        var operationId = history.GetAll(admin.Id, [site.Id], 100)
            .Single(x => x.Message == Marker).Id;
        return new FailureFixture(operationId, site.Id);
    }

    private static async Task<Site> EnsureSiteAsync(AppDbContext db, Guid ownerId)
    {
        var existing = await db.Sites.SingleOrDefaultAsync(x => x.OwnerUserId == ownerId && x.Name == SiteName);
        if (existing is not null) return existing;
        var site = new Site(SiteName, new Uri("https://operations-failure.example.test"), DateTime.UtcNow, ownerId);
        db.Sites.Add(site);
        await db.SaveChangesAsync();
        return site;
    }

    private AppDbContext CreateDbContext()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX database context factory.");
        return (AppDbContext)(method.Invoke(host, null)
            ?? throw new InvalidOperationException("UX database context factory returned null."));
    }

    private string RuntimeRoot()
    {
        var field = typeof(UxTestHost).GetField("_runRoot", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX runtime root.");
        return (string)(field.GetValue(host)
            ?? throw new InvalidOperationException("UX runtime root is unavailable."));
    }

    private string HistoryPath() => Path.Combine(
        RuntimeRoot(), ".local", "share", "AIWordPressManager", "Data", "site-operation-history.json");

    private void ResetHistoryStorage()
    {
        var path = HistoryPath();
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        foreach (var candidate in new[] { path, path + ".tmp", path + ".lock" })
        {
            try { if (File.Exists(candidate)) File.Delete(candidate); }
            catch { }
        }
    }

    private static async Task ClickUntilAsync(ILocator control, Func<Task<bool>> condition, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(12);
        Exception? last = null;
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                if (await condition()) return;
                await control.ClickAsync(new() { Timeout = 1500 });
                if (await condition()) return;
            }
            catch (Exception ex) when (ex is PlaywrightException or InvalidOperationException or TimeoutException)
            {
                last = ex;
                try
                {
                    if (await condition()) return;
                }
                catch (Exception conditionEx) when (conditionEx is PlaywrightException or InvalidOperationException or TimeoutException)
                {
                    last = conditionEx;
                }
            }
            await Task.Delay(100);
        }
        throw new TimeoutException(failure, last);
    }

    private sealed record FailureFixture(Guid OperationId, Guid SiteId);
}