using System.Reflection;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class RolesPermissionsUxTests(UxTestHost host)
{
    private const string RoleName = "ux.content.operator";
    private const string RoleDisplayName = "UX Content Operator";
    private const string UserName = "role.assignment.ux";
    private const string Password = "BrowserRole123!";

    [Fact]
    public async Task Custom_role_create_and_user_assignment_persist_audit_revoke_sessions_and_reconcile_UI()
    {
        await ResetFixtureAsync();
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            var errors = new List<string>();
            page.PageError += (_, message) => errors.Add(message);

            await CreateManagedUserAsync(page);
            var user = await LoadUserAsync();
            user.Should().NotBeNull();
            user!.Role.Should().Be("User");
            var sessionId = await SeedSessionAsync(user.Id);

            var response = await page.GotoAsync(host.BaseUrl + "/admin/roles-permissions",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            await CreateRoleUntilPersistedAsync(page);
            var role = await LoadRoleAsync();
            role.Should().NotBeNull();
            role!.IsActive.Should().BeTrue();
            role.Permissions.Should().BeEquivalentTo(new[]
            {
                ApplicationPermissionCatalog.ContentView,
                ApplicationPermissionCatalog.ContentEdit
            });
            role.Permissions.Should().NotContain(ApplicationPermissionCatalog.UsersManage);
            role.Permissions.Should().NotContain(ApplicationPermissionCatalog.SettingsManage);
            await AssertAuditAsync("Authorization", "Role.Created", "ApplicationRole", RoleName);

            var roleCard = page.GetByText(RoleName, new PageGetByTextOptions { Exact = true }).First;
            await roleCard.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });

            var assignment = page.GetByRole(AriaRole.Combobox, new() { Name = $"Role for {UserName}", Exact = true });
            await assignment.SelectOptionAsync(RoleName);
            var assignmentRow = assignment.Locator("xpath=ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' role-assignment-row ')][1]");
            await SaveAssignmentUntilPersistedAsync(page, assignmentRow);

            var assigned = await LoadUserAsync();
            assigned.Should().NotBeNull();
            assigned!.Role.Should().Be(RoleName);
            await using (var db = CreateDbContext())
            {
                var session = await new ApplicationSessionStore(db).TryGetAsync(sessionId);
                session.Should().NotBeNull();
                session!.RevokedAtUtc.Should().NotBeNull();
                session.RevokedReason.Should().Be("Account role changed.");
            }
            await AssertAuditAsync("Account", "User.Updated", "ApplicationUser", assigned.Id.ToString("D"));
            await assignmentRow.GetByText($"Current role: {RoleDisplayName}", new LocatorGetByTextOptions { Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });

            errors.Should().BeEmpty("real role mutations and assignments must not produce browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("roles-permissions");
        }
    }

    private async Task CreateManagedUserAsync(IPage page)
    {
        var response = await page.GotoAsync(host.BaseUrl + "/admin/application-users",
            new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);
        var deadline = DateTime.UtcNow.AddSeconds(15);
        while (DateTime.UtcNow < deadline)
        {
            if (await LoadUserAsync() is not null) return;
            try
            {
                await page.Locator("#admin-user-name").FillAsync(UserName, new() { Timeout = 1500 });
                await page.Locator("#admin-user-password").FillAsync(Password, new() { Timeout = 1500 });
                await page.Locator("#admin-user-confirm-password").FillAsync(Password, new() { Timeout = 1500 });
                await page.GetByRole(AriaRole.Button, new() { Name = "Create", Exact = true }).ClickAsync(new() { Timeout = 1500 });
            }
            catch (PlaywrightException) { }
            await page.WaitForTimeoutAsync(100);
        }
        throw new TimeoutException("Application Users did not persist the role-assignment fixture user.");
    }

    private async Task CreateRoleUntilPersistedAsync(IPage page)
    {
        var deadline = DateTime.UtcNow.AddSeconds(15);
        while (DateTime.UtcNow < deadline)
        {
            if (await LoadRoleAsync() is not null) return;
            try
            {
                await page.Locator("#custom-role-name").FillAsync(RoleName, new() { Timeout = 1500 });
                await page.Locator("#custom-role-name-en").FillAsync(RoleDisplayName, new() { Timeout = 1500 });
                await page.Locator("#custom-role-name-ar").FillAsync("مشغل محتوى UX", new() { Timeout = 1500 });
                await SetPermissionAsync(page, ApplicationPermissionCatalog.ContentView);
                await SetPermissionAsync(page, ApplicationPermissionCatalog.ContentEdit);
                await page.GetByRole(AriaRole.Button, new() { Name = "Save role", Exact = true }).ClickAsync(new() { Timeout = 1500 });
            }
            catch (PlaywrightException) { }
            await page.WaitForTimeoutAsync(100);
        }
        throw new TimeoutException("Roles & Permissions did not persist the custom role.");
    }

    private static async Task SetPermissionAsync(IPage page, string permission)
    {
        var label = page.Locator("label.permission-option").Filter(new LocatorFilterOptions { HasText = permission });
        var checkbox = label.Locator("input[type='checkbox']");
        if (!await checkbox.IsCheckedAsync()) await checkbox.CheckAsync(new() { Timeout = 1500 });
    }

    private async Task SaveAssignmentUntilPersistedAsync(IPage page, ILocator assignmentRow)
    {
        var deadline = DateTime.UtcNow.AddSeconds(15);
        while (DateTime.UtcNow < deadline)
        {
            if ((await LoadUserAsync())?.Role == RoleName) return;
            try
            {
                await assignmentRow.GetByRole(AriaRole.Button, new() { Name = "Save assignment", Exact = true })
                    .ClickAsync(new() { Timeout = 1500 });
            }
            catch (PlaywrightException) { }
            await page.WaitForTimeoutAsync(100);
        }
        throw new TimeoutException("Roles & Permissions did not persist the user role assignment.");
    }

    private async Task<CustomApplicationRole?> LoadRoleAsync()
    {
        await using var db = CreateDbContext();
        return (await new ApplicationRoleStore(db).GetAsync())
            .SingleOrDefault(role => string.Equals(role.Name, RoleName, StringComparison.Ordinal));
    }

    private async Task<AIWordPressManager.Domain.Entities.AuthUser?> LoadUserAsync()
    {
        await using var db = CreateDbContext();
        return await db.AuthUsers.AsNoTracking()
            .SingleOrDefaultAsync(user => user.NormalizedUserName == UserName.ToUpperInvariant());
    }

    private async Task<Guid> SeedSessionAsync(Guid userId)
    {
        await using var db = CreateDbContext();
        var session = await new ApplicationSessionStore(db).CreateAsync(
            userId, UserName, "User", "127.0.0.52", "AIMWWeb UX role assignment", persistent: true);
        return session.SessionId;
    }

    private async Task AssertAuditAsync(string category, string action, string targetType, string targetId)
    {
        await using var db = CreateDbContext();
        var audits = await new ApplicationSecurityAuditStore(db).ListAsync(
            new SecurityAuditQuery(Category: category, Action: action, Take: 50));
        audits.Should().Contain(audit =>
            audit.Outcome == "Succeeded" && audit.TargetType == targetType && audit.TargetId == targetId);
    }

    private async Task ResetFixtureAsync()
    {
        await using var db = CreateDbContext();
        var store = new ApplicationRoleStore(db);
        var roles = (await store.GetAsync()).Where(role => !string.Equals(role.Name, RoleName, StringComparison.Ordinal)).ToArray();
        await store.SaveAsync(roles);
        var existing = await db.AuthUsers.SingleOrDefaultAsync(user => user.NormalizedUserName == UserName.ToUpperInvariant());
        if (existing is not null)
        {
            db.AuthUsers.Remove(existing);
            await db.SaveChangesAsync();
        }
    }

    private AppDbContext CreateDbContext()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX database context factory.");
        return (AppDbContext)(method.Invoke(host, null)
            ?? throw new InvalidOperationException("UX database context factory returned null."));
    }
}
