using System.Security.Claims;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Identity;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class ApplicationUserAdministrationServiceTests
{
    [Fact]
    public async Task Normal_User_Cannot_List_Application_Users()
    {
        await using var fixture = await Fixture.CreateAsync("User");
        var action = () => fixture.Service.ListAsync();
        await action.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    [Fact]
    public async Task UsersView_Claim_Can_List_But_Cannot_Create_Application_Users()
    {
        await using var fixture = await Fixture.CreateAsync("User", ApplicationPermissionCatalog.UsersView);

        var users = await fixture.Service.ListAsync();
        users.Should().ContainSingle(x => x.Id == fixture.Actor.Id);

        var create = () => fixture.Service.CreateAsync("blocked.editor", "StrongPass1", "StrongPass1", "User");
        await create.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    [Fact]
    public async Task UsersManage_Claim_Can_Create_User_Without_Administrator_Role()
    {
        await using var fixture = await Fixture.CreateAsync("User", ApplicationPermissionCatalog.UsersManage);

        var result = await fixture.Service.CreateAsync("editor.one", "StrongPass1", "StrongPass1", "User");

        result.IsSuccess.Should().BeTrue();
        var stored = await fixture.Context.AuthUsers.AsNoTracking().SingleAsync(x => x.UserName == "editor.one");
        stored.PasswordHash.Should().NotBe("StrongPass1");
        var audit = await new ApplicationSecurityAuditStore(fixture.Context).ListAsync(new SecurityAuditQuery(Category: "Account"));
        audit.Should().ContainSingle(x => x.Action == "User.Created" && x.Outcome == "Succeeded" && x.TargetId == stored.Id.ToString("D"));
        audit.Single().ActorUserId.Should().Be(fixture.Actor.Id);
        audit.Single().Metadata["role"].Should().Be("User");
    }

    [Fact]
    public async Task Administrator_Can_Create_User_With_Hashed_Password()
    {
        await using var fixture = await Fixture.CreateAsync();
        var result = await fixture.Service.CreateAsync("editor.one", "StrongPass1", "StrongPass1", "User");
        result.IsSuccess.Should().BeTrue();
        var stored = await fixture.Context.AuthUsers.AsNoTracking().SingleAsync(x => x.UserName == "editor.one");
        stored.PasswordHash.Should().NotBe("StrongPass1");
        new PasswordHasher<AuthUser>().VerifyHashedPassword(stored, stored.PasswordHash, "StrongPass1").Should().NotBe(PasswordVerificationResult.Failed);
    }

    [Fact]
    public async Task Administrator_Cannot_Disable_Own_Account_And_Block_Is_Audited()
    {
        await using var fixture = await Fixture.CreateAsync();
        var result = await fixture.Service.SetActiveAsync(fixture.Actor.Id, false);
        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("own account");
        (await fixture.Context.AuthUsers.AsNoTracking().SingleAsync(x => x.Id == fixture.Actor.Id)).IsActive.Should().BeTrue();
        var audit = await new ApplicationSecurityAuditStore(fixture.Context).ListAsync(new SecurityAuditQuery(Category: "Account"));
        audit.Should().ContainSingle(x => x.Action == "User.Disabled" && x.Outcome == "Blocked" && x.TargetId == fixture.Actor.Id.ToString("D"));
    }

    [Fact]
    public async Task Signed_In_Administrator_Cannot_Demote_Self()
    {
        await using var fixture = await Fixture.CreateAsync();
        var result = await fixture.Service.UpdateAsync(fixture.Actor.Id, fixture.Actor.UserName, "User");
        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("own administrator role");
    }

    [Fact]
    public async Task Last_Active_Administrator_Cannot_Be_Disabled()
    {
        await using var fixture = await Fixture.CreateAsync();
        var otherAdmin = await fixture.AddUserAsync("backup.admin", "Administrator");
        var actor = await fixture.Context.AuthUsers.SingleAsync(x => x.Id == fixture.Actor.Id);
        actor.SetRole("User", DateTime.UtcNow);
        await fixture.Context.SaveChangesAsync();
        fixture.SetActorIdentity("Administrator");

        var result = await fixture.Service.SetActiveAsync(otherAdmin.Id, false);
        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("At least one active administrator");
    }

    [Fact]
    public async Task ResetPassword_Clears_Lockout_State()
    {
        await using var fixture = await Fixture.CreateAsync();
        var user = await fixture.AddUserAsync("locked.user", "User");
        for (var i = 0; i < 5; i++) user.RecordFailedLogin(DateTime.UtcNow);
        await fixture.Context.SaveChangesAsync();

        var reset = await fixture.Service.ResetPasswordAsync(user.Id, "NewStrong2", "NewStrong2");
        reset.IsSuccess.Should().BeTrue();
        fixture.Context.ChangeTracker.Clear();
        var stored = await fixture.Context.AuthUsers.SingleAsync(x => x.Id == user.Id);
        stored.FailedAccessCount.Should().Be(0);
        stored.LockedUntilUtc.Should().BeNull();
        new PasswordHasher<AuthUser>().VerifyHashedPassword(stored, stored.PasswordHash, "NewStrong2").Should().NotBe(PasswordVerificationResult.Failed);
        var audit = await new ApplicationSecurityAuditStore(fixture.Context).ListAsync(new SecurityAuditQuery(Category: "Account"));
        audit.Should().Contain(x => x.Action == "Password.Reset" && x.Outcome == "Succeeded" && x.TargetId == user.Id.ToString("D"));
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        private readonly IsolatedHttpContextAccessor _accessor;
        public AppDbContext Context { get; }
        public AuthUser Actor { get; }
        public ApplicationUserAdministrationService Service { get; }

        private Fixture(SqliteConnection connection, AppDbContext context, AuthUser actor, ApplicationUserAdministrationService service, IsolatedHttpContextAccessor accessor)
        {
            _connection = connection;
            Context = context;
            Actor = actor;
            Service = service;
            _accessor = accessor;
        }

        public static async Task<Fixture> CreateAsync(string actorRole = "Administrator", params string[] permissions)
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();
            var actor = new AuthUser("test.admin", "hash", DateTime.UtcNow, actorRole);
            context.AuthUsers.Add(actor);
            await context.SaveChangesAsync();
            var accessor = new IsolatedHttpContextAccessor(CreateHttpContext(actor, actorRole, permissions));
            var service = new ApplicationUserAdministrationService(context, new CurrentUserContext(accessor), httpContextAccessor: accessor);
            return new Fixture(connection, context, actor, service, accessor);
        }

        public void SetActorIdentity(string role, params string[] permissions) =>
            _accessor.HttpContext = CreateHttpContext(Actor, role, permissions);

        private static HttpContext CreateHttpContext(AuthUser actor, string role, IEnumerable<string> permissions)
        {
            var claims = new List<Claim>
            {
                new(ClaimTypes.NameIdentifier, actor.Id.ToString()),
                new(ClaimTypes.Name, actor.UserName),
                new(ClaimTypes.Role, role)
            };
            claims.AddRange(permissions.Select(permission => new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
            var identity = new ClaimsIdentity(claims, "Test");
            return new DefaultHttpContext { User = new ClaimsPrincipal(identity), TraceIdentifier = "user-admin-test" };
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