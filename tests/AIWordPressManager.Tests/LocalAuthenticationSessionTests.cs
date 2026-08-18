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
    public async Task Successful_login_issues_server_tracked_session_claim_and_security_audit()
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
        var context = new DefaultHttpContext { RequestServices = services, TraceIdentifier = "login-trace" };
        context.Request.Headers.UserAgent = "Session test browser";
        context.Connection.RemoteIpAddress = System.Net.IPAddress.Parse("127.0.0.8");
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

        var audit = await new ApplicationSecurityAuditStore(db).ListAsync(new SecurityAuditQuery(Category: "Authentication"));
        audit.Should().ContainSingle(x => x.Action == "SignIn" && x.Outcome == "Succeeded" && x.ActorUserId == user.Id);
        audit.Single().CorrelationId.Should().Be("login-trace");
        audit.Single().IpAddress.Should().Be("127.0.0.8");
        var raw = await db.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == ApplicationSecurityAuditStore.SettingsKey)
            .Select(x => x.Value)
            .SingleAsync();
        raw.Contains(password, StringComparison.Ordinal).Should().BeFalse();
    }

    [Fact]
    public async Task Failed_password_login_is_audited_without_persisting_submitted_password()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:");
        await connection.OpenAsync();
        await using var db = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
        await db.Database.EnsureCreatedAsync();

        var user = new AuthUser("blocked.user", "temporary", DateTime.UtcNow, "User");
        user.SetPasswordHash(new PasswordHasher<AuthUser>().HashPassword(user, "StrongPass1"), DateTime.UtcNow);
        db.AuthUsers.Add(user);
        await db.SaveChangesAsync();
        const string submittedPassword = "WrongSecret9";
        var context = new DefaultHttpContext { TraceIdentifier = "failed-login" };
        var service = new LocalAuthenticationService(db);

        var result = await service.SignInAsync(context, user.UserName, submittedPassword, rememberMe: false);

        result.IsSuccess.Should().BeFalse();
        var audit = await new ApplicationSecurityAuditStore(db).ListAsync(new SecurityAuditQuery(Category: "Authentication"));
        audit.Should().ContainSingle(x => x.Action == "SignIn" && x.Outcome == "Failed" && x.ActorUserId == user.Id);
        audit.Single().Metadata["reason"].Should().Be("Invalid password");
        var raw = await db.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == ApplicationSecurityAuditStore.SettingsKey)
            .Select(x => x.Value)
            .SingleAsync();
        raw.Contains(submittedPassword, StringComparison.Ordinal).Should().BeFalse();
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