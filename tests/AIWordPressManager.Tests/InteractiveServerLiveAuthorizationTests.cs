using System.Security.Claims;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Components.Authorization;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class InteractiveServerLiveAuthorizationTests
{
    [Fact]
    public async Task Revoked_session_stops_same_cached_circuit_without_reconnect()
    {
        await using var fixture = await AuthorizationFixture.CreateAsync("Administrator");
        var context = fixture.CreateCurrentUserContext();

        context.RequirePermission(ApplicationPermissionCatalog.SettingsManage).Should().Be(fixture.User.Id);
        context.IsAuthenticated.Should().BeTrue();

        await fixture.Sessions.RevokeAsync(fixture.Session.SessionId, "Security test revocation.");

        context.HasPermission(ApplicationPermissionCatalog.SettingsManage).Should().BeFalse();
        context.IsAuthenticated.Should().BeFalse();
        context.TryGetUserId(out _).Should().BeFalse();
        var action = () => context.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        action.Should().Throw<UnauthorizedAccessException>();
    }

    [Fact]
    public async Task Role_demotion_stops_same_cached_administrator_circuit_without_reconnect()
    {
        await using var fixture = await AuthorizationFixture.CreateAsync("Administrator");
        var context = fixture.CreateCurrentUserContext();

        context.RequireAdministrator().Should().Be(fixture.User.Id);
        context.HasPermission(ApplicationPermissionCatalog.SettingsManage).Should().BeTrue();

        fixture.User.SetRole("User", DateTime.UtcNow);
        await fixture.Db.SaveChangesAsync();

        context.IsInRole("Administrator").Should().BeFalse();
        context.HasPermission(ApplicationPermissionCatalog.SettingsManage).Should().BeFalse();
        var adminAction = () => context.RequireAdministrator();
        adminAction.Should().Throw<UnauthorizedAccessException>();
        var permissionAction = () => context.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        permissionAction.Should().Throw<UnauthorizedAccessException>();
    }

    [Fact]
    public async Task Disabled_account_stops_same_cached_circuit_without_reconnect()
    {
        await using var fixture = await AuthorizationFixture.CreateAsync("User");
        var context = fixture.CreateCurrentUserContext();

        context.RequirePermission(ApplicationPermissionCatalog.ContentEdit).Should().Be(fixture.User.Id);

        fixture.User.SetActive(false, DateTime.UtcNow);
        await fixture.Db.SaveChangesAsync();

        context.HasPermission(ApplicationPermissionCatalog.ContentEdit).Should().BeFalse();
        context.TryGetUserId(out _).Should().BeFalse();
        var action = () => context.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
        action.Should().Throw<UnauthorizedAccessException>();
    }

    private sealed class AuthorizationFixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;

        private AuthorizationFixture(
            SqliteConnection connection,
            AppDbContext db,
            AuthUser user,
            ApplicationSessionRecord session,
            ClaimsPrincipal principal)
        {
            _connection = connection;
            Db = db;
            User = user;
            Session = session;
            Principal = principal;
            Sessions = new ApplicationSessionStore(db);
        }

        public AppDbContext Db { get; }
        public AuthUser User { get; }
        public ApplicationSessionRecord Session { get; }
        public ClaimsPrincipal Principal { get; }
        public ApplicationSessionStore Sessions { get; }

        public static async Task<AuthorizationFixture> CreateAsync(string role)
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>()
                .UseSqlite(connection)
                .Options;
            var db = new AppDbContext(options);
            await db.Database.EnsureCreatedAsync();

            var user = new AuthUser($"circuit-{Guid.NewGuid():N}", "test-password-hash", DateTime.UtcNow, role);
            db.AuthUsers.Add(user);
            await db.SaveChangesAsync();

            var sessions = new ApplicationSessionStore(db);
            var session = await sessions.CreateAsync(
                user.Id,
                user.UserName,
                role,
                "127.0.0.1",
                "InteractiveServerLiveAuthorizationTests",
                persistent: false);
            var principal = CreatePrincipal(user, session, role);
            return new AuthorizationFixture(connection, db, user, session, principal);
        }

        public CurrentUserContext CreateCurrentUserContext()
        {
            var accessor = new HttpContextAccessor { HttpContext = null };
            var provider = new FixedAuthenticationStateProvider(Principal);
            var context = new CurrentUserContext(accessor, provider, Db);
            context.SetCircuitPrincipal(Principal);
            return context;
        }

        public async ValueTask DisposeAsync()
        {
            await Db.DisposeAsync();
            await _connection.DisposeAsync();
        }

        private static ClaimsPrincipal CreatePrincipal(AuthUser user, ApplicationSessionRecord session, string role)
        {
            var claims = new List<Claim>
            {
                new(ClaimTypes.NameIdentifier, user.Id.ToString()),
                new(ClaimTypes.Name, user.UserName),
                new(ClaimTypes.Role, role),
                new(ApplicationSessionStore.SessionIdClaimType, session.SessionId.ToString())
            };
            claims.AddRange(ApplicationPermissionCatalog.ForRole(role)
                .Select(permission => new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
            return new ClaimsPrincipal(new ClaimsIdentity(claims, "test", ClaimTypes.Name, ClaimTypes.Role));
        }
    }

    private sealed class FixedAuthenticationStateProvider(ClaimsPrincipal principal) : AuthenticationStateProvider
    {
        public override Task<AuthenticationState> GetAuthenticationStateAsync() =>
            Task.FromResult(new AuthenticationState(principal));
    }
}
