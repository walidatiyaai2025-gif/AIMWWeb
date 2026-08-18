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
    public async Task Legacy_User_Cannot_List_Application_Users()
    {
        await using var fixture = await Fixture.CreateAsync(ApplicationRoles.LegacyUser);
        var action = () => fixture.Service.ListAsync();
        await action.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    [Fact]
    public async Task Manager_Can_Read_Users_But_Cannot_Create_Accounts()
    {
        await using var fixture = await Fixture.CreateAsync(ApplicationRoles.Manager);

        var users = await fixture.Service.ListAsync();
        users.Should().ContainSingle(x => x.Id == fixture.Actor.Id);

        var create = () => fixture.Service.CreateAsync("blocked.user", "StrongPass1", "StrongPass1", ApplicationRoles.Operator);
        await create.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    [Fact]
    public async Task Administrator_Can_Create_User_With_Hashed_Password()
    {
        await using var fixture = await Fixture.CreateAsync();
        var result = await fixture.Service.CreateAsync("editor.one", "StrongPass1", "StrongPass1", ApplicationRoles.LegacyUser);
        result.IsSuccess.Should().BeTrue();
        var stored = await fixture.Context.AuthUsers.AsNoTracking().SingleAsync(x => x.UserName == "editor.one");
        stored.PasswordHash.Should().NotBe("StrongPass1");
        new PasswordHasher<AuthUser>().VerifyHashedPassword(stored, stored.PasswordHash, "StrongPass1").Should().NotBe(PasswordVerificationResult.Failed);
    }

    [Fact]
    public async Task Administrator_Can_Assign_New_Roles_Using_Canonical_Names()
    {
        await using var fixture = await Fixture.CreateAsync();

        var result = await fixture.Service.CreateAsync("ops.manager", "StrongPass1", "StrongPass1", " manager ");

        result.IsSuccess.Should().BeTrue();
        var stored = await fixture.Context.AuthUsers.AsNoTracking().SingleAsync(x => x.UserName == "ops.manager");
        stored.Role.Should().Be(ApplicationRoles.Manager);
    }

    [Fact]
    public async Task Unknown_Role_Is_Rejected_Without_Persisting_User()
    {
        await using var fixture = await Fixture.CreateAsync();

        var result = await fixture.Service.CreateAsync("unknown.role", "StrongPass1", "StrongPass1", "SuperUser");

        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("configured application roles");
        (await fixture.Context.AuthUsers.AsNoTracking().AnyAsync(x => x.UserName == "unknown.role")).Should().BeFalse();
    }

    [Fact]
    public async Task Administrator_Cannot_Disable_Own_Account()
    {
        await using var fixture = await Fixture.CreateAsync();
        var result = await fixture.Service.SetActiveAsync(fixture.Actor.Id, false);
        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("own account");
        (await fixture.Context.AuthUsers.AsNoTracking().SingleAsync(x => x.Id == fixture.Actor.Id)).IsActive.Should().BeTrue();
    }

    [Fact]
    public async Task Signed_In_Administrator_Cannot_Demote_Self()
    {
        await using var fixture = await Fixture.CreateAsync();
        var result = await fixture.Service.UpdateAsync(fixture.Actor.Id, fixture.Actor.UserName, ApplicationRoles.Manager);
        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("own administrator role");
    }

    [Fact]
    public async Task Last_Active_Administrator_Cannot_Be_Disabled()
    {
        await using var fixture = await Fixture.CreateAsync();
        var otherAdmin = await fixture.AddUserAsync("backup.admin", ApplicationRoles.Administrator);
        var actor = await fixture.Context.AuthUsers.SingleAsync(x => x.Id == fixture.Actor.Id);
        actor.SetRole(ApplicationRoles.LegacyUser, DateTime.UtcNow);
        await fixture.Context.SaveChangesAsync();
        fixture.SetActorRole(ApplicationRoles.Administrator);

        var result = await fixture.Service.SetActiveAsync(otherAdmin.Id, false);
        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Contain("At least one active administrator");
    }

    [Fact]
    public async Task ResetPassword_Clears_Lockout_State()
    {
        await using var fixture = await Fixture.CreateAsync();
        var user = await fixture.AddUserAsync("locked.user", ApplicationRoles.LegacyUser);
        for (var i = 0; i < 5; i++) user.RecordFailedLogin(DateTime.UtcNow);
        await fixture.Context.SaveChangesAsync();

        var reset = await fixture.Service.ResetPasswordAsync(user.Id, "NewStrong2", "NewStrong2");
        reset.IsSuccess.Should().BeTrue();
        fixture.Context.ChangeTracker.Clear();
        var stored = await fixture.Context.AuthUsers.SingleAsync(x => x.Id == user.Id);
        stored.FailedAccessCount.Should().Be(0);
        stored.LockedUntilUtc.Should().BeNull();
        new PasswordHasher<AuthUser>().VerifyHashedPassword(stored, stored.PasswordHash, "NewStrong2").Should().NotBe(PasswordVerificationResult.Failed);
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
            _connection = connection; Context = context; Actor = actor; Service = service; _accessor = accessor;
        }

        public static async Task<Fixture> CreateAsync(string actorRole = ApplicationRoles.Administrator)
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();
            var actor = new AuthUser("test.admin", "hash", DateTime.UtcNow, actorRole);
            context.AuthUsers.Add(actor);
            await context.SaveChangesAsync();
            var accessor = new IsolatedHttpContextAccessor(CreateHttpContext(actor, actorRole));
            var service = new ApplicationUserAdministrationService(context, new CurrentUserContext(accessor));
            return new Fixture(connection, context, actor, service, accessor);
        }

        public void SetActorRole(string role) => _accessor.HttpContext = CreateHttpContext(Actor, role);

        private static HttpContext CreateHttpContext(AuthUser actor, string role)
        {
            var identity = new ClaimsIdentity([
                new Claim(ClaimTypes.NameIdentifier, actor.Id.ToString()),
                new Claim(ClaimTypes.Name, actor.UserName),
                new Claim(ClaimTypes.Role, role)
            ], "Test");
            return new DefaultHttpContext { User = new ClaimsPrincipal(identity) };
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
