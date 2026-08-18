using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Identity;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;

namespace AIWordPressManager.Tests;

public sealed class LocalAuthenticationSessionTests
{
    [Fact]
    public async Task Successful_login_issues_server_tracked_session_claim()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:");
        await connection.OpenAsync();
        await using var db = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
        await db.Database.EnsureCreatedAsync();

        const string password = "StrongPass1";
        var user = new AuthUser("tracked.user", "temporary", DateTime.UtcNow, "User");
        user.SetPasswordHash(new PasswordHasher<AuthUser>().HashPassword(user, password), DateTime.UtcNow);
        db.AuthUsers.Add(user);
        await db.SaveChangesAsync();

        var authentication = new CapturingAuthenticationService();
        await using var services = new ServiceCollection()
            .AddSingleton<IAuthenticationService>(authentication)
            .BuildServiceProvider();
        var context = new DefaultHttpContext { RequestServices = services };
        context.Request.Headers.UserAgent = "Session test browser";
        var sessionStore = new ApplicationSessionStore(db);
        var service = new LocalAuthenticationService(db, new ApplicationRoleStore(db), sessionStore);

        var result = await service.SignInAsync(context, user.UserName, password, rememberMe: false);

        result.IsSuccess.Should().BeTrue();
        authentication.SignedInPrincipal.Should().NotBeNull();
        var sessionClaim = authentication.SignedInPrincipal!.FindFirst(ApplicationSessionStore.SessionIdClaimType);
        sessionClaim.Should().NotBeNull();
        Guid.TryParse(sessionClaim!.Value, out var sessionId).Should().BeTrue();
        var persisted = await sessionStore.TryGetAsync(sessionId);
        persisted.Should().NotBeNull();
        persisted!.UserId.Should().Be(user.Id);
        persisted.UserAgent.Should().Be("Session test browser");
    }

    private sealed class CapturingAuthenticationService : IAuthenticationService
    {
        public System.Security.Claims.ClaimsPrincipal? SignedInPrincipal { get; private set; }

        public Task<AuthenticateResult> AuthenticateAsync(HttpContext context, string? scheme) => Task.FromResult(AuthenticateResult.NoResult());
        public Task ChallengeAsync(HttpContext context, string? scheme, AuthenticationProperties? properties) => Task.CompletedTask;
        public Task ForbidAsync(HttpContext context, string? scheme, AuthenticationProperties? properties) => Task.CompletedTask;
        public Task SignOutAsync(HttpContext context, string? scheme, AuthenticationProperties? properties) => Task.CompletedTask;

        public Task SignInAsync(HttpContext context, string? scheme, System.Security.Claims.ClaimsPrincipal principal, AuthenticationProperties? properties)
        {
            SignedInPrincipal = principal;
            return Task.CompletedTask;
        }
    }
}