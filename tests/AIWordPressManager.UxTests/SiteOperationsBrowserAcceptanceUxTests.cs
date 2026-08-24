using System.Reflection;
using System.Text;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class SiteOperationsBrowserAcceptanceUxTests(UxTestHost host)
{
    private const string ViewRole = "ux.operations.viewer";
    private const string ViewUser = "operations.viewer.ux";
    private const string ViewPassword = "OpsViewer123!";
    private const string OwnedSiteName = "UX Operations Owned Site";
    private const string OtherSiteName = "UX Operations Other Site";
    private const string ViewSiteName = "UX Operations View Site";
    private const string OwnedSyncMarker = "UX-OWNED-SYNC";
    private const string OwnedConnectionMarker = "UX-OWNED-CONNECTION";
    private const string OwnedBulkMarker = "UX-OWNED-BULK";
    private const string OtherSecretMarker = "UX-OTHER-OWNER-SECRET";

    [Fact]
    public async Task Authorization_boundaries_are_enforced_for_read_and_maintenance_surfaces()
    {
        await EnsureOperationsViewerAsync();

        var anonymous = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900), authenticated: false);
        try
        {
            var page = await anonymous.NewPageAsync();
            await page.GotoAsync(host.BaseUrl + "/operations", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            page.Url.Should().Contain("/login");
            (await page.Locator(".operations-hub-page").CountAsync()).Should().Be(0);
        }
        finally
        {
            await anonymous.CloseBoundedAsync("site-operations-anonymous-denial");
        }

        var (withoutOperations, _) = await host.CreateContentViewerContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            foreach (var path in new[] { "/operations", "/site-operations", "/site-reliability" })
            {
                var page = await withoutOperations.NewPageAsync();
                await page.GotoAsync(host.BaseUrl + path, new PageGotoOptions
                {
                    WaitUntil = WaitUntilState.DOMContentLoaded,
                    Timeout = 10000
                });
                await page.GetByRole(AriaRole.Heading, new() { Name = "Access denied", Exact = true })
                    .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
                await page.CloseAsync();
            }
        }
        finally
        {
            await withoutOperations.CloseBoundedAsync("site-operations-view-denial");
        }

        var operationsView = await LoginAsync(ViewUser, ViewPassword);
        try
        {
            foreach (var route in new[]
            {
                (Path: "/operations", Heading: "WordPress Site Operations Hub"),
                (Path: "/site-operations", Heading: "WordPress Site Operations"),
                (Path: "/site-reliability", Heading: "WordPress Site Reliability")
            })
            {
                var page = await operationsView.NewPageAsync();
                var response = await page.GotoAsync(host.BaseUrl + route.Path, new PageGotoOptions
                {
                    WaitUntil = WaitUntilState.DOMContentLoaded,
                    Timeout = 10000
                });
                response.Should().NotBeNull();
                response!.Status.Should().BeLessThan(400);
                await page.GetByRole(AriaRole.Heading, new() { Name = route.Heading, Exact = true })
                    .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
                await page.CloseAsync();
            }

            var maintenance = await operationsView.NewPageAsync();
            await maintenance.GotoAsync(host.BaseUrl + "/operations/maintenance", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await maintenance.GetByRole(AriaRole.Heading, new() { Name = "Access denied", Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            (await maintenance.GetByText("Site Operation History Maintenance", new() { Exact = true }).CountAsync()).Should().Be(0);
        }
        finally
        {
            await operationsView.CloseBoundedAsync("site-operations-view-only");
        }
    }

    [Fact]
    public async Task Persisted_owned_history_drives_overview_filters_csv_details_clipboard_and_reliability_without_cross_owner_leakage()
    {
        var fixture = await SeedHistoryAsync(includeCleanupHistory: false);
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            await context.GrantPermissionsAsync(
                ["clipboard-read", "clipboard-write"],
                new BrowserContextGrantPermissionsOptions { Origin = host.BaseUrl });
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, error) => pageErrors.Add(error);

            await page.GotoAsync(host.BaseUrl + "/operations", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByRole(AriaRole.Heading, new() { Name = "WordPress Site Operations Hub", Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            (await page.ContentAsync()).Should().Contain(OwnedSyncMarker).And.NotContain(OtherSecretMarker);

            await page.GotoAsync(host.BaseUrl + "/site-operations", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByRole(AriaRole.Heading, new() { Name = "WordPress Site Operations", Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            await page.GetByText(OwnedSyncMarker, new() { Exact = true }).WaitForAsync();
            var overviewHtml = await page.ContentAsync();
            overviewHtml.Should().Contain(OwnedConnectionMarker).And.Contain(OwnedBulkMarker).And.NotContain(OtherSecretMarker);

            var search = page.GetByPlaceholder("Search...", new() { Exact = true });
            await FillUntilAsync(
                search,
                OwnedConnectionMarker,
                async () =>
                {
                    var body = page.Locator("tbody");
                    var text = await body.InnerTextAsync();
                    return await page.Locator("tbody tr").CountAsync() == 1
                        && text.Contains(OwnedConnectionMarker, StringComparison.Ordinal)
                        && !text.Contains(OwnedSyncMarker, StringComparison.Ordinal);
                },
                "Search did not reconcile the rendered persisted history.");

            await FillUntilAsync(
                search,
                OtherSecretMarker,
                async () =>
                    await page.GetByText("No operations match the current filters.", new() { Exact = true }).CountAsync() == 1
                    && await page.GetByRole(AriaRole.Button, new() { Name = "Export CSV", Exact = true }).IsDisabledAsync(),
                "Cross-owner search did not reconcile to an honest empty result with export disabled.");

            await page.GetByRole(AriaRole.Button, new() { Name = "Reset filters", Exact = true }).ClickAsync();
            var fromDate = page.Locator("input[aria-label='From date']");
            var toDate = page.Locator("input[aria-label='To date']");
            await FillUntilAsync(
                fromDate,
                DateTime.Today.ToString("yyyy-MM-dd"),
                async () => (await fromDate.InputValueAsync()) == DateTime.Today.ToString("yyyy-MM-dd"),
                "From-date input did not become interactive.");
            await FillUntilAsync(
                toDate,
                DateTime.Today.AddDays(-1).ToString("yyyy-MM-dd"),
                async () => await page.GetByText("The start date cannot be after the end date. Correct the range before reviewing results or exporting CSV.", new() { Exact = true }).CountAsync() == 1,
                "Invalid date range did not surface its honest validation state.");
            (await page.GetByRole(AriaRole.Button, new() { Name = "Export CSV", Exact = true }).IsDisabledAsync()).Should().BeTrue();

            await page.GetByRole(AriaRole.Button, new() { Name = "Reset filters", Exact = true }).ClickAsync();
            var export = page.GetByRole(AriaRole.Button, new() { Name = "Export CSV", Exact = true });
            await WaitUntilEnabledAsync(export);
            var download = await page.RunAndWaitForDownloadAsync(
                () => export.ClickAsync(new() { Timeout = 5000 }),
                new PageRunAndWaitForDownloadOptions { Timeout = 10000 });
            var downloadPath = await download.PathAsync();
            downloadPath.Should().NotBeNullOrWhiteSpace();
            var csv = await File.ReadAllTextAsync(downloadPath!);
            csv.Should().Contain(OwnedSyncMarker).And.Contain(OwnedConnectionMarker).And.Contain(OwnedBulkMarker);
            csv.Should().Contain(OwnedSiteName).And.NotContain(OtherSecretMarker).And.NotContain(OtherSiteName);

            var ownedRow = page.Locator("tbody tr").Filter(new LocatorFilterOptions { HasText = OwnedSyncMarker });
            await ownedRow.GetByRole(AriaRole.Link, new() { Name = "Details", Exact = true }).ClickAsync();
            await page.GetByRole(AriaRole.Heading, new() { Name = "Synchronization", Exact = true }).WaitForAsync();
            (await page.ContentAsync()).Should().Contain(OwnedSyncMarker).And.NotContain(OtherSecretMarker);
            await page.GetByRole(AriaRole.Button, new() { Name = "Copy report", Exact = true }).ClickAsync();
            await page.GetByText("Report copied to the clipboard.", new() { Exact = true }).WaitForAsync();
            var clipboard = await page.EvaluateAsync<string>("navigator.clipboard.readText()");
            clipboard.Should().Contain(OwnedSyncMarker).And.Contain(fixture.OwnedSiteId.ToString()).And.NotContain(OtherSecretMarker);

            await page.GotoAsync(host.BaseUrl + $"/site-operations/{fixture.OtherOperationId}", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByRole(AriaRole.Heading, new() { Name = "Operation not found", Exact = true }).WaitForAsync();
            (await page.ContentAsync()).Should().NotContain(OtherSecretMarker);

            await page.GotoAsync(host.BaseUrl + "/site-reliability", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByRole(AriaRole.Heading, new() { Name = "WordPress Site Reliability", Exact = true }).WaitForAsync();
            await page.Locator(".site-reliability-page tbody").GetByText(OwnedSiteName, new() { Exact = true }).WaitForAsync();
            (await StatValueAsync(page, "Total operations")).Should().Be("2",
                "reliability must use only persisted connection-test and synchronization records");
            var reliability = await page.ContentAsync();
            reliability.Should().NotContain(OtherSiteName).And.NotContain(OtherSecretMarker);
            reliability.Should().NotContain("Excellent").And.NotContain("Critical");

            pageErrors.Should().BeEmpty("real Site Operations read/export/report flows must not cause browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("site-operations-owned-read");
        }
    }

    [Fact]
    public async Task Maintenance_preview_cancel_and_confirm_are_owner_scoped_audited_and_durably_reconciled()
    {
        var fixture = await SeedHistoryAsync(includeCleanupHistory: true);
        var history = new SiteOperationHistoryService(HistoryPath());
        var beforeOwned = history.GetAll(fixture.AdminUserId, [fixture.OwnedSiteId], 2000).Count;
        var beforeOther = history.GetAll(fixture.OtherUserId, [fixture.OtherSiteId], 2000).Count;

        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            await page.GotoAsync(host.BaseUrl + "/operations/maintenance", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByRole(AriaRole.Heading, new() { Name = "Site Operation History Maintenance", Exact = true }).WaitForAsync();
            var pageText = await page.ContentAsync();
            pageText.Should().NotContain("site-operation-history.json").And.NotContain(RuntimeRoot());
            (await StatValueAsync(page, "Total records")).Should().Be(beforeOwned.ToString());
            (await StatValueAsync(page, "Sites represented")).Should().Be("1");

            var keepLatest = MaintenanceSelect(page, "Always keep the newest");
            await SelectUntilAsync(
                keepLatest,
                "50",
                async () => int.TryParse(await StatValueAsync(page, "Will be removed"), out var count) && count > 0,
                "Maintenance preview never exposed owner-scoped removable persisted records.");
            var removable = int.Parse(await StatValueAsync(page, "Will be removed"));
            removable.Should().BeGreaterThan(0);
            history.GetAll(fixture.AdminUserId, [fixture.OwnedSiteId], 2000).Should().HaveCount(beforeOwned,
                "preview must not mutate persisted history");

            var delete = page.GetByRole(AriaRole.Button, new() { Name = $"Delete {removable} records", Exact = true });
            await FillUntilAsync(
                MaintenanceInput(page, "Type CLEANUP to confirm"),
                "CLEANUP",
                async () => await delete.IsEnabledAsync(),
                "Typed CLEANUP did not enable the owner-scoped cleanup action.");

            EventHandler<IDialog>? dismiss = null;
            dismiss = async (_, dialog) =>
            {
                page.Dialog -= dismiss;
                await dialog.DismissAsync();
            };
            page.Dialog += dismiss;
            await delete.ClickAsync();
            await page.GetByText("Cleanup cancelled. No records were removed.", new() { Exact = true }).WaitForAsync();
            history.GetAll(fixture.AdminUserId, [fixture.OwnedSiteId], 2000).Should().HaveCount(beforeOwned);
            history.GetAll(fixture.OtherUserId, [fixture.OtherSiteId], 2000).Should().HaveCount(beforeOther);

            EventHandler<IDialog>? accept = null;
            accept = async (_, dialog) =>
            {
                page.Dialog -= accept;
                await dialog.AcceptAsync();
            };
            page.Dialog += accept;
            await delete.ClickAsync();
            await page.GetByText($"Removed {removable} records from your account scope; {beforeOwned - removable} records remain.", new() { Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });

            var afterOwned = history.GetAll(fixture.AdminUserId, [fixture.OwnedSiteId], 2000).Count;
            afterOwned.Should().Be(beforeOwned - removable);
            history.GetAll(fixture.OtherUserId, [fixture.OtherSiteId], 2000).Should().HaveCount(beforeOther,
                "cleanup must never remove another owner's retained history");

            await using (var db = CreateDbContext())
            {
                var audits = await new ApplicationSecurityAuditStore(db).ListAsync(
                    new SecurityAuditQuery(Category: "SiteOperations", Action: "HistoryCleanup", ActorUserId: fixture.AdminUserId, Take: 20));
                audits.Select(x => x.Outcome).Should().Contain(["Requested", "Succeeded"]);
                audits.Single(x => x.Outcome == "Succeeded").Metadata["removedCount"].Should().Be(removable.ToString());
            }

            await page.ReloadAsync(new() { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            await page.GetByRole(AriaRole.Heading, new() { Name = "Site Operation History Maintenance", Exact = true }).WaitForAsync();
            (await StatValueAsync(page, "Total records")).Should().Be(afterOwned.ToString(),
                "post-cleanup UI must reconcile from the durable history file after reload");
        }
        finally
        {
            await context.CloseBoundedAsync("site-operations-maintenance");
        }
    }

    [Fact]
    public async Task Corrupt_real_history_is_visible_retryable_and_never_reported_as_empty_or_cleaned()
    {
        var fixture = await SeedHistoryAsync(includeCleanupHistory: true);
        var path = HistoryPath();
        var original = await File.ReadAllBytesAsync(path);
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            await page.GotoAsync(host.BaseUrl + "/site-operations", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByText(OwnedSyncMarker, new() { Exact = true }).WaitForAsync();

            const string corrupt = "{ definitely-not-valid-site-operation-history";
            await File.WriteAllTextAsync(path, corrupt);
            var refresh = page.Locator(".site-operations-page").GetByRole(AriaRole.Button, new() { Name = "Refresh", Exact = true });
            await ClickUntilAsync(
                refresh,
                async () => await page.GetByRole(AriaRole.Heading, new() { Name = "Operation history could not be loaded", Exact = true }).CountAsync() == 1,
                "Refresh did not surface the persisted operation-history read failure.");
            await page.GetByRole(AriaRole.Heading, new() { Name = "Operation history could not be loaded", Exact = true }).WaitForAsync();
            var failureHtml = await page.ContentAsync();
            failureHtml.Should().Contain("The retained operation-history source could not be read.");
            failureHtml.Should().NotContain("No operations match the current filters.");
            await page.GetByRole(AriaRole.Button, new() { Name = "Retry", Exact = true }).WaitForAsync();

            await File.WriteAllBytesAsync(path, original);
            await page.GetByRole(AriaRole.Button, new() { Name = "Retry", Exact = true }).ClickAsync();
            await page.GetByText(OwnedSyncMarker, new() { Exact = true }).WaitForAsync();

            await page.GotoAsync(host.BaseUrl + "/operations/maintenance", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByRole(AriaRole.Heading, new() { Name = "Site Operation History Maintenance", Exact = true }).WaitForAsync();
            var keepLatest = MaintenanceSelect(page, "Always keep the newest");
            await SelectUntilAsync(
                keepLatest,
                "50",
                async () => int.TryParse(await StatValueAsync(page, "Will be removed"), out var count) && count > 0,
                "Maintenance failure fixture did not have removable records.");
            var removable = int.Parse(await StatValueAsync(page, "Will be removed"));
            var delete = page.GetByRole(AriaRole.Button, new() { Name = $"Delete {removable} records", Exact = true });
            await FillUntilAsync(
                MaintenanceInput(page, "Type CLEANUP to confirm"),
                "CLEANUP",
                async () => await delete.IsEnabledAsync(),
                "Typed CLEANUP did not enable cleanup before the controlled storage failure.");

            await File.WriteAllTextAsync(path, corrupt);
            EventHandler<IDialog>? accept = null;
            accept = async (_, dialog) =>
            {
                page.Dialog -= accept;
                await dialog.AcceptAsync();
            };
            page.Dialog += accept;
            await delete.ClickAsync();
            await page.GetByText("Operation history could not be read safely. Corrupt data was not treated as empty and no success was recorded.", new() { Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            (await File.ReadAllTextAsync(path)).Should().Be(corrupt,
                "failed cleanup must not rewrite corrupt storage into an empty or synthetic successful state");
            (await page.ContentAsync()).Should().NotContain("Removed ");
        }
        finally
        {
            await File.WriteAllBytesAsync(path, original);
            await context.CloseBoundedAsync("site-operations-corrupt-history");
        }
    }

    private async Task<HistoryFixture> SeedHistoryAsync(bool includeCleanupHistory)
    {
        ResetHistoryStorage();
        await using var db = CreateDbContext();
        var admin = await db.AuthUsers.SingleAsync(x => x.NormalizedUserName == "ADMIN");
        var other = await EnsureUserAsync(db, "operations.other.ux", "OtherOwner123!", "User");
        var ownedSite = await EnsureSiteAsync(db, admin.Id, OwnedSiteName, "https://operations-owned.example.test");
        var otherSite = await EnsureSiteAsync(db, other.Id, OtherSiteName, "https://operations-other.example.test");

        var history = new SiteOperationHistoryService(HistoryPath());
        var now = DateTime.UtcNow;
        history.Record(admin.Id, ownedSite.Id, "Synchronization", true, OwnedSyncMarker, "persisted owned synchronization", now.AddMinutes(-10), now.AddMinutes(-9), 11, Guid.NewGuid());
        history.Record(admin.Id, ownedSite.Id, "Connection Test", false, OwnedConnectionMarker, "persisted owned connection failure", now.AddMinutes(-8), now.AddMinutes(-7), null, null);
        history.Record(admin.Id, ownedSite.Id, "Bulk Trash", false, OwnedBulkMarker, "must remain history but not reliability input", now.AddMinutes(-6), now.AddMinutes(-5), 3, Guid.NewGuid());
        history.Record(other.Id, otherSite.Id, "Synchronization", true, OtherSecretMarker, "cross-owner secret detail", now.AddMinutes(-4), now.AddMinutes(-3), 99, Guid.NewGuid());

        if (includeCleanupHistory)
        {
            var old = now.AddDays(-180);
            for (var i = 0; i < 55; i++)
            {
                history.Record(admin.Id, ownedSite.Id, "MaintenanceFixture", true, $"UX-OLD-OWNED-{i:00}", null,
                    old.AddMinutes(i), old.AddMinutes(i).AddSeconds(1));
            }
            history.Record(other.Id, otherSite.Id, "MaintenanceFixture", true, "UX-OLD-OTHER-OWNER", null,
                old.AddHours(2), old.AddHours(2).AddSeconds(1));
        }

        var otherOperationId = history.GetAll(other.Id, [otherSite.Id], 100)
            .Single(x => x.Message == OtherSecretMarker).Id;
        return new HistoryFixture(admin.Id, other.Id, ownedSite.Id, otherSite.Id, otherOperationId);
    }

    private async Task EnsureOperationsViewerAsync()
    {
        await using var db = CreateDbContext();
        var roleStore = new ApplicationRoleStore(db);
        var roles = (await roleStore.GetAsync())
            .Where(role => !string.Equals(role.Name, ViewRole, StringComparison.OrdinalIgnoreCase))
            .ToList();
        roles.Add(new CustomApplicationRole(
            ViewRole,
            "UX Operations Viewer",
            "عارض عمليات UX",
            true,
            [ApplicationPermissionCatalog.OperationsView]));
        await roleStore.SaveAsync(roles);
        var viewer = await EnsureUserAsync(db, ViewUser, ViewPassword, ViewRole);
        _ = await EnsureSiteAsync(db, viewer.Id, ViewSiteName, "https://operations-view.example.test");
    }

    private static async Task<AuthUser> EnsureUserAsync(AppDbContext db, string userName, string password, string role)
    {
        var normalized = userName.ToUpperInvariant();
        var existing = await db.AuthUsers.SingleOrDefaultAsync(x => x.NormalizedUserName == normalized);
        if (existing is not null) return existing;

        var now = DateTime.UtcNow;
        var user = new AuthUser(userName, "temporary", now, role);
        user.SetPasswordHash(new PasswordHasher<AuthUser>().HashPassword(user, password), now);
        db.AuthUsers.Add(user);
        await db.SaveChangesAsync();
        return user;
    }

    private static async Task<Site> EnsureSiteAsync(AppDbContext db, Guid ownerId, string name, string url)
    {
        var existing = await db.Sites.SingleOrDefaultAsync(x => x.OwnerUserId == ownerId && x.Name == name);
        if (existing is not null) return existing;
        var site = new Site(name, new Uri(url), DateTime.UtcNow, ownerId);
        db.Sites.Add(site);
        await db.SaveChangesAsync();
        return site;
    }

    private async Task<IBrowserContext> LoginAsync(string userName, string password)
    {
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900), authenticated: false);
        try
        {
            var page = await context.NewPageAsync();
            await page.GotoAsync(host.BaseUrl + "/login?returnUrl=%2Foperations", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.Locator("input[name='userName']").FillAsync(userName);
            await page.Locator("input[name='password']").FillAsync(password);
            await page.Locator("button[type='submit']").ClickAsync(new() { Timeout = 10000 });
            await WaitUntilAsync(() => Task.FromResult(!page.Url.Contains("/login", StringComparison.OrdinalIgnoreCase)),
                $"Could not authenticate fixture user {userName}.");
            return context;
        }
        catch
        {
            await context.CloseAsync();
            throw;
        }
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

    private static ILocator MaintenanceSelect(IPage page, string labelText) =>
        page.Locator("label").Filter(new LocatorFilterOptions { HasText = labelText }).Locator("select");

    private static ILocator MaintenanceInput(IPage page, string labelText) =>
        page.Locator("label").Filter(new LocatorFilterOptions { HasText = labelText }).Locator("input");

    private static async Task<string> StatValueAsync(IPage page, string label)
    {
        var stat = page.Locator("article.panel").Filter(new LocatorFilterOptions { HasText = label });
        await stat.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
        return (await stat.Locator("strong").InnerTextAsync()).Trim();
    }

    private static async Task WaitUntilEnabledAsync(ILocator control)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        while (DateTime.UtcNow < deadline)
        {
            if (await control.IsVisibleAsync() && await control.IsEnabledAsync()) return;
            await Task.Delay(100);
        }
        throw new TimeoutException("Expected control did not become enabled.");
    }

    private static async Task ClickUntilAsync(
        ILocator control,
        Func<Task<bool>> condition,
        string failure)
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

    private static async Task FillUntilAsync(
        ILocator control,
        string value,
        Func<Task<bool>> condition,
        string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(12);
        Exception? last = null;
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await control.FillAsync(value, new() { Timeout = 1500 });
                if (await condition()) return;
            }
            catch (Exception ex) when (ex is PlaywrightException or InvalidOperationException)
            {
                last = ex;
            }
            await Task.Delay(100);
        }
        throw new TimeoutException(failure, last);
    }

    private static async Task SelectUntilAsync(
        ILocator control,
        string value,
        Func<Task<bool>> condition,
        string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(12);
        Exception? last = null;
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await control.SelectOptionAsync(value, new() { Timeout = 1500 });
                if (await condition()) return;
            }
            catch (Exception ex) when (ex is PlaywrightException or InvalidOperationException)
            {
                last = ex;
            }
            await Task.Delay(100);
        }
        throw new TimeoutException(failure, last);
    }

    private static async Task WaitUntilAsync(Func<Task<bool>> condition, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(12);
        Exception? last = null;
        while (DateTime.UtcNow < deadline)
        {
            try { if (await condition()) return; }
            catch (Exception ex) when (ex is PlaywrightException or InvalidOperationException) { last = ex; }
            await Task.Delay(100);
        }
        throw new TimeoutException(failure, last);
    }

    private sealed record HistoryFixture(
        Guid AdminUserId,
        Guid OtherUserId,
        Guid OwnedSiteId,
        Guid OtherSiteId,
        Guid OtherOperationId);
}