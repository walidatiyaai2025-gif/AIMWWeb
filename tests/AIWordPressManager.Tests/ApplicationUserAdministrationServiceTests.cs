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
    public async Task Non_Administrator_Cannot_List_Application_Users()
    {
        await using var fixture = await Fixture.CreateAsync("User");

        var action = () => fixture.Service.ListAsync();

        await action.Should().ThrowAsync<UnauthorizedAccessException>();
    }

    [Fact]
    public async Task Administrator_Can_Create_User_With_Hashed_Password()
    {
        await using var fixture = await Fixture.CreateAsync();

        var result = await fixture.Service.CreateAsync("editor.one", "StrongPass1", "StrongPass1", "User");

        result.IsSuccess.Should().BeTrue();
        var stored = await fixture.Context.AuthUsers.AsNoTracking().SingleAsync(x => x.UserName == "editor.one");
        stored.PasswordHash.Should().NotBe("StrongPass1");
        new PasswordHasher<AuthUser>().VerifyHashedPassword(stored, stored.PasswordHash, "StrongPass1")
            .Should().NotBe(PasswordVerificationResult.Failed);
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
    public async Task Last_Administrator_Cannot_Be_Demoted()
    {
        await using var fixture = await Fixture.CreateAsync();
        var otherAdmin = await fixture.AddUserAsync("backup.admin", "Administrator");
        await fixture.Service.SetActiveAsync(otherAdmin.Id, false);

        var result = await fixture.Service.UpdateAsync(otherAdmin.Id, otherAdmin.UserName, "User");

        result.IsSuccess.Should().BeTrue("the signed-in administrator remains active");

        var selfDemotion = await fixture.Service.UpdateAsync(fixture.Actor.Id, fixture.Actor.UserName, "User");
        selfDemotion.IsSuccess.Should().BeFalse();
        selfDemotion.Message.Should().Contain("own administrator role");
    }

    [Fact]
    public async Task Unlock_And_ResetPassword_Clear_Lockout_State()
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
        new PasswordHasher<AuthUser>().VerifyHashedPassword(stored, stored.PasswordHash, "NewStrong2")
            .Should().NotBe(PasswordVerificationResult.Failed);
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        public AppDbContext Context { get; }
        public AuthUser Actor { get; }
        public ApplicationUserAdministrationService Service { get; }

        private Fixture(SqliteConnection connection, AppDbContext context, AuthUser actor, ApplicationUserAdministrationService service)
        {
            _connection = connection;
            Context = context;
            Actor = actor;
            Service = service;
        }

        public static async Task<Fixture> CreateAsync(string actorRole = "Administrator")
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();

            var now = DateTime.UtcNow;
            var actor = new AuthUser("test.admin", "hash", now, actorRole);
            context.AuthUsers.Add(actor);
            await context.SaveChangesAsync();

            var identity = new ClaimsIdentity(
            [
                new Claim(ClaimTypes.NameIdentifier, actor.Id.ToString()),
                new Claim(ClaimTypes.Name, actor.UserName),
                new Claim(ClaimTypes.Role, actorRole)
            ], "Test");
            var httpContext = new DefaultHttpContext { User = new ClaimsPrincipal(identity) };
            var currentUser = new CurrentUserContext(new IsolatedHttpContextAccessor(httpContext));
            return new Fixture(connection, context, actor, new ApplicationUserAdministrationService(context, currentUser));
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
