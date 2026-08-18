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

public sealed class AccountProfileSecurityAuditTests
{
    [Fact]
    public async Task Password_change_keeps_current_session_revokes_others_and_audits_without_secrets()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:");
        await connection.OpenAsync();
        await using var db = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
        await db.Database.EnsureCreatedAsync();

        const string currentPassword = "CurrentPass1";
        const string newPassword = "ChangedPass2";
        var user = new AuthUser("profile.user", "temporary", DateTime.UtcNow, "User");
        user.SetPasswordHash(new PasswordHasher<AuthUser>().HashPassword(user, currentPassword), DateTime.UtcNow);
        db.AuthUsers.Add(user);
        await db.SaveChangesAsync();

        var sessions = new ApplicationSessionStore(db);
        var current = await sessions.CreateAsync(user.Id, user.UserName, user.Role, null, "Current", false);
        var other = await sessions.CreateAsync(user.Id, user.UserName, user.Role, null, "Other", true);
        var claims = new[]
        {
            new Claim(ClaimTypes.NameIdentifier, user.Id.ToString("D")),
            new Claim(ClaimTypes.Name, user.UserName),
            new Claim(ClaimTypes.Role, user.Role),
            new Claim(ApplicationSessionStore.SessionIdClaimType, current.SessionId.ToString("D"))
        };
        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test")),
            TraceIdentifier = "profile-password"
        };
        var accessor = new IsolatedHttpContextAccessor(context);
        var service = new AccountProfileService(db, new CurrentUserContext(accessor), accessor);

        var result = await service.ChangePasswordAsync(currentPassword, newPassword, newPassword);

        result.IsSuccess.Should().BeTrue();
        (await sessions.ValidateAsync(current.SessionId, user.Id)).IsValid.Should().BeTrue();
        (await sessions.ValidateAsync(other.SessionId, user.Id)).IsValid.Should().BeFalse();
        var audit = await new ApplicationSecurityAuditStore(db).ListAsync(new SecurityAuditQuery(Category: "Account"));
        audit.Should().ContainSingle(x => x.Action == "Password.Changed" && x.Outcome == "Succeeded" && x.TargetId == user.Id.ToString("D"));
        audit.Single().Metadata["revokedOtherSessions"].Should().Be("1");
        var raw = await db.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == ApplicationSecurityAuditStore.SettingsKey)
            .Select(x => x.Value)
            .SingleAsync();
        raw.Contains(currentPassword, StringComparison.Ordinal).Should().BeFalse();
        raw.Contains(newPassword, StringComparison.Ordinal).Should().BeFalse();
    }

    [Fact]
    public async Task Incorrect_current_password_is_audited_and_sessions_remain_active()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:");
        await connection.OpenAsync();
        await using var db = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
        await db.Database.EnsureCreatedAsync();

        var user = new AuthUser("profile.user", "temporary", DateTime.UtcNow, "User");
        user.SetPasswordHash(new PasswordHasher<AuthUser>().HashPassword(user, "CurrentPass1"), DateTime.UtcNow);
        db.AuthUsers.Add(user);
        await db.SaveChangesAsync();
        var sessions = new ApplicationSessionStore(db);
        var session = await sessions.CreateAsync(user.Id, user.UserName, user.Role, null, "Browser", false);
        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(new[]
            {
                new Claim(ClaimTypes.NameIdentifier, user.Id.ToString("D")),
                new Claim(ClaimTypes.Name, user.UserName),
                new Claim(ClaimTypes.Role, user.Role),
                new Claim(ApplicationSessionStore.SessionIdClaimType, session.SessionId.ToString("D"))
            }, "Test"))
        };
        var accessor = new IsolatedHttpContextAccessor(context);
        var service = new AccountProfileService(db, new CurrentUserContext(accessor), accessor);

        var result = await service.ChangePasswordAsync("WrongPass9", "ChangedPass2", "ChangedPass2");

        result.IsSuccess.Should().BeFalse();
        (await sessions.ValidateAsync(session.SessionId, user.Id)).IsValid.Should().BeTrue();
        var audit = await new ApplicationSecurityAuditStore(db).ListAsync(new SecurityAuditQuery(Category: "Account"));
        audit.Should().ContainSingle(x => x.Action == "Password.Changed" && x.Outcome == "Failed");
    }

    private sealed class IsolatedHttpContextAccessor(HttpContext context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }
}