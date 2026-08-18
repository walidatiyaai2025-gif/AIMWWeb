using System.Security.Claims;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class ApplicationSecurityAuditServiceTests
{
    [Fact]
    public async Task Read_requires_SettingsManage_permission()
    {
        await using var fixture = await Fixture.CreateAsync([]);
        await fixture.Store.AppendAsync(new SecurityAuditEvent("Account", "User.Created", "Succeeded", null, null, "ApplicationUser"));

        var action = () => fixture.Service.ListAsync();

        await action.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    [Fact]
    public async Task SettingsManage_can_read_and_current_event_uses_server_actor_context()
    {
        await using var fixture = await Fixture.CreateAsync([ApplicationPermissionCatalog.SettingsManage]);

        var record = await fixture.Service.RecordCurrentAsync(
            "Authorization",
            "Role.Updated",
            "Succeeded",
            "ApplicationRole",
            "Editors",
            "Editors",
            new Dictionary<string, string> { ["permissionCount"] = "3" });
        var events = await fixture.Service.ListAsync();

        events.Should().ContainSingle(x => x.EventId == record.EventId);
        record.ActorUserId.Should().Be(fixture.ActorId);
        record.ActorUserName.Should().Be("audit.admin");
        record.CorrelationId.Should().Be("audit-trace");
        record.IpAddress.Should().Be("127.0.0.42");
        record.UserAgent.Should().Be("AuditTest/1.0");
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        public Guid ActorId { get; }
        public AppDbContext Context { get; }
        public ApplicationSecurityAuditStore Store { get; }
        public ApplicationSecurityAuditService Service { get; }

        private Fixture(SqliteConnection connection, Guid actorId, AppDbContext context, ApplicationSecurityAuditStore store, ApplicationSecurityAuditService service)
        {
            _connection = connection;
            ActorId = actorId;
            Context = context;
            Store = store;
            Service = service;
        }

        public static async Task<Fixture> CreateAsync(string[] permissions)
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();

            var actorId = Guid.NewGuid();
            var claims = new List<Claim>
            {
                new(ClaimTypes.NameIdentifier, actorId.ToString("D")),
                new(ClaimTypes.Name, "audit.admin"),
                new(ClaimTypes.Role, "User")
            };
            claims.AddRange(permissions.Select(x => new Claim(ApplicationPermissionCatalog.ClaimType, x)));
            var http = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test")),
                TraceIdentifier = "audit-trace"
            };
            http.Connection.RemoteIpAddress = System.Net.IPAddress.Parse("127.0.0.42");
            http.Request.Headers.UserAgent = "AuditTest/1.0";
            var accessor = new TestAccessor { HttpContext = http };
            var currentUser = new CurrentUserContext(accessor);
            var store = new ApplicationSecurityAuditStore(context);
            var service = new ApplicationSecurityAuditService(context, currentUser, accessor, store);
            return new Fixture(connection, actorId, context, store, service);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await _connection.DisposeAsync();
        }
    }

    private sealed class TestAccessor : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; }
    }
}