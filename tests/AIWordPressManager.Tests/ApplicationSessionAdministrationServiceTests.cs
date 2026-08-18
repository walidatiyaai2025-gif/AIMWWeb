using System.Security.Claims;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class ApplicationSessionAdministrationServiceTests
{
    [Fact]
    public async Task Self_service_lists_only_current_users_sessions_and_cannot_end_another_users_session()
    {
        await using var fixture = await Fixture.CreateAsync(includeUsersManage: false);
        var mine = await fixture.Store.CreateAsync(fixture.ActorId, "self.user", "User", null, "Mine", false);
        var other = await fixture.Store.CreateAsync(Guid.NewGuid(), "other.user", "User", null, "Other", false);
        fixture.SetCurrentSession(mine.SessionId);

        var listed = await fixture.Service.ListMineAsync();
        var endOther = await fixture.Service.EndMySessionAsync(other.SessionId, "Self service");

        listed.Should().ContainSingle(x => x.SessionId == mine.SessionId && x.IsCurrent);
        endOther.IsSuccess.Should().BeFalse();
        (await fixture.Store.ValidateAsync(other.SessionId, other.UserId)).IsValid.Should().BeTrue();
    }

    [Fact]
    public async Task UsersManage_can_end_another_users_session()
    {
        await using var fixture = await Fixture.CreateAsync(includeUsersManage: true);
        var targetUserId = Guid.NewGuid();
        var target = await fixture.Store.CreateAsync(targetUserId, "target.user", "User", null, null, false);

        var result = await fixture.Service.EndSessionAsync(target.SessionId, "Administrator ended session");

        result.IsSuccess.Should().BeTrue();
        (await fixture.Store.ValidateAsync(target.SessionId, targetUserId)).IsValid.Should().BeFalse();
    }

    [Fact]
    public async Task User_without_UsersManage_cannot_use_administrator_end_session_operation()
    {
        await using var fixture = await Fixture.CreateAsync(includeUsersManage: false);
        var target = await fixture.Store.CreateAsync(Guid.NewGuid(), "target.user", "User", null, null, false);

        var action = () => fixture.Service.EndSessionAsync(target.SessionId, "Not allowed");

        await action.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        private readonly DefaultHttpContext _httpContext;
        public AppDbContext Context { get; }
        public Guid ActorId { get; }
        public ApplicationSessionStore Store { get; }
        public ApplicationSessionAdministrationService Service { get; }

        private Fixture(
            SqliteConnection connection,
            AppDbContext context,
            Guid actorId,
            DefaultHttpContext httpContext,
            ApplicationSessionStore store,
            ApplicationSessionAdministrationService service)
        {
            _connection = connection;
            Context = context;
            ActorId = actorId;
            _httpContext = httpContext;
            Store = store;
            Service = service;
        }

        public static async Task<Fixture> CreateAsync(bool includeUsersManage)
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();
            var actorId = Guid.NewGuid();
            var claims = new List<Claim>
            {
                new(ClaimTypes.NameIdentifier, actorId.ToString()),
                new(ClaimTypes.Name, "session.admin"),
                new(ClaimTypes.Role, includeUsersManage ? "Administrator" : "User")
            };
            if (includeUsersManage)
                claims.Add(new Claim(ApplicationPermissionCatalog.ClaimType, ApplicationPermissionCatalog.UsersManage));
            var httpContext = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"))
            };
            var accessor = new IsolatedHttpContextAccessor(httpContext);
            var currentUser = new CurrentUserContext(accessor);
            var store = new ApplicationSessionStore(context);
            var service = new ApplicationSessionAdministrationService(store, currentUser, accessor);
            return new Fixture(connection, context, actorId, httpContext, store, service);
        }

        public void SetCurrentSession(Guid sessionId)
        {
            var identity = (ClaimsIdentity)_httpContext.User.Identity!;
            identity.AddClaim(new Claim(ApplicationSessionStore.SessionIdClaimType, sessionId.ToString("D")));
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