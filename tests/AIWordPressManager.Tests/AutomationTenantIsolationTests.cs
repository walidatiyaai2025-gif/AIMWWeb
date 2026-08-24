using System.Security.Claims;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class AutomationTenantIsolationTests
{
    [Fact]
    public async Task Account_snapshot_contains_only_owned_jobs_and_history()
    {
        await using var fixture = await Fixture.CreateAsync();
        var jobA = fixture.CreateJob(fixture.SiteA, "Owner A automation");
        var jobB = fixture.CreateJob(fixture.SiteB, "Owner B automation");
        await fixture.InsertHistoryAsync(jobA, DateTime.UtcNow.AddMinutes(-10), "owner-a-history");
        await fixture.InsertHistoryAsync(jobB, DateTime.UtcNow.AddMinutes(-5), "owner-b-history");

        var snapshotA = await fixture.ServiceFor(fixture.OwnerA).GetSnapshotAsync(100);
        var snapshotB = await fixture.ServiceFor(fixture.OwnerB).GetSnapshotAsync(100);

        snapshotA.Jobs.Should().ContainSingle(x => x.Id == jobA.Id);
        snapshotA.Jobs.Should().NotContain(x => x.Id == jobB.Id);
        snapshotA.History.Should().ContainSingle(x => x.JobId == jobA.Id && x.Message == "owner-a-history");
        snapshotA.History.Should().NotContain(x => x.JobId == jobB.Id);
        snapshotA.Jobs.Count.Should().Be(1);
        snapshotA.History.Count.Should().Be(1);

        snapshotB.Jobs.Should().ContainSingle(x => x.Id == jobB.Id);
        snapshotB.History.Should().ContainSingle(x => x.JobId == jobB.Id);
    }

    [Fact]
    public async Task History_applies_owner_scope_before_take_limit()
    {
        await using var fixture = await Fixture.CreateAsync();
        var jobA = fixture.CreateJob(fixture.SiteA, "Owner A automation");
        var jobB = fixture.CreateJob(fixture.SiteB, "Owner B automation");
        var baseTime = DateTime.UtcNow.AddHours(-1);

        await fixture.InsertHistoryAsync(jobA, baseTime, "owner-a-retained-row");
        for (var i = 0; i < 8; i++)
            await fixture.InsertHistoryAsync(jobB, baseTime.AddMinutes(i + 1), $"owner-b-{i}");

        fixture.RawAutomation.GetHistory(1).Should().ContainSingle(x => x.JobId == jobB.Id);

        var ownerHistory = await fixture.ServiceFor(fixture.OwnerA).GetHistoryAsync(1);

        ownerHistory.Should().ContainSingle();
        ownerHistory[0].JobId.Should().Be(jobA.Id);
        ownerHistory[0].Message.Should().Be("owner-a-retained-row");
    }

    [Fact]
    public async Task Other_owner_and_unknown_job_ids_fail_with_the_same_not_found_result()
    {
        await using var fixture = await Fixture.CreateAsync();
        var jobA = fixture.CreateJob(fixture.SiteA, "Owner A automation");
        var jobB = fixture.CreateJob(fixture.SiteB, "Owner B automation");
        var serviceA = fixture.ServiceFor(fixture.OwnerA);
        var unknownId = Guid.NewGuid();

        await FluentActions.Awaiting(() => serviceA.SetEnabledAsync(jobB.Id, false))
            .Should().ThrowAsync<KeyNotFoundException>().WithMessage("Automation job was not found.");
        await FluentActions.Awaiting(() => serviceA.SetEnabledAsync(unknownId, false))
            .Should().ThrowAsync<KeyNotFoundException>().WithMessage("Automation job was not found.");

        await FluentActions.Awaiting(() => serviceA.SaveAsync(Model(jobB.Id, fixture.SiteA, "Owner A update")))
            .Should().ThrowAsync<KeyNotFoundException>().WithMessage("Automation job was not found.");
        await FluentActions.Awaiting(() => serviceA.SaveAsync(Model(unknownId, fixture.SiteA, "Owner A update")))
            .Should().ThrowAsync<KeyNotFoundException>().WithMessage("Automation job was not found.");

        fixture.RawAutomation.GetJobs().Should().Contain(x => x.Id == jobA.Id);
        fixture.RawAutomation.GetJobs().Should().Contain(x => x.Id == jobB.Id && x.Name == "Owner B automation");
        fixture.RawAutomation.GetJobs().Should().NotContain(x => x.Id == unknownId);
    }

    [Fact]
    public async Task Other_owner_and_unknown_site_ids_return_the_same_unavailable_result()
    {
        await using var fixture = await Fixture.CreateAsync();
        var serviceA = fixture.ServiceFor(fixture.OwnerA);
        var unknownSite = new Site("Unknown", new Uri("https://unknown.example.test"), DateTime.UtcNow, fixture.OwnerA);

        await FluentActions.Awaiting(() => serviceA.SaveAsync(Model(Guid.Empty, fixture.SiteB, "Other owner site")))
            .Should().ThrowAsync<UnauthorizedAccessException>().WithMessage("The selected site is unavailable.");
        await FluentActions.Awaiting(() => serviceA.SaveAsync(Model(Guid.Empty, unknownSite, "Unknown site")))
            .Should().ThrowAsync<UnauthorizedAccessException>().WithMessage("The selected site is unavailable.");
    }

    [Fact]
    public async Task Valid_owner_create_persists_and_reloads_with_server_site_name()
    {
        await using var fixture = await Fixture.CreateAsync();
        var serviceA = fixture.ServiceFor(fixture.OwnerA);
        var model = Model(Guid.Empty, fixture.SiteA, "Owned persisted job");
        model.SiteName = "caller value";

        var id = await serviceA.SaveAsync(model);
        var reloaded = await fixture.ServiceFor(fixture.OwnerA).GetJobsAsync();

        reloaded.Should().ContainSingle(x => x.Id == id);
        reloaded[0].SiteName.Should().Be(fixture.SiteA.Name);
    }

    [Fact]
    public void Reports_and_api_depend_on_the_account_scoped_boundary()
    {
        var reports = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/ReportsExports.razor");
        var automationCenter = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AutomationCenter.razor");
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        reports.Should().Contain("@inject AccountAutomationCenterService AutomationService");
        reports.Should().Contain("AutomationService.GetJobsAsync()");
        reports.Should().NotContain("@inject AutomationCenterService AutomationService");

        automationCenter.Should().Contain("@inject AccountAutomationCenterService Automation");
        automationCenter.Should().NotContain("new(RawAutomation, Db, CurrentUser, Entitlements)");

        program.Should().Contain("builder.Services.AddScoped<AccountAutomationCenterService>();");
        program.Should().Contain("app.MapGet(\"/api/automations\", async (AccountAutomationCenterService service");
        program.Should().Contain("service.GetSnapshotAsync(100, cancellationToken)");
        program.Should().NotContain("app.MapGet(\"/api/automations\", (AutomationCenterService service)");
    }

    private static AutomationJobEditModel Model(Guid id, Site site, string name) => new()
    {
        Id = id,
        Name = name,
        SiteId = site.Id,
        SiteName = site.Name,
        Type = AutomationCenterService.SynchronizationType,
        Frequency = "daily",
        IntervalValue = 1,
        TimeOfDay = "08:00",
        Enabled = true,
        RetryCount = 0
    };

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _dbConnection;
        private readonly string _root;

        private Fixture(string root, SqliteConnection dbConnection, AppDbContext db, TestPaths paths,
            AutomationCenterService rawAutomation, Guid ownerA, Guid ownerB, Site siteA, Site siteB)
        {
            _root = root;
            _dbConnection = dbConnection;
            Db = db;
            Paths = paths;
            RawAutomation = rawAutomation;
            OwnerA = ownerA;
            OwnerB = ownerB;
            SiteA = siteA;
            SiteB = siteB;
        }

        public AppDbContext Db { get; }
        public TestPaths Paths { get; }
        public AutomationCenterService RawAutomation { get; }
        public Guid OwnerA { get; }
        public Guid OwnerB { get; }
        public Site SiteA { get; }
        public Site SiteB { get; }

        public static async Task<Fixture> CreateAsync()
        {
            var root = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", "automation-tenant", Guid.NewGuid().ToString("N"));
            var paths = new TestPaths(root);
            Directory.CreateDirectory(paths.GetApplicationDataDirectory());
            var automationPath = Path.Combine(paths.GetApplicationDataDirectory(), "automation-center.db");
            await using (var store = new SqliteConnection($"Data Source={automationPath}"))
                await store.OpenAsync();

            var dbConnection = new SqliteConnection("Data Source=:memory:");
            await dbConnection.OpenAsync();
            var db = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(dbConnection).Options);
            await db.Database.EnsureCreatedAsync();

            var ownerA = Guid.NewGuid();
            var ownerB = Guid.NewGuid();
            var siteA = new Site("Owner A site", new Uri("https://owner-a-automation.example.test"), DateTime.UtcNow, ownerA);
            var siteB = new Site("Owner B site", new Uri("https://owner-b-automation.example.test"), DateTime.UtcNow, ownerB);
            db.Sites.AddRange(siteA, siteB);
            await db.SaveChangesAsync();

            return new Fixture(root, dbConnection, db, paths, new AutomationCenterService(paths), ownerA, ownerB, siteA, siteB);
        }

        public AccountAutomationCenterService ServiceFor(Guid ownerId) => new(
            RawAutomation,
            Db,
            new CurrentUserContext(Accessor(ownerId)),
            new AllowAllEntitlements(),
            Paths);

        public AutomationJob CreateJob(Site site, string name)
        {
            var id = RawAutomation.Save(Model(Guid.Empty, site, name));
            return RawAutomation.GetJobs().Single(x => x.Id == id);
        }

        public async Task InsertHistoryAsync(AutomationJob job, DateTime startedAtUtc, string message)
        {
            var databasePath = Path.Combine(Paths.GetApplicationDataDirectory(), "automation-center.db");
            await using var connection = new SqliteConnection($"Data Source={databasePath}");
            await connection.OpenAsync();
            await using var command = connection.CreateCommand();
            command.CommandText = """
                INSERT INTO AutomationHistory (Id,JobId,JobName,StartedAtUtc,FinishedAtUtc,Status,Message)
                VALUES ($id,$jobId,$jobName,$started,$finished,'Completed',$message);
                """;
            command.Parameters.AddWithValue("$id", Guid.NewGuid().ToString("D"));
            command.Parameters.AddWithValue("$jobId", job.Id.ToString("D"));
            command.Parameters.AddWithValue("$jobName", job.Name);
            command.Parameters.AddWithValue("$started", startedAtUtc.ToUniversalTime().ToString("O"));
            command.Parameters.AddWithValue("$finished", startedAtUtc.ToUniversalTime().AddSeconds(1).ToString("O"));
            command.Parameters.AddWithValue("$message", message);
            await command.ExecuteNonQueryAsync();
        }

        public async ValueTask DisposeAsync()
        {
            await Db.DisposeAsync();
            await _dbConnection.DisposeAsync();
            try { Directory.Delete(_root, recursive: true); } catch { }
        }
    }

    private sealed class AllowAllEntitlements : IAccountEntitlementEnforcementService
    {
        public Task RequireBooleanCapabilityAsync(Guid ownerUserId, string entitlementKey, CancellationToken cancellationToken = default) => Task.CompletedTask;
        public Task RequireAdditionalUsageAsync(Guid ownerUserId, string entitlementKey, long currentUsage, long requestedAdditional = 1, CancellationToken cancellationToken = default) => Task.CompletedTask;
    }

    private sealed class TestPaths(string root) : IApplicationPathService
    {
        public string GetApplicationDataDirectory() => Path.Combine(root, "data");
        public string GetDatabasePath() => Path.Combine(root, "application.db");
        public string GetLogsDirectory() => Path.Combine(root, "logs");
        public string GetScreenshotsDirectory() => Path.Combine(root, "screenshots");
        public string GetBackupsDirectory() => Path.Combine(root, "backups");
        public string GetExportsDirectory() => Path.Combine(root, "exports");
        public string GetTemporaryDirectory() => Path.Combine(root, "tmp");
    }

    private sealed class TestAccessor(HttpContext context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }

    private static IHttpContextAccessor Accessor(Guid userId)
    {
        var identity = new ClaimsIdentity(new[]
        {
            new Claim(ClaimTypes.NameIdentifier, userId.ToString("D")),
            new Claim(ClaimTypes.Name, $"automation-{userId:N}"),
            new Claim(ApplicationPermissionCatalog.ClaimType, ApplicationPermissionCatalog.OperationsView)
        }, "Test");
        return new TestAccessor(new DefaultHttpContext { User = new ClaimsPrincipal(identity) });
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);
        while (directory is not null)
        {
            var candidate = Path.Combine(directory.FullName, relativePath.Replace('/', Path.DirectorySeparatorChar));
            if (File.Exists(candidate)) return File.ReadAllText(candidate);
            directory = directory.Parent;
        }
        throw new FileNotFoundException($"Repository file was not found: {relativePath}");
    }
}
