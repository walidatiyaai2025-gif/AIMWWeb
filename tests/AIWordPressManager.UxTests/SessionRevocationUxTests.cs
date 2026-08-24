using System.Reflection;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class SessionRevocationUxTests(UxTestHost host)
{
    private const string SelfSessionUserAgent = "AIMWWeb UX secondary browser";
    private const string BulkUserName = "session.bulk.ux";
    private const string BulkUserAgentOne = "AIMWWeb UX bulk browser one";
    private const string BulkUserAgentTwo = "AIMWWeb UX bulk browser two";

    [Fact]
    public async Task My_sessions_revokes_another_session_persists_audit_and_reconciles_UI()
    {
        var sessionId = await SeedOwnAdditionalSessionAsync();
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            var errors = new List<string>();
            page.PageError += (_, message) => errors.Add(message);

            var response = await page.GotoAsync(host.BaseUrl + "/settings/sessions",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            var sessionCard = page.GetByText(SelfSessionUserAgent, new PageGetByTextOptions { Exact = true })
                .Locator("xpath=ancestor::article[1]");
            await sessionCard.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var end = sessionCard.GetByRole(AriaRole.Button, new() { Name = "End", Exact = true });
            await ClickUntilPersistedAsync(end, () => IsRevokedAsync(sessionId), "My Sessions did not persist the revocation.");

            await page.GetByText(SelfSessionUserAgent, new PageGetByTextOptions { Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Hidden, Timeout = 10000 });

            await using var db = CreateDbContext();
            var record = await new ApplicationSessionStore(db).TryGetAsync(sessionId);
            record.Should().NotBeNull();
            record!.RevokedAtUtc.Should().NotBeNull();
            record.RevokedReason.Should().Be("Ended by account owner.");

            var audits = await new ApplicationSecurityAuditStore(db).ListAsync(
                new SecurityAuditQuery(Category: "Session", Action: "Session.SelfRevoked", Search: sessionId.ToString("D"), Take: 20));
            audits.Should().ContainSingle(audit =>
                audit.Outcome == "Succeeded" &&
                audit.TargetType == "ApplicationSession" &&
                audit.TargetId == sessionId.ToString("D"));
            errors.Should().BeEmpty("session revocation must not produce browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("session-self-revocation");
        }
    }

    [Fact]
    public async Task Admin_sessions_bulk_revokes_another_account_persists_audit_and_reconciles_UI()
    {
        var fixture = await SeedOtherAccountSessionsAsync();
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            var errors = new List<string>();
            page.PageError += (_, message) => errors.Add(message);

            var response = await page.GotoAsync(host.BaseUrl + "/admin/sessions",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            var accountName = page.GetByText(BulkUserName, new PageGetByTextOptions { Exact = true }).First;
            var accountCard = accountName.Locator("xpath=ancestor::article[1]");
            await accountCard.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            await accountCard.GetByText("2 active session(s)", new LocatorGetByTextOptions { Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });

            var endAll = accountCard.GetByRole(AriaRole.Button, new() { Name = "End all sessions", Exact = true });
            await ClickUntilPersistedAsync(endAll,
                () => AreAllRevokedAsync(fixture.SessionIds),
                "Session Management did not persist the account-wide revocation.");

            await accountName.WaitForAsync(new() { State = WaitForSelectorState.Hidden, Timeout = 10000 });

            await using var db = CreateDbContext();
            var store = new ApplicationSessionStore(db);
            foreach (var sessionId in fixture.SessionIds)
            {
                var record = await store.TryGetAsync(sessionId);
                record.Should().NotBeNull();
                record!.RevokedAtUtc.Should().NotBeNull();
                record.RevokedReason.Should().Be("All account sessions ended by administrator.");
            }

            var audits = await new ApplicationSecurityAuditStore(db).ListAsync(
                new SecurityAuditQuery(Category: "Session", Action: "Session.UserBulkRevoked", Search: BulkUserName, Take: 20));
            audits.Should().ContainSingle(audit =>
                audit.Outcome == "Succeeded" &&
                audit.TargetType == "ApplicationUser" &&
                audit.TargetId == fixture.UserId.ToString("D"));
            var bulkAudit = audits.Single();
            bulkAudit.Metadata.Should().ContainKey("sessionCount").WhoseValue.Should().Be("2");
            errors.Should().BeEmpty("bulk session revocation must not produce browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("session-bulk-revocation");
        }
    }

    private async Task<Guid> SeedOwnAdditionalSessionAsync()
    {
        await using var db = CreateDbContext();
        var admin = await db.AuthUsers.AsNoTracking().SingleAsync(user => user.NormalizedUserName == "ADMIN");
        var session = await new ApplicationSessionStore(db).CreateAsync(
            admin.Id,
            "Admin",
            "Administrator",
            "127.0.0.22",
            SelfSessionUserAgent,
            persistent: true);
        return session.SessionId;
    }

    private async Task<(Guid UserId, Guid[] SessionIds)> SeedOtherAccountSessionsAsync()
    {
        var userId = Guid.NewGuid();
        await using var db = CreateDbContext();
        var store = new ApplicationSessionStore(db);
        var first = await store.CreateAsync(userId, BulkUserName, "Editor", "127.0.0.31", BulkUserAgentOne, persistent: false);
        var second = await store.CreateAsync(userId, BulkUserName, "Editor", "127.0.0.32", BulkUserAgentTwo, persistent: true);
        return (userId, [first.SessionId, second.SessionId]);
    }

    private async Task<bool> IsRevokedAsync(Guid sessionId)
    {
        await using var db = CreateDbContext();
        var record = await new ApplicationSessionStore(db).TryGetAsync(sessionId);
        return record?.RevokedAtUtc is not null;
    }

    private async Task<bool> AreAllRevokedAsync(IReadOnlyCollection<Guid> sessionIds)
    {
        await using var db = CreateDbContext();
        var store = new ApplicationSessionStore(db);
        foreach (var sessionId in sessionIds)
        {
            var record = await store.TryGetAsync(sessionId);
            if (record?.RevokedAtUtc is null) return false;
        }
        return true;
    }

    private static async Task ClickUntilPersistedAsync(
        ILocator button,
        Func<Task<bool>> persisted,
        string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(12);
        while (DateTime.UtcNow < deadline)
        {
            if (await persisted()) return;
            try
            {
                await button.ClickAsync(new() { Timeout = 1500 });
            }
            catch (PlaywrightException)
            {
                // SSR can render the control before the interactive circuit is attached.
                // Retry the real control until its persisted side effect proves interactivity.
            }
            await Task.Delay(100);
        }
        throw new TimeoutException(failure);
    }

    private AppDbContext CreateDbContext()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX database context factory.");
        return (AppDbContext)(method.Invoke(host, null)
            ?? throw new InvalidOperationException("UX database context factory returned null."));
    }
}
