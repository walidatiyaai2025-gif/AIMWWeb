using System.Security.Claims;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class ApplicationRoleAdministrationServiceTests
{
    [Fact]
    public async Task Administrator_can_create_persisted_custom_role_and_resolver_returns_grants()
    {
        await using var fixture = await Fixture.CreateAsync();

        var result = await fixture.Service.CreateAsync(
            "ContentReviewer",
            "Content reviewer",
            "مراجع المحتوى",
            [ApplicationPermissionCatalog.ContentView, ApplicationPermissionCatalog.ApprovalsView]);

        result.IsSuccess.Should().BeTrue();
        var persisted = await new ApplicationRoleRegistryStore(fixture.Context).LoadAsync();
        persisted.Should().ContainSingle(role => role.Name == "ContentReviewer");

        var resolver = new ApplicationRolePermissionResolver(fixture.Context);
        (await resolver.ResolveRoleNameAsync("contentreviewer")).Should().Be("ContentReviewer");
        (await resolver.GetPermissionsAsync("ContentReviewer")).Should().BeEquivalentTo(
            ApplicationPermissionCatalog.ContentView,
            ApplicationPermissionCatalog.ApprovalsView);
    }

    [Fact]
    public async Task Unknown_permission_is_rejected_without_persisting_role()
    {
        await using var fixture = await Fixture.CreateAsync();

        var result = await fixture.Service.CreateAsync(
            "UnsafeRole",
            "Unsafe role",
            "دور غير آمن",
            ["Users.SuperAdmin"]);

        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("unknown permission");
        (await new ApplicationRoleRegistryStore(fixture.Context).LoadAsync()).Should().BeEmpty();
    }

    [Fact]
    public async Task Corrupt_registry_blocks_administration_instead_of_overwriting_it()
    {
        await using var fixture = await Fixture.CreateAsync();
        fixture.Context.ApplicationSettings.Add(new ApplicationSetting(
            ApplicationRoleRegistryStore.RegistryKey,
            "{broken-json",
            DateTime.UtcNow));
        await fixture.Context.SaveChangesAsync();

        var result = await fixture.Service.CreateAsync(
            "ContentReviewer",
            "Content reviewer",
            "مراجع المحتوى",
            [ApplicationPermissionCatalog.ContentView]);

        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("registry is invalid");
        (await fixture.Context.ApplicationSettings.AsNoTracking().SingleAsync(x => x.Key == ApplicationRoleRegistryStore.RegistryKey)).Value
            .Should().Be("{broken-json");
    }

    [Fact]
    public async Task Assigned_custom_role_cannot_be_deleted()
    {
        await using var fixture = await Fixture.CreateAsync();
        var role = await fixture.CreateRoleAsync("ContentReviewer", ApplicationPermissionCatalog.ContentView);
        await fixture.AddUserAsync("reviewer.one", role.Name);

        var result = await fixture.Service.DeleteAsync(role.Id);

        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("assigned");
        (await new ApplicationRoleRegistryStore(fixture.Context).LoadAsync()).Should().ContainSingle(x => x.Id == role.Id);
    }

    [Fact]
    public async Task Actor_cannot_remove_UsersManage_from_own_custom_role()
    {
        await using var fixture = await Fixture.CreateAsync("TeamAdmin", ApplicationPermissionCatalog.UsersManage);
        var role = await fixture.CreateRoleAsync("TeamAdmin", ApplicationPermissionCatalog.UsersView, ApplicationPermissionCatalog.UsersManage);
        var actor = await fixture.Context.AuthUsers.SingleAsync(x => x.Id == fixture.Actor.Id);
        actor.SetRole(role.Name, DateTime.UtcNow);
        await fixture.Context.SaveChangesAsync();

        var result = await fixture.Service.UpdateAsync(
            role.Id,
            role.DisplayNameEn,
            role.DisplayNameAr,
            [ApplicationPermissionCatalog.UsersView]);

        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("own active role");
        (await new ApplicationRolePermissionResolver(fixture.Context).GetPermissionsAsync(role.Name))
            .Should().Contain(ApplicationPermissionCatalog.UsersManage);
    }

    [Fact]
    public async Task UsersView_claim_can_list_roles_but_cannot_create_them()
    {
        await using var fixture = await Fixture.CreateAsync("User", ApplicationPermissionCatalog.UsersView);

        var roles = await fixture.Service.ListAsync();
        roles.Should().Contain(role => role.Name == "Administrator" && role.IsBuiltIn);
        roles.Should().Contain(role => role.Name == "User" && role.IsBuiltIn);

        var create = () => fixture.Service.CreateAsync(
            "ContentReviewer",
            "Content reviewer",
            "مراجع المحتوى",
            [ApplicationPermissionCatalog.ContentView]);
        await create.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        public AppDbContext Context { get; }
        public AuthUser Actor { get; }
        public ApplicationRoleAdministrationService Service { get; }

        private Fixture(SqliteConnection connection, AppDbContext context, AuthUser actor, ApplicationRoleAdministrationService service)
        {
            _connection = connection;
            Context = context;
            Actor = actor;
            Service = service;
        }

        public static async Task<Fixture> CreateAsync(string actorRole = "Administrator", params string[] permissions)
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();
            var actor = new AuthUser("role.admin", "hash", DateTime.UtcNow, actorRole);
            context.AuthUsers.Add(actor);
            await context.SaveChangesAsync();

            var claims = new List<Claim>
            {
                new(ClaimTypes.NameIdentifier, actor.Id.ToString()),
                new(ClaimTypes.Name, actor.UserName),
                new(ClaimTypes.Role, actorRole)
            };
            claims.AddRange(permissions.Select(permission => new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
            var httpContext = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"))
            };
            var currentUser = new CurrentUserContext(new IsolatedHttpContextAccessor(httpContext));
            return new Fixture(connection, context, actor, new ApplicationRoleAdministrationService(context, currentUser));
        }

        public async Task<PersistedApplicationRole> CreateRoleAsync(string name, params string[] permissions)
        {
            var role = new PersistedApplicationRole(
                Guid.NewGuid(),
                name,
                $"{name} display",
                "دور مخصص",
                permissions);
            var store = new ApplicationRoleRegistryStore(Context);
            var roles = await store.LoadAsync();
            await store.SaveAsync(roles.Append(role).ToArray());
            return role;
        }

        public async Task<AuthUser> AddUserAsync(string userName, string role)
        {
            var user = new AuthUser(userName, "hash", DateTime.UtcNow, role);
            Context.AuthUsers.Add(user);
            await Context.SaveChangesAsync();
            return user;
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await _connection.DisposeAsync();
        }
    }

    private sealed class IsolatedHttpContextAccessor(HttpContext context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }
}