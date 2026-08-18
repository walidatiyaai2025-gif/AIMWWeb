using System.Security.Claims;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class ApplicationRoleSecurityAuditTests
{
    [Fact]
    public async Task Role_create_and_permission_update_are_audited_with_server_actor()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:");
        await connection.OpenAsync();
        await using var db = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
        await db.Database.EnsureCreatedAsync();

        var actor = new AuthUser("role.admin", "hash", DateTime.UtcNow, "Administrator");
        db.AuthUsers.Add(actor);
        await db.SaveChangesAsync();

        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(new[]
            {
                new Claim(ClaimTypes.NameIdentifier, actor.Id.ToString("D")),
                new Claim(ClaimTypes.Name, actor.UserName),
                new Claim(ClaimTypes.Role, "Administrator")
            }, "Test")),
            TraceIdentifier = "role-audit"
        };
        var accessor = new TestAccessor { HttpContext = context };
        var currentUser = new CurrentUserContext(accessor);
        var roleStore = new ApplicationRoleStore(db);
        var service = new ApplicationRoleAdministrationService(db, currentUser, roleStore, httpContextAccessor: accessor);

        var created = await service.SaveAsync(
            "Publisher",
            "Publisher",
            "ناشر",
            [ApplicationPermissionCatalog.ContentView]);
        var updated = await service.SaveAsync(
            "Publisher",
            "Publisher",
            "ناشر",
            [ApplicationPermissionCatalog.ContentView, ApplicationPermissionCatalog.ContentEdit]);

        created.IsSuccess.Should().BeTrue();
        updated.IsSuccess.Should().BeTrue();
        var audit = await new ApplicationSecurityAuditStore(db).ListAsync(new SecurityAuditQuery(Category: "Authorization", Take: 20));
        audit.Should().Contain(x =>
            x.Action == "Role.Created" &&
            x.Outcome == "Succeeded" &&
            x.TargetId == "Publisher" &&
            x.ActorUserId == actor.Id);
        audit.Should().Contain(x =>
            x.Action == "Role.Updated" &&
            x.Outcome == "Succeeded" &&
            x.TargetId == "Publisher" &&
            x.Metadata["grantsChanged"] == bool.TrueString &&
            x.Metadata["permissionCount"] == "2");
        audit.All(x => x.CorrelationId == "role-audit").Should().BeTrue();
    }

    [Fact]
    public async Task Blocking_role_disable_with_active_assignee_is_audited()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:");
        await connection.OpenAsync();
        await using var db = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
        await db.Database.EnsureCreatedAsync();

        var actor = new AuthUser("role.admin", "hash", DateTime.UtcNow, "Administrator");
        db.AuthUsers.Add(actor);
        await db.SaveChangesAsync();
        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(new[]
            {
                new Claim(ClaimTypes.NameIdentifier, actor.Id.ToString("D")),
                new Claim(ClaimTypes.Name, actor.UserName),
                new Claim(ClaimTypes.Role, "Administrator")
            }, "Test"))
        };
        var accessor = new TestAccessor { HttpContext = context };
        var service = new ApplicationRoleAdministrationService(db, new CurrentUserContext(accessor), httpContextAccessor: accessor);

        (await service.SaveAsync("Publisher", "Publisher", "ناشر", [ApplicationPermissionCatalog.ContentView])).IsSuccess.Should().BeTrue();
        db.AuthUsers.Add(new AuthUser("publisher.one", "hash", DateTime.UtcNow, "Publisher"));
        await db.SaveChangesAsync();

        var result = await service.SetActiveAsync("Publisher", false);

        result.IsSuccess.Should().BeFalse();
        var audit = await new ApplicationSecurityAuditStore(db).ListAsync(new SecurityAuditQuery(Category: "Authorization", Take: 20));
        audit.Should().Contain(x =>
            x.Action == "Role.Disabled" &&
            x.Outcome == "Blocked" &&
            x.TargetId == "Publisher" &&
            x.Metadata["reason"] == "Active users remain assigned");
    }

    private sealed class TestAccessor : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; }
    }
}