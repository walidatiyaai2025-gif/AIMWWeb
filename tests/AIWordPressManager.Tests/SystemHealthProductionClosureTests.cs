using System.Security.Claims;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Components.Pages;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class SystemHealthProductionClosureTests
{
    [Fact]
    public void System_health_route_requires_operations_view()
    {
        ApplicationRoutePermissionCatalog.For(typeof(SystemHealth))
            .Should().Be(ApplicationPermissionCatalog.OperationsView);
    }

    [Fact]
    public async Task Service_rejects_missing_operations_view_before_touching_runtime_dependencies()
    {
        var currentUser = CurrentUser(Guid.NewGuid(), ApplicationPermissionCatalog.SitesView);
        var service = new SystemHealthWebService(
            null!, null!, null!, null!, null!, null!, Array.Empty<IAIProvider>(), currentUser,
            NullLogger<SystemHealthWebService>.Instance);

        var action = () => service.CheckAsync();

        await action.Should().ThrowAsync<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.OperationsView}*");
    }

    [Fact]
    public async Task WordPress_health_is_owner_scoped_and_server_paths_are_redacted_from_visible_snapshot()
    {
        await using var fixture = await HealthFixture.CreateAsync();
        var ownerSite = new Site("Owned health site", new Uri("https://owned-health.example.test"), DateTime.UtcNow, fixture.OwnerId);
        var otherSite = new Site("Other tenant health site", new Uri("https://other-health.example.test"), DateTime.UtcNow, fixture.OtherOwnerId);
        fixture.Db.Sites.AddRange(ownerSite, otherSite);
        await fixture.Db.SaveChangesAsync();
        fixture.Db.SiteCredentials.AddRange(
            new SiteCredential(ownerSite.Id, "owner-user", "owner-protected", DateTime.UtcNow),
            new SiteCredential(otherSite.Id, "other-user", "other-protected", DateTime.UtcNow));
        await fixture.Db.SaveChangesAsync();

        var snapshot = await fixture.Service.CheckAsync();

        fixture.ConnectionTester.Requests.Should().ContainSingle();
        fixture.ConnectionTester.Requests[0].SiteUrl.Should().Be(ownerSite.SiteUrl);
        snapshot.Checks.Should().Contain(check => check.Key == $"wordpress:{ownerSite.Id:N}");
        snapshot.Checks.Should().NotContain(check => check.Key == $"wordpress:{otherSite.Id:N}");

        foreach (var check in snapshot.Checks)
        {
            check.Target.Should().NotContain(fixture.Root, "visible/exported targets must not reveal server filesystem paths");
            check.Details.Should().NotContain(fixture.Root, "visible/exported details must not reveal server filesystem paths");
        }

        snapshot.Checks.Single(check => check.Key == "storage").Target.Should().Be("managed-application-data");
        snapshot.Checks.Single(check => check.Key == "logs").Target.Should().Be("managed-application-logs");
    }

    [Fact]
    public async Task Storage_failure_is_honest_without_leaking_the_server_path()
    {
        await using var fixture = await HealthFixture.CreateAsync(blockApplicationStorage: true);

        var snapshot = await fixture.Service.CheckAsync();

        var storage = snapshot.Checks.Single(check => check.Key == "storage");
        storage.IsHealthy.Should().BeFalse();
        storage.Target.Should().Be("managed-application-data");
        storage.Details.Should().Contain("check failed");
        storage.Details.Should().NotContain(fixture.Root);
        snapshot.IsHealthy.Should().BeFalse();
    }

    private static CurrentUserContext CurrentUser(Guid userId, params string[] permissions) =>
        new(Accessor(userId, permissions));

    private sealed class HealthFixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        public string Root { get; }
        public Guid OwnerId { get; }
        public Guid OtherOwnerId { get; }
        public AppDbContext Db { get; }
        public RecordingConnectionTester ConnectionTester { get; }
        public SystemHealthWebService Service { get; }

        private HealthFixture(
            string root,
            Guid ownerId,
            Guid otherOwnerId,
            SqliteConnection connection,
            AppDbContext db,
            RecordingConnectionTester connectionTester,
            SystemHealthWebService service)
        {
            Root = root;
            OwnerId = ownerId;
            OtherOwnerId = otherOwnerId;
            _connection = connection;
            Db = db;
            ConnectionTester = connectionTester;
            Service = service;
        }

        public static async Task<HealthFixture> CreateAsync(bool blockApplicationStorage = false)
        {
            var root = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(root);
            var dataPath = Path.Combine(root, "data");
            if (blockApplicationStorage)
            {
                Directory.CreateDirectory(dataPath);
                var blocked = Path.Combine(dataPath, "blocked");
                await File.WriteAllTextAsync(blocked, "file blocks directory creation");
                dataPath = blocked;
            }
            else
            {
                Directory.CreateDirectory(dataPath);
            }
            var logsPath = Path.Combine(root, "logs");
            Directory.CreateDirectory(logsPath);

            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var db = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await db.Database.EnsureCreatedAsync();

            var ownerId = Guid.NewGuid();
            var otherOwnerId = Guid.NewGuid();
            var currentUser = new CurrentUserContext(Accessor(ownerId, ApplicationPermissionCatalog.OperationsView));
            var tester = new RecordingConnectionTester();
            var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
            {
                ["Database:SetupComplete"] = "true",
                ["Database:Provider"] = "SQLite"
            }).Build();
            var service = new SystemHealthWebService(
                new TestPaths(root, dataPath, logsPath),
                new TestEnvironment(root),
                configuration,
                db,
                new PassThroughSecretProtection(),
                tester,
                Array.Empty<IAIProvider>(),
                currentUser,
                NullLogger<SystemHealthWebService>.Instance);

            return new HealthFixture(root, ownerId, otherOwnerId, connection, db, tester, service);
        }

        public async ValueTask DisposeAsync()
        {
            await Db.DisposeAsync();
            await _connection.DisposeAsync();
            try { Directory.Delete(Root, recursive: true); } catch { }
        }
    }

    private sealed class RecordingConnectionTester : IWordPressConnectionTester
    {
        public List<WordPressConnectionRequest> Requests { get; } = [];
        public Task<WordPressConnectionResult> TestAsync(WordPressConnectionRequest request, CancellationToken cancellationToken = default)
        {
            Requests.Add(request);
            return Task.FromResult(new WordPressConnectionResult(true, "Connection succeeded.", Diagnostics: "HTTP 200"));
        }
    }

    private sealed class PassThroughSecretProtection : ISecretProtectionService
    {
        public Task<string> ProtectAsync(string plainText, CancellationToken cancellationToken = default) => Task.FromResult(plainText);
        public Task<string> UnprotectAsync(string protectedValue, CancellationToken cancellationToken = default) => Task.FromResult(protectedValue);
    }

    private sealed class TestPaths(string root, string data, string logs) : IApplicationPathService
    {
        public string GetApplicationDataDirectory() => data;
        public string GetDatabasePath() => Path.Combine(root, "database.db");
        public string GetLogsDirectory() => logs;
        public string GetScreenshotsDirectory() => Path.Combine(root, "screenshots");
        public string GetBackupsDirectory() => Path.Combine(root, "backups");
        public string GetExportsDirectory() => Path.Combine(root, "exports");
        public string GetTemporaryDirectory() => Path.Combine(root, "tmp");
    }

    private sealed class TestEnvironment(string root) : IWebHostEnvironment
    {
        public string ApplicationName { get; set; } = "AIWordPressManager.Tests";
        public IFileProvider WebRootFileProvider { get; set; } = new NullFileProvider();
        public string WebRootPath { get; set; } = root;
        public string EnvironmentName { get; set; } = "Test";
        public string ContentRootPath { get; set; } = root;
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
    }

    private sealed class TestAccessor(HttpContext context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }

    private static IHttpContextAccessor Accessor(Guid actorId, params string[] permissions)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, actorId.ToString("D")),
            new(ClaimTypes.Name, "health.owner")
        };
        claims.AddRange(permissions.Select(permission =>
            new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
        return new TestAccessor(new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"))
        });
    }
}
