using System.Security.Claims;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class ApplicationCustomRoleTests
{
    [Fact]
    public async Task Custom_role_round_trips_through_existing_application_settings_store()
    {
        await using var fixture = await Fixture.CreateAsync();
        var role = new CustomApplicationRole(
            "Publisher",
            "Publisher",
            "ناشر",
            true,
            [ApplicationPermissionCatalog.ContentView, ApplicationPermissionCatalog.ContentEdit]);

        await fixture.Store.SaveAsync([role]);
        fixture.Context.ChangeTracker.Clear();

        var stored = await fixture.Store.GetAsync();
        stored.Should().ContainSingle();
        stored[0].Name.Should().Be("Publisher");
        stored[0].DisplayNameArabic.Should().Be("ناشر");
        stored[0].Permissions.Should().BeEquivalentTo(ApplicationPermissionCatalog.ContentView, ApplicationPermissionCatalog.ContentEdit);
        (await fixture.Context.ApplicationSettings.AsNoTracking().SingleAsync(x => x.Key == ApplicationRoleStore.SettingsKey)).Value.Should().Contain("Publisher");
    }

    [Fact]
    public async Task Custom_role_resolver_returns_only_active_persisted_grants()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Store.SaveAsync([
            new CustomApplicationRole("Publisher", "Publisher", "ناشر", true, [ApplicationPermissionCatalog.ContentView, ApplicationPermissionCatalog.ContentEdit]),
            new CustomApplicationRole("Dormant", "Dormant", "معطل", false, [ApplicationPermissionCatalog.OperationsExecute])
        ]);

        (await fixture.Store.ResolvePermissionsAsync("publisher"))
            .Should().BeEquivalentTo(ApplicationPermissionCatalog.ContentView, ApplicationPermissionCatalog.ContentEdit);
        (await fixture.Store.ResolvePermissionsAsync("Dormant")).Should().BeEmpty();
        (await fixture.Store.ResolveAssignableRoleNameAsync("Publisher")).Should().Be("Publisher");
        (await fixture.Store.ResolveAssignableRoleNameAsync("Dormant")).Should().BeNull();
    }

    [Theory]
    [InlineData(ApplicationPermissionCatalog.UsersManage)]
    [InlineData(ApplicationPermissionCatalog.SettingsManage)]
    public async Task Administrator_only_permissions_cannot_be_persisted_on_custom_roles(string reservedPermission)
    {
        await using var fixture = await Fixture.CreateAsync();

        var result = await fixture.RoleAdministration.SaveAsync("SecurityTeam", "Security Team", "فريق الأمان", [reservedPermission]);

        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("reserved");
        (await fixture.Store.GetAsync()).Should().BeEmpty();
    }

    [Fact]
    public async Task Active_custom_role_cannot_be_disabled_while_assigned_to_active_user()
    {
        await using var fixture = await Fixture.CreateAsync();
        (await fixture.RoleAdministration.SaveAsync(
            "Publisher",
            "Publisher",
            "ناشر",
            [ApplicationPermissionCatalog.ContentView, ApplicationPermissionCatalog.ContentEdit])).IsSuccess.Should().BeTrue();

        var assigned = new AuthUser("publisher.one", "hash", DateTime.UtcNow, "Publisher");
        fixture.Context.AuthUsers.Add(assigned);
        await fixture.Context.SaveChangesAsync();

        var result = await fixture.RoleAdministration.SetActiveAsync("Publisher", false);

        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("Reassign active users");
        (await fixture.Store.GetAsync()).Single(x => x.Name == "Publisher").IsActive.Should().BeTrue();
    }

    [Fact]
    public async Task Application_user_administration_can_assign_an_active_custom_role()
    {
        await using var fixture = await Fixture.CreateAsync();
        (await fixture.RoleAdministration.SaveAsync(
            "Publisher",
            "Publisher",
            "ناشر",
            [ApplicationPermissionCatalog.ContentView, ApplicationPermissionCatalog.ContentEdit])).IsSuccess.Should().BeTrue();

        var result = await fixture.UserAdministration.CreateAsync(
            "publisher.one",
            "StrongPass1",
            "StrongPass1",
            "publisher");

        result.IsSuccess.Should().BeTrue();
        var stored = await fixture.Context.AuthUsers.AsNoTracking().SingleAsync(x => x.UserName == "publisher.one");
        stored.Role.Should().Be("Publisher");
    }

    [Fact]
    public async Task Inactive_custom_role_cannot_receive_new_user_assignments()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Store.SaveAsync([
            new CustomApplicationRole("Dormant", "Dormant", "معطل", false, [ApplicationPermissionCatalog.ContentView])
        ]);

        var result = await fixture.UserAdministration.CreateAsync(
            "dormant.user",
            "StrongPass1",
            "StrongPass1",
            "Dormant");

        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("unavailable or inactive");
    }

    [Fact]
    public void Custom_role_claims_do_not_gain_reserved_permissions_through_role_name_fallback()
    {
        var principal = new ClaimsPrincipal(new ClaimsIdentity([
            new Claim(ClaimTypes.NameIdentifier, Guid.NewGuid().ToString()),
            new Claim(ClaimTypes.Name, "publisher.one"),
            new Claim(ClaimTypes.Role, "Publisher"),
            new Claim(ApplicationPermissionCatalog.ClaimType, ApplicationPermissionCatalog.ContentEdit)
        ], "Test"));

        ApplicationPermissionCatalog.PrincipalHasPermission(principal, ApplicationPermissionCatalog.ContentEdit).Should().BeTrue();
        ApplicationPermissionCatalog.PrincipalHasPermission(principal, ApplicationPermissionCatalog.UsersManage).Should().BeFalse();
        ApplicationPermissionCatalog.PrincipalHasPermission(principal, ApplicationPermissionCatalog.SettingsManage).Should().BeFalse();
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        public AppDbContext Context { get; }
        public ApplicationRoleStore Store { get; }
        public ApplicationRoleAdministrationService RoleAdministration { get; }
        public ApplicationUserAdministrationService UserAdministration { get; }

        private Fixture(
            SqliteConnection connection,
            AppDbContext context,
            ApplicationRoleStore store,
            ApplicationRoleAdministrationService roleAdministration,
            ApplicationUserAdministrationService userAdministration)
        {
            _connection = connection;
            Context = context;
            Store = store;
            RoleAdministration = roleAdministration;
            UserAdministration = userAdministration;
        }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();

            var actor = new AuthUser("security.admin", "hash", DateTime.UtcNow, "Administrator");
            context.AuthUsers.Add(actor);
            await context.SaveChangesAsync();

            var identity = new ClaimsIdentity([
                new Claim(ClaimTypes.NameIdentifier, actor.Id.ToString()),
                new Claim(ClaimTypes.Name, actor.UserName),
                new Claim(ClaimTypes.Role, "Administrator")
            ], "Test");
            var httpContext = new DefaultHttpContext { User = new ClaimsPrincipal(identity) };
            var accessor = new TestHttpContextAccessor(httpContext);
            var currentUser = new CurrentUserContext(accessor);
            var store = new ApplicationRoleStore(context);
            var roleAdministration = new ApplicationRoleAdministrationService(context, currentUser, store);
            var userAdministration = new ApplicationUserAdministrationService(context, currentUser, store);
            return new Fixture(connection, context, store, roleAdministration, userAdministration);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await _connection.DisposeAsync();
        }
    }

    private sealed class TestHttpContextAccessor(HttpContext context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }
}
