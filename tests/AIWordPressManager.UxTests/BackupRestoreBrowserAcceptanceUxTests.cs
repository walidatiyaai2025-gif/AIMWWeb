using System.IO.Compression;
using System.Reflection;
using System.Text.Json;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class BackupRestoreBrowserAcceptanceUxTests(UxTestHost host)
{
    [Fact]
    public async Task Administrator_creates_inspects_preflights_reloads_copies_and_deletes_real_backup()
    {
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            await context.GrantPermissionsAsync(
                ["clipboard-read", "clipboard-write"],
                new BrowserContextGrantPermissionsOptions { Origin = host.BaseUrl });

            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            await NavigateToBackupsAsync(page);
            await page.GetByText("No backups yet", new() { Exact = true }).WaitForAsync();

            var backupDirectory = await CopyRealBackupPathAsync(page);
            backupDirectory.Should().Be(Path.Combine(ApplicationRoot(), "Backups"));
            await PrepareManagedSqliteFixtureAsync();

            var note = "UX verified backup " + Guid.NewGuid().ToString("N");
            await page.GetByText("Backup note", new() { Exact = true })
                .Locator("..").Locator("input").FillAsync(note);

            var create = page.GetByRole(AriaRole.Button, new() { Name = "Create backup", Exact = false });
            await ClickUntilAsync(
                create,
                async () => await page.GetByText(note, new() { Exact = true }).CountAsync() == 1,
                "The browser create action never reconciled a real backup row.");

            var row = page.Locator(".backup-row").Filter(new LocatorFilterOptions { HasText = note }).First;
            await row.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            (await row.InnerTextAsync()).Should().Contain("Verified");
            (await page.Locator(".backup-stats article").Nth(0).InnerTextAsync()).Should().Contain("1");

            var backupFile = (await row.Locator("strong").First.InnerTextAsync()).Trim();
            File.Exists(Path.Combine(backupDirectory, backupFile)).Should().BeTrue(
                "the visible backup row must correspond to a real archive on disk");

            await row.GetByRole(AriaRole.Button, new() { Name = "Inspect", Exact = false }).ClickAsync();
            await page.GetByRole(AriaRole.Heading, new() { Name = "Backup inspection", Exact = true }).WaitForAsync();
            var inspection = page.GetByRole(AriaRole.Heading, new() { Name = "Backup inspection", Exact = true })
                .Locator("xpath=ancestor::section[1]");
            (await inspection.InnerTextAsync()).Should().Contain("VALID");
            (await inspection.InnerTextAsync()).Should().Contain("SQLite");
            (await inspection.InnerTextAsync()).Should().Contain("Data/ux-browser-backup.db");
            (await inspection.InnerTextAsync()).Should().Contain("Config/setup.database.json");

            await row.GetByRole(AriaRole.Button, new() { Name = "Preflight", Exact = false }).ClickAsync();
            await page.GetByRole(AriaRole.Heading, new() { Name = "Restore readiness", Exact = true }).WaitForAsync();
            var readiness = page.GetByRole(AriaRole.Heading, new() { Name = "Restore readiness", Exact = true })
                .Locator("xpath=ancestor::section[1]");
            var readinessText = await readiness.InnerTextAsync();
            readinessText.Should().Contain("BLOCKED");
            readinessText.Should().Contain("Application state");
            readinessText.Should().Contain("Restore must run while the web application is stopped");
            readinessText.Should().Contain("In-process restore remains blocked");

            await page.GetByRole(AriaRole.Button, new() { Name = "Refresh", Exact = true }).ClickAsync();
            await page.ReloadAsync(new() { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            await page.GetByText(note, new() { Exact = true }).WaitForAsync();
            (await page.Locator(".backup-row").Filter(new LocatorFilterOptions { HasText = note }).CountAsync()).Should().Be(1,
                "refresh and browser reload must reconcile the durable archive rather than a local-only row");

            row = page.Locator(".backup-row").Filter(new LocatorFilterOptions { HasText = note }).First;
            await row.GetByRole(AriaRole.Button, new() { Name = "Delete", Exact = false }).ClickAsync();
            await page.GetByText("Backup deleted.", new() { Exact = true }).WaitForAsync();
            await WaitUntilAsync(
                async () => await page.GetByText(note, new() { Exact = true }).CountAsync() == 0,
                "Delete success did not reconcile the visible archive out of the list.");
            File.Exists(Path.Combine(backupDirectory, backupFile)).Should().BeFalse(
                "delete success must correspond to the real archive being removed");
            (await page.Locator(".backup-list").Last.InnerTextAsync()).Should().Contain("Delete");

            pageErrors.Should().BeEmpty("real backup create/inspect/preflight/reload/delete actions must not cause browser errors");
        }
        finally
        {
            CleanupApplicationRoot();
            await context.CloseBoundedAsync("backup-restore-browser-real-flow");
        }
    }

    [Fact]
    public async Task Invalid_archive_fails_closed_and_in_process_restore_never_appears_actionable()
    {
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            await context.GrantPermissionsAsync(
                ["clipboard-read", "clipboard-write"],
                new BrowserContextGrantPermissionsOptions { Origin = host.BaseUrl });
            var page = await context.NewPageAsync();
            await NavigateToBackupsAsync(page);
            var backupDirectory = await CopyRealBackupPathAsync(page);

            var fileName = $"AIWM-Backup-20260827-000000-{Guid.NewGuid():N}.zip";
            var corruptPath = Path.Combine(backupDirectory, fileName);
            using (var archive = ZipFile.Open(corruptPath, ZipArchiveMode.Create))
            {
                var entry = archive.CreateEntry("unexpected.txt");
                await using var writer = new StreamWriter(entry.Open());
                await writer.WriteAsync("not a backup manifest");
            }

            var refresh = page.GetByRole(AriaRole.Button, new() { Name = "Refresh", Exact = true });
            await ClickUntilAsync(
                refresh,
                async () => await page.GetByText(fileName, new() { Exact = true }).CountAsync() == 1,
                "Refresh did not discover the real invalid archive on disk.");

            var row = page.Locator(".backup-row").Filter(new LocatorFilterOptions { HasText = fileName }).First;
            var rowText = await row.InnerTextAsync();
            rowText.Should().Contain("Invalid");
            rowText.Should().NotContain("Verified");

            await row.GetByRole(AriaRole.Button, new() { Name = "Inspect", Exact = false }).ClickAsync();
            await page.GetByRole(AriaRole.Heading, new() { Name = "Backup inspection", Exact = true }).WaitForAsync();
            var inspectionText = await page.GetByRole(AriaRole.Heading, new() { Name = "Backup inspection", Exact = true })
                .Locator("xpath=ancestor::section[1]").InnerTextAsync();
            inspectionText.Should().Contain("INVALID");
            inspectionText.Should().Contain("manifest is missing");

            await row.GetByRole(AriaRole.Button, new() { Name = "Preflight", Exact = false }).ClickAsync();
            await page.GetByRole(AriaRole.Heading, new() { Name = "Restore readiness", Exact = true }).WaitForAsync();
            var body = await page.Locator("body").InnerTextAsync();
            body.Should().Contain("BLOCKED");
            body.Should().Contain("In-process restore remains blocked");
            body.Should().NotContain("Restore now");
            body.Should().NotContain("Restore completed");

            await row.GetByRole(AriaRole.Button, new() { Name = "Delete", Exact = false }).ClickAsync();
            await page.GetByText("Backup deleted.", new() { Exact = true }).WaitForAsync();
            File.Exists(corruptPath).Should().BeFalse();
        }
        finally
        {
            CleanupApplicationRoot();
            await context.CloseBoundedAsync("backup-restore-invalid-archive");
        }
    }

    [Fact]
    public async Task Backup_routes_deny_non_administrator_and_clipboard_denial_never_reports_success()
    {
        var (viewerContext, _) = await host.CreateContentViewerContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            foreach (var path in new[] { "/backups", "/module/backups" })
            {
                var page = await viewerContext.NewPageAsync();
                var response = await page.GotoAsync(host.BaseUrl + path, new PageGotoOptions
                {
                    WaitUntil = WaitUntilState.DOMContentLoaded,
                    Timeout = 10000
                });
                response.Should().NotBeNull();
                response!.Status.Should().BeLessThan(400);
                await page.GetByRole(AriaRole.Heading, new() { Name = "Access denied", Exact = true }).WaitForAsync();
                (await page.GetByText("403", new() { Exact = true }).CountAsync()).Should().BeGreaterThan(0);
                (await page.Locator(".backup-page").CountAsync()).Should().Be(0);
                await page.CloseAsync();
            }
        }
        finally
        {
            await viewerContext.CloseBoundedAsync("backup-restore-non-admin-denial");
        }

        var adminContext = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            await adminContext.ClearPermissionsAsync();
            var page = await adminContext.NewPageAsync();
            await NavigateToBackupsAsync(page);
            var copy = page.GetByRole(AriaRole.Button, new() { Name = "Copy path", Exact = true });
            await ClickUntilAsync(
                copy,
                async () => await page.GetByText("Could not copy the backup path", new() { Exact = true }).CountAsync() == 1,
                "Denied real browser clipboard access never surfaced the backup-path copy failure.");
            var body = await page.Locator("body").InnerTextAsync();
            body.Should().Contain("Could not copy the backup path");
            body.Should().NotContain("Backup path copied.");
            (await copy.IsEnabledAsync()).Should().BeTrue("clipboard failure must remain retryable");
        }
        finally
        {
            await adminContext.CloseBoundedAsync("backup-restore-clipboard-denial");
        }
    }

    private async Task NavigateToBackupsAsync(IPage page)
    {
        var response = await page.GotoAsync(host.BaseUrl + "/backups", new PageGotoOptions
        {
            WaitUntil = WaitUntilState.DOMContentLoaded,
            Timeout = 10000
        });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);
        await page.GetByRole(AriaRole.Heading, new() { Name = "Backup & Restore Safety Center", Exact = true }).WaitForAsync();
    }

    private async Task<string> CopyRealBackupPathAsync(IPage page)
    {
        var copy = page.GetByRole(AriaRole.Button, new() { Name = "Copy path", Exact = true });
        await ClickUntilAsync(
            copy,
            async () => await page.GetByText("Backup path copied.", new() { Exact = true }).CountAsync() == 1,
            "Copy path did not report browser-confirmed success.");
        var path = await page.EvaluateAsync<string>("navigator.clipboard.readText()");
        path.Should().NotBeNullOrWhiteSpace();
        Directory.Exists(path).Should().BeTrue("the copied path must be the real backup directory");
        return path;
    }

    private async Task PrepareManagedSqliteFixtureAsync()
    {
        CleanupApplicationRoot();
        var root = ApplicationRoot();
        var data = Path.Combine(root, "Data");
        var config = Path.Combine(root, "Config");
        var backups = Path.Combine(root, "Backups");
        Directory.CreateDirectory(data);
        Directory.CreateDirectory(config);
        Directory.CreateDirectory(backups);

        var dbPath = Path.Combine(data, "ux-browser-backup.db");
        await using (var connection = new SqliteConnection($"Data Source={dbPath};Pooling=False"))
        {
            await connection.OpenAsync();
            await using var command = connection.CreateCommand();
            command.CommandText = "CREATE TABLE Evidence (Id INTEGER PRIMARY KEY, Marker TEXT NOT NULL); INSERT INTO Evidence(Marker) VALUES ('browser-real-backup');";
            await command.ExecuteNonQueryAsync();
        }

        var setup = new
        {
            Database = new
            {
                Provider = "SQLite",
                ConnectionString = $"Data Source={dbPath};Foreign Keys=True;Pooling=False"
            }
        };
        await File.WriteAllTextAsync(
            Path.Combine(config, "setup.database.json"),
            JsonSerializer.Serialize(setup));
    }

    private string ApplicationRoot() => Path.Combine(
        RuntimeRoot(), ".local", "share", "AIWordPressManager", "Development");

    private string RuntimeRoot()
    {
        var field = typeof(UxTestHost).GetField("_runRoot", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX runtime root.");
        return (string)(field.GetValue(host)
            ?? throw new InvalidOperationException("UX runtime root is unavailable."));
    }

    private void CleanupApplicationRoot()
    {
        var root = ApplicationRoot();
        try { if (Directory.Exists(root)) Directory.Delete(root, recursive: true); }
        catch { }
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
                await control.ClickAsync(new LocatorClickOptions { Timeout = 1500 });
                if (await condition()) return;
            }
            catch (Exception ex) when (ex is PlaywrightException or InvalidOperationException or TimeoutException)
            {
                last = ex;
            }
            await Task.Delay(100);
        }
        throw new TimeoutException(failure, last);
    }

    private static async Task WaitUntilAsync(Func<Task<bool>> condition, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        Exception? last = null;
        while (DateTime.UtcNow < deadline)
        {
            try { if (await condition()) return; }
            catch (Exception ex) when (ex is PlaywrightException or InvalidOperationException) { last = ex; }
            await Task.Delay(100);
        }
        throw new TimeoutException(failure, last);
    }
}
