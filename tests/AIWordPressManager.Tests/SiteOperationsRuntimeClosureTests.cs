using System.Security.Claims;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Components.Pages;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class SiteOperationsRuntimeClosureTests
{
    [Fact]
    public void Site_operations_routes_are_permission_mapped_fail_closed()
    {
        ApplicationRoutePermissionCatalog.For(typeof(SiteOperationsHub)).Should().Be(ApplicationPermissionCatalog.OperationsView);
        ApplicationRoutePermissionCatalog.For(typeof(SiteOperationsOverview)).Should().Be(ApplicationPermissionCatalog.OperationsView);
        ApplicationRoutePermissionCatalog.For(typeof(SiteReliability)).Should().Be(ApplicationPermissionCatalog.OperationsView);
        ApplicationRoutePermissionCatalog.For(typeof(SiteOperationDetails)).Should().Be(ApplicationPermissionCatalog.OperationsView);
        ApplicationRoutePermissionCatalog.For(typeof(SiteOperationsMaintenance)).Should().Be(ApplicationPermissionCatalog.OperationsExecute);
    }

    [Fact]
    public async Task Maintenance_service_rejects_view_only_before_site_or_storage_work()
    {
        var currentUser = CurrentUser(ApplicationPermissionCatalog.OperationsView);
        var service = new SiteOperationMaintenanceService(
            null!,
            null!,
            currentUser,
            null!,
            NullLogger<SiteOperationMaintenanceService>.Instance);

        var action = () => service.GetSnapshotAsync(90, 100);

        await action.Should().ThrowAsync<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.OperationsExecute}*");
    }

    [Fact]
    public void Corrupt_history_fails_closed_instead_of_becoming_empty_state()
    {
        using var fixture = HistoryFixture.Create();
        File.WriteAllText(fixture.Path, "{ definitely-not-valid-json");
        var owner = Guid.NewGuid();
        var site = Guid.NewGuid();

        var action = () => fixture.Service.GetAll(owner, [site], 100);

        action.Should().Throw<InvalidDataException>()
            .WithMessage("*No records were treated as empty*");
    }

    [Fact]
    public void Separate_history_service_instances_serialize_mutations_without_lost_records()
    {
        using var fixture = HistoryFixture.Create();
        var second = new SiteOperationHistoryService(fixture.Path);
        var owner = Guid.NewGuid();
        var site = Guid.NewGuid();
        var started = DateTime.UtcNow.AddMinutes(-1);

        Parallel.Invoke(
            () => RecordRange(fixture.Service, owner, site, started, "A", 25),
            () => RecordRange(second, owner, site, started, "B", 25));

        fixture.Service.GetAll(owner, [site], 100).Should().HaveCount(50);
    }

    [Fact]
    public async Task Cleanup_requires_execute_scope_preserves_other_owner_and_records_audit_outcome()
    {
        await using var fixture = await RuntimeFixture.CreateAsync();
        var old = DateTime.UtcNow.AddDays(-120);
        fixture.History.Record(fixture.OwnerId, fixture.SiteId, "sync", true, "owner old", null, old, old.AddSeconds(1));
        fixture.History.Record(fixture.OtherOwnerId, fixture.OtherSiteId, "sync", true, "other old", null, old, old.AddSeconds(1));

        var result = await fixture.Maintenance.CleanupAsync(olderThanDays: 30, keepLatest: 0);

        result.Cleanup.RemovedCount.Should().Be(1);
        result.CompletionAuditRecorded.Should().BeTrue();
        fixture.History.GetAll(fixture.OwnerId, [fixture.SiteId], 100).Should().BeEmpty();
        fixture.History.GetAll(fixture.OtherOwnerId, [fixture.OtherSiteId], 100).Should().ContainSingle();

        var audit = await fixture.AuditStore.ListAsync(new SecurityAuditQuery(Category: "SiteOperations", Action: "HistoryCleanup", Take: 20));
        audit.Select(x => x.Outcome).Should().Contain(["Requested", "Succeeded"]);
        audit.Select(x => x.TargetId).Distinct().Should().ContainSingle();
        audit.Single(x => x.Outcome == "Succeeded").Metadata["removedCount"].Should().Be("1");
    }

    [Fact]
    public void Web_host_registration_source_keeps_maintenance_and_audit_services_declared()
    {
        // This is a narrow regression guard only. Runtime/browser acceptance remains required
        // before the ledger can move to a terminal browser-verified status.
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        program.Should().Contain("builder.Services.AddScoped<ApplicationSecurityAuditStore>();");
        program.Should().Contain("builder.Services.AddScoped<ApplicationSecurityAuditService>();");
        program.Should().Contain("builder.Services.AddScoped<SiteOperationMaintenanceService>();");
    }

    private static void RecordRange(
        SiteOperationHistoryService history,
        Guid owner,
        Guid site,
        DateTime started,
        string prefix,
        int count)
    {
        for (var i = 0; i < count; i++)
            history.Record(owner, site, "sync", true, $"{prefix}-{i}", null, started.AddSeconds(i), started.AddSeconds(i + 1));
    }

    private static CurrentUserContext CurrentUser(params string[] permissions) =>
        new(Accessor(Guid.NewGuid(), permissions));

    private static IHttpContextAccessor Accessor(Guid actorId, params string[] permissions)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, actorId.ToString("D")),
            new(ClaimTypes.Name, "operations.test")
        };
        claims.AddRange(permissions.Select(permission =>
            new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test")),
            TraceIdentifier = "site-operations-test"
        };
        return new TestAccessor(context);
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
                return File.ReadAllText(Path.Combine(current.FullName, relativePath));
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate repository root from test output.");
    }

    private sealed class HistoryFixture : IDisposable
    {
        public string DirectoryPath { get; }
        public string Path { get; }
        public SiteOperationHistoryService Service { get; }

        private HistoryFixture(string directoryPath, string path)
        {
            DirectoryPath = directoryPath;
            Path = path;
            Service = new SiteOperationHistoryService(path);
        }

        public static HistoryFixture Create()
        {
            var directory = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
            System.IO.Directory.CreateDirectory(directory);
            return new HistoryFixture(directory, System.IO.Path.Combine(directory, "site-operation-history.json"));
        }

        public void Dispose()
        {
            try { System.IO.Directory.Delete(DirectoryPath, recursive: true); }
            catch { }
        }
    }

    private sealed class RuntimeFixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        private readonly string _directory;
        public Guid OwnerId { get; }
        public Guid OtherOwnerId { get; }
        public Guid SiteId { get; }
        public Guid OtherSiteId { get; }
        public AppDbContext Context { get; }
        public SiteOperationHistoryService History { get; }
        public ApplicationSecurityAuditStore AuditStore { get; }
        public SiteOperationMaintenanceService Maintenance { get; }

        private RuntimeFixture(
            SqliteConnection connection,
            string directory,
            Guid ownerId,
            Guid otherOwnerId,
            Guid siteId,
            Guid otherSiteId,
            AppDbContext context,
            SiteOperationHistoryService history,
            ApplicationSecurityAuditStore auditStore,
            SiteOperationMaintenanceService maintenance)
        {
            _connection = connection;
            _directory = directory;
            OwnerId = ownerId;
            OtherOwnerId = otherOwnerId;
            SiteId = siteId;
            OtherSiteId = otherSiteId;
            Context = context;
            History = history;
            AuditStore = auditStore;
            Maintenance = maintenance;
        }

        public static async Task<RuntimeFixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();

            var ownerId = Guid.NewGuid();
            var otherOwnerId = Guid.NewGuid();
            var ownerSite = new Site("Owner site", new Uri("https://owner.example"), DateTime.UtcNow, ownerId);
            var otherSite = new Site("Other site", new Uri("https://other.example"), DateTime.UtcNow, otherOwnerId);
            context.Sites.AddRange(ownerSite, otherSite);
            await context.SaveChangesAsync();

            var accessor = Accessor(ownerId, ApplicationPermissionCatalog.OperationsExecute);
            var currentUser = new CurrentUserContext(accessor);
            var siteService = new SiteWebService(context, null!, null!, currentUser, null!);
            var directory = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
            System.IO.Directory.CreateDirectory(directory);
            var history = new SiteOperationHistoryService(System.IO.Path.Combine(directory, "site-operation-history.json"));
            var auditStore = new ApplicationSecurityAuditStore(context);
            var auditService = new ApplicationSecurityAuditService(context, currentUser, accessor, auditStore);
            var maintenance = new SiteOperationMaintenanceService(
                history,
                siteService,
                currentUser,
                auditService,
                NullLogger<SiteOperationMaintenanceService>.Instance);

            return new RuntimeFixture(
                connection,
                directory,
                ownerId,
                otherOwnerId,
                ownerSite.Id,
                otherSite.Id,
                context,
                history,
                auditStore,
                maintenance);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await _connection.DisposeAsync();
            try { System.IO.Directory.Delete(_directory, recursive: true); }
            catch { }
        }
    }

    private sealed class TestAccessor(HttpContext context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }
}
