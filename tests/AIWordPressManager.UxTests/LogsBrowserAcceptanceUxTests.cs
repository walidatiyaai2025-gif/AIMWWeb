using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class LogsBrowserAcceptanceUxTests(UxTestHost host)
{
    [Fact]
    public async Task Settings_manage_user_reads_refreshes_filters_and_copies_real_log_file()
    {
        var marker = Guid.NewGuid().ToString("N");
        var fileName = $"ux-browser-logs-{marker}.log";
        var logDirectory = host.RepositoryPath("src", "AIWordPressManager.Web", "bin", "Release", "net8.0", "Logs");
        var logPath = Path.Combine(logDirectory, fileName);
        var infoLine = $"2026-08-24T10:00:00Z Information UX-REAL-{marker} persisted information";
        var warningLine = $"2026-08-24T10:00:01Z Warning UX-WARN-{marker} persisted warning";
        var errorLine = $"2026-08-24T10:00:02Z Error ERR-UX9001 UX-ERROR-{marker} persisted failure";
        var refreshLine = $"2026-08-24T10:00:03Z Critical UX-REFRESH-{marker} appended after initial browser load";

        Directory.CreateDirectory(logDirectory);
        if (File.Exists(logPath)) File.Delete(logPath);

        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            await context.GrantPermissionsAsync(
                ["clipboard-read", "clipboard-write"],
                new BrowserContextGrantPermissionsOptions { Origin = host.BaseUrl });

            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            var response = await page.GotoAsync(host.BaseUrl + "/logs", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);
            await page.GetByRole(AriaRole.Heading, new() { Name = "Logs & Error Center" }).WaitForAsync();

            var fixtureFile = page.Locator(".logs-files button").Filter(new LocatorFilterOptions { HasText = fileName });
            (await fixtureFile.CountAsync()).Should().Be(0,
                "the page must not fabricate a test log before the real file exists on disk");

            await File.WriteAllLinesAsync(logPath, [infoLine, warningLine, errorLine]);
            var refresh = page.Locator(".logs-hero-actions .btn.primary");
            await ClickUntilAsync(refresh,
                async () => await fixtureFile.CountAsync() == 1,
                "Refresh did not discover the newly-created real log file.");

            await fixtureFile.ClickAsync();
            var lines = page.Locator(".logs-console .log-line");
            await WaitUntilAsync(async () => await lines.CountAsync() == 3,
                "Selecting the real log file did not render exactly its three persisted lines.");

            var rendered = await lines.AllInnerTextsAsync();
            rendered.Should().HaveCount(3);
            rendered.Should().Contain(text => text.Contains(infoLine, StringComparison.Ordinal));
            rendered.Should().Contain(text => text.Contains(warningLine, StringComparison.Ordinal));
            rendered.Should().Contain(text => text.Contains(errorLine, StringComparison.Ordinal));
            rendered.Should().OnlyContain(text =>
                text.Contains(marker, StringComparison.Ordinal) || text.Contains("ERR-UX9001", StringComparison.Ordinal),
                "the selected-file console must be a direct rendering of real file content, not sample rows");

            var level = page.Locator(".logs-filters select").Nth(0);
            await level.SelectOptionAsync("Error");
            await WaitUntilAsync(async () => await page.Locator(".logs-console .log-line").CountAsync() == 1,
                "The Error filter did not reduce the real file to its persisted error line.");

            var visibleError = page.Locator(".logs-console .log-line").First;
            (await visibleError.InnerTextAsync()).Should().Contain(errorLine);

            var copyResults = page.GetByRole(AriaRole.Button, new() { Name = "Copy results" });
            await copyResults.ClickAsync();
            await page.GetByText("Visible results copied.", new() { Exact = true }).WaitForAsync();
            var copiedResults = await page.EvaluateAsync<string>("navigator.clipboard.readText()");
            copiedResults.Should().Be($"[Error] {errorLine}",
                "copy success must correspond to the real browser clipboard receiving the visible persisted row");

            await visibleError.ClickAsync();
            await page.GetByRole(AriaRole.Heading, new() { Name = "Line details #3" }).WaitForAsync();
            var copyDetails = page.GetByRole(AriaRole.Button, new() { Name = "Copy full details" });
            await copyDetails.ClickAsync();
            await page.GetByText("Error details copied.", new() { Exact = true }).WaitForAsync();
            var copiedDetails = await page.EvaluateAsync<string>("navigator.clipboard.readText()");
            copiedDetails.Should().Contain($"File: {fileName}");
            copiedDetails.Should().Contain("Line: 3");
            copiedDetails.Should().Contain("Level: Error");
            copiedDetails.Should().Contain("Error code: ERR-UX9001");
            copiedDetails.Should().Contain(errorLine);

            await File.AppendAllLinesAsync(logPath, [refreshLine]);
            await level.SelectOptionAsync("");
            await refresh.ClickAsync();
            await page.GetByText(refreshLine, new() { Exact = false }).WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });

            pageErrors.Should().BeEmpty("real log listing, reading, refresh and clipboard operations must not cause browser errors");
        }
        finally
        {
            try { if (File.Exists(logPath)) File.Delete(logPath); } catch { }
            await context.CloseBoundedAsync("logs-browser-acceptance");
        }
    }

    [Fact]
    public async Task Logs_routes_deny_authenticated_user_without_Settings_manage()
    {
        var (context, _) = await host.CreateContentViewerContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            foreach (var path in new[] { "/logs", "/module/logs" })
            {
                var page = await context.NewPageAsync();
                var response = await page.GotoAsync(host.BaseUrl + path, new PageGotoOptions
                {
                    WaitUntil = WaitUntilState.DOMContentLoaded,
                    Timeout = 10000
                });
                response.Should().NotBeNull();
                response!.Status.Should().BeLessThan(400);

                await page.GetByRole(AriaRole.Heading, new() { Name = "Access denied" }).WaitForAsync(new LocatorWaitForOptions
                {
                    State = WaitForSelectorState.Visible,
                    Timeout = 10000
                });
                (await page.GetByText("403", new() { Exact = true }).CountAsync()).Should().BeGreaterThan(0);
                (await page.Locator(".logs-page").CountAsync()).Should().Be(0,
                    "a user without Settings.Manage must never render the log listing or log contents");
                await page.CloseAsync();
            }
        }
        finally
        {
            await context.CloseBoundedAsync("logs-settings-manage-denial");
        }
    }

    private static async Task ClickUntilAsync(ILocator control, Func<Task<bool>> condition, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        Exception? lastError = null;
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await control.ClickAsync(new LocatorClickOptions { Timeout = 1500 });
                if (await condition()) return;
            }
            catch (Exception ex) when (ex is TimeoutException or PlaywrightException)
            {
                lastError = ex;
            }
            await Task.Delay(150);
        }
        throw new TimeoutException(failure, lastError);
    }

    private static async Task WaitUntilAsync(Func<Task<bool>> condition, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        Exception? lastError = null;
        while (DateTime.UtcNow < deadline)
        {
            try { if (await condition()) return; }
            catch (Exception ex) when (ex is PlaywrightException or InvalidOperationException) { lastError = ex; }
            await Task.Delay(100);
        }
        throw new TimeoutException(failure, lastError);
    }
}
