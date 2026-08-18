using System.Security.Claims;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ApprovalPermissionBoundaryTests
{
    [Fact]
    public async Task Persisted_view_only_role_cannot_mutate_even_with_spoofed_decide_claim()
    {
        await using var fixture = await ApprovalPermissionFixture.CreateAsync(
            "ApprovalViewer",
            [ApplicationPermissionCatalog.ApprovalsView],
            principalPermissions: [ApplicationPermissionCatalog.ApprovalsView, ApplicationPermissionCatalog.ApprovalsDecide]);

        var action = () => fixture.Service.Submit(
            fixture.UserId,
            CreateSubmission("view-only"),
            "viewer@example.com");

        action.Should().Throw<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.ApprovalsDecide}*");
        fixture.Service.GetItems(fixture.UserId).Should().BeEmpty();
    }

    [Fact]
    public async Task Persisted_decide_role_can_submit_and_reject_own_approval()
    {
        await using var fixture = await ApprovalPermissionFixture.CreateAsync(
            "ApprovalDecider",
            [ApplicationPermissionCatalog.ApprovalsView, ApplicationPermissionCatalog.ApprovalsDecide],
            principalPermissions: [ApplicationPermissionCatalog.ApprovalsView]);

        var item = fixture.Service.Submit(
            fixture.UserId,
            CreateSubmission("decide"),
            "decider@example.com");
        var rejected = fixture.Service.Reject(
            fixture.UserId,
            item.Id,
            "decider@example.com",
            "Rejected by authorized decider");

        item.Status.Should().Be(ApprovalStatus.Pending);
        rejected.Status.Should().Be(ApprovalStatus.Rejected);
    }

    [Fact]
    public async Task Authenticated_owner_cannot_mutate_for_another_decider_account()
    {
        await using var fixture = await ApprovalPermissionFixture.CreateAsync(
            "ApprovalDecider",
            [ApplicationPermissionCatalog.ApprovalsView, ApplicationPermissionCatalog.ApprovalsDecide],
            principalPermissions: [ApplicationPermissionCatalog.ApprovalsDecide]);
        var otherUserId = await fixture.AddUserAsync("other-approval-decider");

        var action = () => fixture.Service.Submit(
            otherUserId,
            CreateSubmission("cross-owner"),
            "spoofed-other@example.com");

        action.Should().Throw<UnauthorizedAccessException>()
            .WithMessage("*owner identity*");
        fixture.Service.GetItems(otherUserId).Should().BeEmpty();
    }

    [Fact]
    public async Task Inactive_account_fails_closed_before_approval_mutation()
    {
        await using var fixture = await ApprovalPermissionFixture.CreateAsync(
            "ApprovalDecider",
            [ApplicationPermissionCatalog.ApprovalsView, ApplicationPermissionCatalog.ApprovalsDecide],
            principalPermissions: [ApplicationPermissionCatalog.ApprovalsDecide],
            isActive: false);

        var action = () => fixture.Service.Submit(
            fixture.UserId,
            CreateSubmission("inactive"),
            "inactive@example.com");

        action.Should().Throw<UnauthorizedAccessException>();
        fixture.Service.GetItems(fixture.UserId).Should().BeEmpty();
    }

    private static ApprovalSubmission CreateSubmission(string key) => new(
        null,
        string.Empty,
        "AI Suggestion",
        "Approval permission boundary",
        new { title = "Before" },
        new { title = "After" },
        "ignored-client-actor@example.com",
        ApprovalRiskLevel.Medium,
        Guid.NewGuid().ToString("N"),
        key);

    private sealed class ApprovalPermissionFixture : IAsyncDisposable
    {
        private readonly string _directory;
        private readonly SqliteConnection _connection;
        private readonly ServiceProvider _provider;
        private readonly ExecutionCenterService _executionCenter;
        private readonly string _roleName;

        private ApprovalPermissionFixture(
            string directory,
            SqliteConnection connection,
            ServiceProvider provider,
            ExecutionCenterService executionCenter,
            string roleName,
            Guid userId,
            ApprovalWorkflowService service)
        {
            _directory = directory;
            _connection = connection;
            _provider = provider;
            _executionCenter = executionCenter;
            _roleName = roleName;
            UserId = userId;
            Service = service;
        }

        public Guid UserId { get; }
        public ApprovalWorkflowService Service { get; }

        public static async Task<ApprovalPermissionFixture> CreateAsync(
            string roleName,
            IReadOnlyList<string> persistedPermissions,
            IReadOnlyList<string> principalPermissions,
            bool isActive = true)
        {
            var directory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(directory);

            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();

            var services = new ServiceCollection();
            services.AddDbContext<AppDbContext>(options => options.UseSqlite(connection));
            var provider = services.BuildServiceProvider();

            Guid userId;
            await using (var scope = provider.CreateAsyncScope())
            {
                var db = scope.ServiceProvider.GetRequiredService<AppDbContext>();
                await db.Database.EnsureCreatedAsync();
                var user = new AuthUser("approval-permission-user", "test-password-hash", DateTime.UtcNow, roleName);
                if (!isActive) user.SetActive(false, DateTime.UtcNow);
                db.AuthUsers.Add(user);
                await db.SaveChangesAsync();
                userId = user.Id;

                var roles = new ApplicationRoleStore(db);
                await roles.SaveAsync([
                    new CustomApplicationRole(
                        roleName,
                        roleName,
                        roleName,
                        true,
                        persistedPermissions)
                ]);
            }

            var claims = new List<Claim>
            {
                new(ClaimTypes.NameIdentifier, userId.ToString()),
                new(ClaimTypes.Name, "approval-permission-user")
            };
            claims.AddRange(principalPermissions.Select(permission =>
                new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
            var accessor = new HttpContextAccessor
            {
                HttpContext = new DefaultHttpContext
                {
                    User = new ClaimsPrincipal(new ClaimsIdentity(claims, "test"))
                }
            };

            var executionCenter = new ExecutionCenterService(
                Path.Combine(directory, "execution-center.db"),
                false,
                false);
            var notifications = NotificationInboxService.ForDatabase(
                Path.Combine(directory, "notifications.db"));
            var service = new ApprovalWorkflowService(
                executionCenter,
                notifications,
                provider.GetRequiredService<IServiceScopeFactory>(),
                accessor);

            return new ApprovalPermissionFixture(
                directory,
                connection,
                provider,
                executionCenter,
                roleName,
                userId,
                service);
        }

        public async Task<Guid> AddUserAsync(string userName)
        {
            await using var scope = _provider.CreateAsyncScope();
            var db = scope.ServiceProvider.GetRequiredService<AppDbContext>();
            var user = new AuthUser(userName, "test-password-hash", DateTime.UtcNow, _roleName);
            db.AuthUsers.Add(user);
            await db.SaveChangesAsync();
            return user.Id;
        }

        public async ValueTask DisposeAsync()
        {
            _executionCenter.Dispose();
            await _provider.DisposeAsync();
            await _connection.DisposeAsync();
            SqliteConnection.ClearAllPools();
            try { Directory.Delete(_directory, true); } catch { }
        }
    }
}
