using System.Security.Claims;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class ApplicationSessionRequestValidatorTests
{
    [Fact]
    public async Task Valid_tracked_session_with_current_permissions_is_accepted()
    {
        await using var fixture = await Fixture.CreateAsync("User");
        var session = await fixture.Sessions.CreateAsync(fixture.User.Id, fixture.User.UserName, "User", null, null, false);
        var principal = Principal(fixture.User, "User", session.SessionId, ApplicationPermissionCatalog.ForRole("User"));

        var result = await fixture.Validator.ValidateAsync(principal);

        result.IsValid.Should().BeTrue();
        result.SessionId.Should().Be(session.SessionId);
    }

    [Fact]
    public async Task Permission_change_invalidates_stale_custom_role_cookie()
    {
        await using var fixture = await Fixture.CreateAsync("Reviewer");
        var roles = new ApplicationRoleStore(fixture.Context);
        await roles.SaveAsync([
            new CustomApplicationRole(
                "Reviewer",
                "Reviewer",
                "مراجع",
                true,
                [ApplicationPermissionCatalog.ContentView])
        ]);
        var session = await fixture.Sessions.CreateAsync(fixture.User.Id, fixture.User.UserName, "Reviewer", null, null, false);
        var principal = Principal(
            fixture.User,
            "Reviewer",
            session.SessionId,
            [ApplicationPermissionCatalog.ContentView]);

        (await fixture.Validator.ValidateAsync(principal)).IsValid.Should().BeTrue();

        await roles.SaveAsync([
            new CustomApplicationRole(
                "Reviewer",
                "Reviewer",
                "مراجع",
                true,
                [ApplicationPermissionCatalog.ApprovalsView])
        ]);

        var stale = await fixture.Validator.ValidateAsync(principal);
        stale.IsValid.Should().BeFalse();
        stale.Reason.Should().Contain("permissions changed");
    }

    [Fact]
    public async Task Disabled_account_invalidates_existing_session()
    {
        await using var fixture = await Fixture.CreateAsync("User");
        var session = await fixture.Sessions.CreateAsync(fixture.User.Id, fixture.User.UserName, "User", null, null, false);
        var principal = Principal(fixture.User, "User", session.SessionId, ApplicationPermissionCatalog.ForRole("User"));
        fixture.User.SetActive(false, DateTime.UtcNow);
        await fixture.Context.SaveChangesAsync();

        var result = await fixture.Validator.ValidateAsync(principal);

        result.IsValid.Should().BeFalse();
        result.Reason.Should().Contain("inactive");
    }

    [Fact]
    public async Task Legacy_cookie_without_session_id_fails_closed()
    {
        await using var fixture = await Fixture.CreateAsync("Administrator");
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, fixture.User.Id.ToString()),
            new(ClaimTypes.Name, fixture.User.UserName),
            new(ClaimTypes.Role, "Administrator")
        };
        claims.AddRange(ApplicationPermissionCatalog.ForRole("Administrator")
            .Select(permission => new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
        var principal = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"));

        var result = await fixture.Validator.ValidateAsync(principal);

        result.IsValid.Should().BeFalse();
        result.Reason.Should().Contain("Missing tracked session");
    }

    [Fact]
    public async Task Session_user_mismatch_is_rejected()
    {
        await using var fixture = await Fixture.CreateAsync("User");
        var otherUserId = Guid.NewGuid();
        var session = await fixture.Sessions.CreateAsync(otherUserId, "other", "User", null, null, false);
        var principal = Principal(fixture.User, "User", session.SessionId, ApplicationPermissionCatalog.ForRole("User"));

        var result = await fixture.Validator.ValidateAsync(principal);

        result.IsValid.Should().BeFalse();
        result.Reason.Should().Contain("does not belong");
    }

    private static ClaimsPrincipal Principal(AuthUser user, string role, Guid sessionId, IEnumerable<string> permissions)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, user.Id.ToString()),
            new(ClaimTypes.Name, user.UserName),
            new(ClaimTypes.Role, role),
            new(ApplicationSessionStore.SessionIdClaimType, sessionId.ToString("D"))
        };
        claims.AddRange(permissions.Select(permission => new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
        return new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"));
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        public AppDbContext Context { get; }
        public AuthUser User { get; }
        public ApplicationSessionStore Sessions { get; }
        public ApplicationSessionRequestValidator Validator { get; }

        private Fixture(SqliteConnection connection, AppDbContext context, AuthUser user)
        {
            _connection = connection;
            Context = context;
            User = user;
            Sessions = new ApplicationSessionStore(context);
            Validator = new ApplicationSessionRequestValidator(context);
        }

        public static async Task<Fixture> CreateAsync(string role)
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();
            var user = new AuthUser("session.user", "hash", DateTime.UtcNow, role);
            context.AuthUsers.Add(user);
            await context.SaveChangesAsync();
            return new Fixture(connection, context, user);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await _connection.DisposeAsync();
        }
    }
}