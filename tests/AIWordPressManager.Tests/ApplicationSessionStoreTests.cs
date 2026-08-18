using System.Security.Claims;
using System.Text.Json;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class ApplicationSessionStoreTests
{
    [Fact]
    public async Task Sign_in_session_round_trips_and_validates_for_active_matching_user()
    {
        await using var fixture = await Fixture.CreateAsync();
        var session = await fixture.Store.CreateAsync(
            fixture.Target.Id,
            fixture.Target.UserName,
            fixture.Target.Role,
            false,
            NewHttpContext(),
            TestContext.Current.CancellationToken);

        session.SessionId.Should().HaveLength(32);
        session.ExpiresAtUtc.Should().BeAfter(session.CreatedAtUtc);
        session.RememberMe.Should().BeFalse();

        var validation = await fixture.Store.ValidateAsync(session.SessionId, fixture.Target.Id, TestContext.Current.CancellationToken);
        validation.IsValid.Should().BeTrue();
        validation.Session!.SessionId.Should().Be(session.SessionId);
    }

    [Fact]
    public async Task Revoked_session_fails_closed()
    {
        await using var fixture = await Fixture.CreateAsync();
        var session = await fixture.CreateTargetSessionAsync();

        (await fixture.Store.RevokeAsync(session.SessionId, "test revoke", TestContext.Current.CancellationToken)).Should().BeTrue();

        var validation = await fixture.Store.ValidateAsync(session.SessionId, fixture.Target.Id, TestContext.Current.CancellationToken);
        validation.IsValid.Should().BeFalse();
        validation.Message.Should().Contain("revoked");
    }

    [Fact]
    public async Task Session_cannot_be_replayed_as_another_user()
    {
        await using var fixture = await Fixture.CreateAsync();
        var session = await fixture.CreateTargetSessionAsync();

        var validation = await fixture.Store.ValidateAsync(session.SessionId, fixture.Actor.Id, TestContext.Current.CancellationToken);

        validation.IsValid.Should().BeFalse();
        validation.Message.Should().Contain("does not match");
    }

    [Fact]
    public async Task Role_change_invalidates_existing_session_even_before_explicit_revocation()
    {
        await using var fixture = await Fixture.CreateAsync();
        var session = await fixture.CreateTargetSessionAsync();
        var target = await fixture.Context.AuthUsers.SingleAsync(x => x.Id == fixture.Target.Id, TestContext.Current.CancellationToken);
        target.SetRole("Administrator", DateTime.UtcNow);
        await fixture.Context.SaveChangesAsync(TestContext.Current.CancellationToken);

        var validation = await fixture.Store.ValidateAsync(session.SessionId, fixture.Target.Id, TestContext.Current.CancellationToken);

        validation.IsValid.Should().BeFalse();
        validation.Message.Should().Contain("stale");
    }

    [Fact]
    public async Task Inactive_account_invalidates_existing_session()
    {
        await using var fixture = await Fixture.CreateAsync();
        var session = await fixture.CreateTargetSessionAsync();
        var target = await fixture.Context.AuthUsers.SingleAsync(x => x.Id == fixture.Target.Id, TestContext.Current.CancellationToken);
        target.SetActive(false, DateTime.UtcNow);
        await fixture.Context.SaveChangesAsync(TestContext.Current.CancellationToken);

        var validation = await fixture.Store.ValidateAsync(session.SessionId, fixture.Target.Id, TestContext.Current.CancellationToken);

        validation.IsValid.Should().BeFalse();
        validation.Message.Should().Contain("unavailable");
    }

    [Fact]
    public async Task Expired_session_fails_closed()
    {
        await using var fixture = await Fixture.CreateAsync();
        var expired = new ApplicationSessionRecord(
            Guid.NewGuid().ToString("N"),
            fixture.Target.Id,
            fixture.Target.UserName,
            fixture.Target.Role,
            DateTime.UtcNow.AddHours(-10),
            DateTime.UtcNow.AddHours(-9),
            DateTime.UtcNow.AddMinutes(-1),
            false,
            "127.0.0.1",
            "test",
            null,
            null);
        fixture.Context.ApplicationSettings.Add(new ApplicationSetting(
            ApplicationSessionStore.SettingsPrefix + expired.SessionId,
            JsonSerializer.Serialize(expired, new JsonSerializerOptions(JsonSerializerDefaults.Web)),
            DateTime.UtcNow));
        await fixture.Context.SaveChangesAsync(TestContext.Current.CancellationToken);

        var validation = await fixture.Store.ValidateAsync(expired.SessionId, fixture.Target.Id, TestContext.Current.CancellationToken);

        validation.IsValid.Should().BeFalse();
        validation.Message.Should().Contain("expired");
    }

    [Fact]
    public async Task Missing_or_malformed_session_records_fail_closed()
    {
        await using var fixture = await Fixture.CreateAsync();
        var id = Guid.NewGuid().ToString("N");
        fixture.Context.ApplicationSettings.Add(new ApplicationSetting(
            ApplicationSessionStore.SettingsPrefix + id,
            "{not-json",
            DateTime.UtcNow));
        await fixture.Context.SaveChangesAsync(TestContext.Current.CancellationToken);

        (await fixture.Store.ValidateAsync(id, fixture.Target.Id, TestContext.Current.CancellationToken)).IsValid.Should().BeFalse();
        (await fixture.Store.ValidateAsync(Guid.NewGuid().ToString("N"), fixture.Target.Id, TestContext.Current.CancellationToken)).IsValid.Should().BeFalse();
        (await fixture.Store.ValidateAsync("not-a-session", fixture.Target.Id, TestContext.Current.CancellationToken)).IsValid.Should().BeFalse();
    }

    [Fact]
    public async Task Revoke_user_sessions_can_preserve_current_session()
    {
        await using var fixture = await Fixture.CreateAsync();
        var current = await fixture.CreateTargetSessionAsync();
        var other = await fixture.CreateTargetSessionAsync();

        var count = await fixture.Store.RevokeUserSessionsAsync(
            fixture.Target.Id,
            "revoke others",
            current.SessionId,
            TestContext.Current.CancellationToken);

        count.Should().Be(1);
        (await fixture.Store.ValidateAsync(current.SessionId, fixture.Target.Id, TestContext.Current.CancellationToken)).IsValid.Should().BeTrue();
        (await fixture.Store.ValidateAsync(other.SessionId, fixture.Target.Id, TestContext.Current.CancellationToken)).IsValid.Should().BeFalse();
    }

    [Fact]
    public async Task Password_reset_revokes_existing_sessions()
    {
        await using var fixture = await Fixture.CreateAsync();
        var session = await fixture.CreateTargetSessionAsync();

        var result = await fixture.UserAdministration.ResetPasswordAsync(
            fixture.Target.Id,
            "NewStrong2",
            "NewStrong2",
            TestContext.Current.CancellationToken);

        result.IsSuccess.Should().BeTrue();
        (await fixture.Store.ValidateAsync(session.SessionId, fixture.Target.Id, TestContext.Current.CancellationToken)).IsValid.Should().BeFalse();
    }

    [Fact]
    public async Task Role_assignment_change_revokes_existing_sessions()
    {
        await using var fixture = await Fixture.CreateAsync();
        var session = await fixture.CreateTargetSessionAsync();

        var result = await fixture.UserAdministration.UpdateAsync(
            fixture.Target.Id,
            fixture.Target.UserName,
            "Administrator",
            TestContext.Current.CancellationToken);

        result.IsSuccess.Should().BeTrue();
        (await fixture.Store.ValidateAsync(session.SessionId, fixture.Target.Id, TestContext.Current.CancellationToken)).IsValid.Should().BeFalse();
    }

    [Fact]
    public async Task Self_service_cannot_revoke_another_users_session()
    {
        await using var fixture = await Fixture.CreateAsync();
        var actorSession = await fixture.Store.CreateAsync(
            fixture.Actor.Id,
            fixture.Actor.UserName,
            fixture.Actor.Role,
            false,
            NewHttpContext(),
            TestContext.Current.CancellationToken);
        var targetSession = await fixture.CreateTargetSessionAsync();
        fixture.SetPrincipal(fixture.Actor, actorSession.SessionId);

        var result = await fixture.SessionAdministration.RevokeOwnAsync(targetSession.SessionId, TestContext.Current.CancellationToken);

        result.IsSuccess.Should().BeFalse();
        (await fixture.Store.ValidateAsync(targetSession.SessionId, fixture.Target.Id, TestContext.Current.CancellationToken)).IsValid.Should().BeTrue();
    }

    private static DefaultHttpContext NewHttpContext()
    {
        var context = new DefaultHttpContext();
        context.Connection.RemoteIpAddress = System.Net.IPAddress.Loopback;
        context.Request.Headers.UserAgent = "AIWM Session Test";
        return context;
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        private readonly TestHttpContextAccessor _accessor;
        public AppDbContext Context { get; }
        public AuthUser Actor { get; }
        public AuthUser Target { get; }
        public ApplicationSessionStore Store { get; }
        public ApplicationUserAdministrationService UserAdministration { get; }
        public ApplicationSessionAdministrationService SessionAdministration { get; }

        private Fixture(
            SqliteConnection connection,
            AppDbContext context,
            AuthUser actor,
            AuthUser target,
            ApplicationSessionStore store,
            ApplicationUserAdministrationService userAdministration,
            ApplicationSessionAdministrationService sessionAdministration,
            TestHttpContextAccessor accessor)
        {
            _connection = connection;
            Context = context;
            Actor = actor;
            Target = target;
            Store = store;
            UserAdministration = userAdministration;
            SessionAdministration = sessionAdministration;
            _accessor = accessor;
        }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync(TestContext.Current.CancellationToken);

            var actor = new AuthUser("security.admin", "hash", DateTime.UtcNow, "Administrator");
            var target = new AuthUser("session.user", "hash", DateTime.UtcNow, "User");
            context.AuthUsers.AddRange(actor, target);
            await context.SaveChangesAsync(TestContext.Current.CancellationToken);

            var accessor = new TestHttpContextAccessor(new DefaultHttpContext());
            var currentUser = new CurrentUserContext(accessor);
            var store = new ApplicationSessionStore(context);
            var roleStore = new ApplicationRoleStore(context);
            var userAdministration = new ApplicationUserAdministrationService(context, currentUser, roleStore, store);
            var sessionAdministration = new ApplicationSessionAdministrationService(context, currentUser, store);
            var fixture = new Fixture(connection, context, actor, target, store, userAdministration, sessionAdministration, accessor);
            fixture.SetPrincipal(actor, Guid.NewGuid().ToString("N"));
            return fixture;
        }

        public Task<ApplicationSessionRecord> CreateTargetSessionAsync() => Store.CreateAsync(
            Target.Id,
            Target.UserName,
            Target.Role,
            false,
            NewHttpContext(),
            TestContext.Current.CancellationToken);

        public void SetPrincipal(AuthUser user, string sessionId)
        {
            _accessor.HttpContext = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity([
                    new Claim(ClaimTypes.NameIdentifier, user.Id.ToString()),
                    new Claim(ClaimTypes.Name, user.UserName),
                    new Claim(ClaimTypes.Role, user.Role),
                    new Claim(ApplicationSessionStore.ClaimType, sessionId),
                    new Claim(ApplicationPermissionCatalog.ClaimType, ApplicationPermissionCatalog.UsersManage)
                ], "Test"))
            };
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
