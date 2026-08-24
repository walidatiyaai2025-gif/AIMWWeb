using System.Reflection;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class ApplicationUserAdministrationUxTests(UxTestHost host)
{
    private const string UserName = "user.admin.ux";
    private const string Password = "BrowserUser123!";
    private const string SessionUserAgent = "AIMWWeb UX managed-user browser";

    [Fact]
    public async Task Application_user_create_and_disable_persist_audit_revoke_sessions_and_reconcile_UI()
    {
        await DeleteExistingFixtureUserAsync();
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            var errors = new List<string>();
            page.PageError += (_, message) => errors.Add(message);

            var response = await page.GotoAsync(host.BaseUrl + "/admin/application-users",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            await CreateUntilPersistedAsync(page);
            var created = await LoadUserAsync();
            created.Should().NotBeNull();
            created!.UserName.Should().Be(UserName);
            created.Role.Should().Be("User");
            created.IsActive.Should().BeTrue();
            created.PasswordHash.Should().NotBeNullOrWhiteSpace();
            created.PasswordHash.Should().NotBe(Password, "production must persist a password hash rather than the supplied plaintext password");

            await AssertAuditAsync("User.Created", created.Id);
            var sessionId = await SeedManagedUserSessionAsync(created.Id);

            var userCell = page.GetByText(UserName, new PageGetByTextOptions { Exact = true }).First;
            await userCell.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var row = userCell.Locator("xpath=ancestor::tr[1]");
            var disable = row.GetByRole(AriaRole.Button, new() { Name = "Disable", Exact = true });
            await DisableUntilPersistedAsync(page, disable);

            var disabled = await LoadUserAsync();
            disabled.Should().NotBeNull();
            disabled!.IsActive.Should().BeFalse();

            await using (var db = CreateDbContext())
            {
                var session = await new ApplicationSessionStore(db).TryGetAsync(sessionId);
                session.Should().NotBeNull();
                session!.RevokedAtUtc.Should().NotBeNull();
                session.RevokedReason.Should().Be("Account disabled.");
            }

            await AssertAuditAsync("User.Disabled", created.Id);
            await row.GetByText("Disabled", new LocatorGetByTextOptions { Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            errors.Should().BeEmpty("real application-user mutations must not produce browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("application-user-administration");
        }
    }

    private async Task CreateUntilPersistedAsync(IPage page)
    {
        var deadline = DateTime.UtcNow.AddSeconds(15);
        while (DateTime.UtcNow < deadline)
        {
            if (await LoadUserAsync() is not null) return;
            try
            {
                await page.Locator("#admin-user-name").FillAsync(UserName, new() { Timeout = 1500 });
                await page.Locator("#admin-user-password").FillAsync(Password, new() { Timeout = 1500 });
                await page.Locator("#admin-user-confirm-password").FillAsync(Password, new() { Timeout = 1500 });
                await page.GetByRole(AriaRole.Button, new() { Name = "Create", Exact = true })
                    .ClickAsync(new() { Timeout = 1500 });
            }
            catch (PlaywrightException)
            {
                // InteractiveServer prerender can expose controls before event handlers attach.
                // Persistence is the readiness proof, so retry only the real visible form.
            }
            await page.WaitForTimeoutAsync(100);
        }
        throw new TimeoutException("Application Users did not persist the created account.");
    }

    private async Task DisableUntilPersistedAsync(IPage page, ILocator disable)
    {
        var deadline = DateTime.UtcNow.AddSeconds(15);
        while (DateTime.UtcNow < deadline)
        {
            var user = await LoadUserAsync();
            if (user is { IsActive: false }) return;
            try
            {
                await disable.ClickAsync(new() { Timeout = 1500 });
                var confirm = page.GetByRole(AriaRole.Button, new() { Name = "Disable account", Exact = true });
                await confirm.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 1500 });
                await confirm.ClickAsync(new() { Timeout = 1500 });
            }
            catch (PlaywrightException)
            {
                // Retry the same production controls until the persisted mutation proves completion.
            }
            await page.WaitForTimeoutAsync(100);
        }
        throw new TimeoutException("Application Users did not persist account disablement.");
    }

    private async Task<AIWordPressManager.Domain.Entities.AuthUser?> LoadUserAsync()
    {
        await using var db = CreateDbContext();
        return await db.AuthUsers.AsNoTracking()
            .SingleOrDefaultAsync(user => user.NormalizedUserName == UserName.ToUpperInvariant());
    }

    private async Task<Guid> SeedManagedUserSessionAsync(Guid userId)
    {
        await using var db = CreateDbContext();
        var session = await new ApplicationSessionStore(db).CreateAsync(
            userId,
            UserName,
            "User",
            "127.0.0.41",
            SessionUserAgent,
            persistent: true);
        return session.SessionId;
    }

    private async Task AssertAuditAsync(string action, Guid userId)
    {
        await using var db = CreateDbContext();
        var audits = await new ApplicationSecurityAuditStore(db).ListAsync(
            new SecurityAuditQuery(Category: "Account", Action: action, Search: UserName, Take: 20));
        audits.Should().ContainSingle(audit =>
            audit.Outcome == "Succeeded" &&
            audit.TargetType == "ApplicationUser" &&
            audit.TargetId == userId.ToString("D"));
    }

    private async Task DeleteExistingFixtureUserAsync()
    {
        await using var db = CreateDbContext();
        var existing = await db.AuthUsers.SingleOrDefaultAsync(user => user.NormalizedUserName == UserName.ToUpperInvariant());
        if (existing is null) return;
        db.AuthUsers.Remove(existing);
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
