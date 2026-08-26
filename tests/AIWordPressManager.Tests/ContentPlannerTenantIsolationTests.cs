using System.Security.Claims;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Components.Pages;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ContentPlannerTenantIsolationTests
{
    private static readonly string[] FullPlannerPermissions =
    [
        ApplicationPermissionCatalog.ContentView,
        ApplicationPermissionCatalog.ContentEdit,
        ApplicationPermissionCatalog.OperationsExecute
    ];

    [Fact]
    public void Planner_route_requires_content_view()
    {
        ApplicationRoutePermissionCatalog.For(typeof(ContentPlanner))
            .Should().Be(ApplicationPermissionCatalog.ContentView);
    }

    [Fact]
    public async Task Two_accounts_cannot_read_or_mutate_each_others_planner_rows_by_guessed_id()
    {
        await using var fixture = await PlannerFixture.CreateAsync();
        var ownerA = fixture.ServiceFor(fixture.OwnerA, "owner.a", FullPlannerPermissions);
        var ownerB = fixture.ServiceFor(fixture.OwnerB, "owner.b", FullPlannerPermissions);

        var itemA = await ownerA.CreateAsync(new CreatePlannerItem(
            fixture.SiteA.Id, "forged other-site name", "Owner A plan", "A idea", null, "forged-creator"));
        var itemB = await ownerB.CreateAsync(new CreatePlannerItem(
            fixture.SiteB.Id, "forged A name", "Owner B secret plan", "B idea", null, "forged-creator"));

        ownerA.GetItems().Should().ContainSingle(x => x.Id == itemA.Id);
        ownerA.GetItems().Should().NotContain(x => x.Id == itemB.Id);
        ownerA.Get(itemB.Id).Should().BeNull("cross-owner IDs must be indistinguishable from missing IDs");
        ownerA.Get(Guid.NewGuid()).Should().BeNull();

        var updateOther = () => ownerA.UpdateAsync(
            itemB.Id,
            new UpdatePlannerItem("stolen", null, null, null, null, null, null));
        await updateOther.Should().ThrowAsync<InvalidOperationException>()
            .WithMessage("Planner item not found.");

        var aiOther = () => ownerA.GenerateBriefAsync(itemB.Id, "en");
        await aiOther.Should().ThrowAsync<InvalidOperationException>()
            .WithMessage("Planner item not found.");

        var queueOther = () => ownerA.QueueForExecutionAsync(itemB.Id);
        await queueOther.Should().ThrowAsync<InvalidOperationException>()
            .WithMessage("Planner item not found.");

        fixture.Ai.Requests.Should().BeEmpty("cross-owner lookups must fail before AI execution");
        fixture.Execution.GetJobs(fixture.OwnerA).Should().BeEmpty("guessed IDs must not create execution metadata");
        fixture.Execution.GetJobs(fixture.OwnerB).Should().BeEmpty();

        // ReportsExports consumes PlannerService.GetItems(); the account-scoped projection therefore
        // drives its planner counts and CSV rows without a separate global planner read path.
        var reportProjection = ownerA.GetItems();
        reportProjection.Should().HaveCount(1);
        reportProjection.Select(x => x.Title).Should().Contain("Owner A plan").And.NotContain("Owner B secret plan");
    }

    [Fact]
    public async Task Create_resolves_site_and_actor_server_side_and_does_not_reveal_cross_owner_site_existence()
    {
        await using var fixture = await PlannerFixture.CreateAsync();
        var ownerA = fixture.ServiceFor(fixture.OwnerA, "owner.a", FullPlannerPermissions);

        var crossOwner = () => ownerA.CreateAsync(new CreatePlannerItem(
            fixture.SiteB.Id, "spoofed", "bad", null, null, "spoofed"));
        var unknown = () => ownerA.CreateAsync(new CreatePlannerItem(
            Guid.NewGuid(), "spoofed", "bad", null, null, "spoofed"));

        await crossOwner.Should().ThrowAsync<InvalidOperationException>().WithMessage("Site is unavailable.");
        await unknown.Should().ThrowAsync<InvalidOperationException>().WithMessage("Site is unavailable.");

        var created = await ownerA.CreateAsync(new CreatePlannerItem(
            fixture.SiteA.Id,
            "Other tenant display name",
            "Owned item",
            "idea",
            null,
            "attacker supplied actor"));

        created.SiteId.Should().Be(fixture.SiteA.Id);
        created.SiteName.Should().Be(fixture.SiteA.Name);
        created.CreatedBy.Should().Be("owner.a");
    }

    [Fact]
    public async Task Planner_permissions_fail_closed_for_anonymous_underprivileged_and_read_only_users()
    {
        await using var fixture = await PlannerFixture.CreateAsync();
        var anonymous = fixture.AnonymousService();
        var underprivileged = fixture.ServiceFor(
            fixture.OwnerA,
            "underprivileged",
            ApplicationPermissionCatalog.SitesView);
        var readOnly = fixture.ServiceFor(
            fixture.OwnerA,
            "reader",
            ApplicationPermissionCatalog.ContentView);

        var anonymousRead = () => Task.Run(() => anonymous.GetItems());
        await anonymousRead.Should().ThrowAsync<UnauthorizedAccessException>();

        var underprivilegedRead = () => Task.Run(() => underprivileged.GetItems());
        await underprivilegedRead.Should().ThrowAsync<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.ContentView}*");

        var readOnlyMutation = () => readOnly.CreateAsync(new CreatePlannerItem(
            fixture.SiteA.Id, null, "not allowed", null, null, null));
        await readOnlyMutation.Should().ThrowAsync<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.ContentEdit}*");
    }

    [Fact]
    public async Task AI_generation_uses_authenticated_owner_identity_and_owned_site_only()
    {
        await using var fixture = await PlannerFixture.CreateAsync();
        var ownerA = fixture.ServiceFor(fixture.OwnerA, "owner.a", FullPlannerPermissions);
        var item = await ownerA.CreateAsync(new CreatePlannerItem(
            fixture.SiteA.Id, null, "AI plan", "Write safely", null, null));

        var updated = await ownerA.GenerateBriefAsync(item.Id, "en");

        updated.Status.Should().Be(PlannerItemStatus.Brief);
        updated.Brief.Should().Be("generated-content");
        fixture.Ai.Requests.Should().ContainSingle();
        fixture.Ai.Requests[0].UserId.Should().Be(fixture.OwnerA.ToString("D"));
        fixture.Ai.Requests[0].SiteId.Should().Be(fixture.SiteA.Id);

        var audit = await new ApplicationSecurityAuditStore(fixture.Db).ListAsync();
        audit.Should().Contain(x =>
            x.Category == "ContentPlanner" &&
            x.Action == "GenerateBrief" &&
            x.Outcome == "Succeeded" &&
            x.ActorUserId == fixture.OwnerA &&
            x.TargetId == item.Id.ToString("D"));
    }

    [Fact]
    public async Task Queue_requires_owned_site_and_preserves_owner_identity_in_execution_ledger()
    {
        await using var fixture = await PlannerFixture.CreateAsync();
        var ownerA = fixture.ServiceFor(fixture.OwnerA, "owner.a", FullPlannerPermissions);
        var siteItem = await ownerA.CreateAsync(new CreatePlannerItem(
            fixture.SiteA.Id, null, "Queued plan", "idea", null, null));
        siteItem = await ownerA.UpdateAsync(
            siteItem.Id,
            new UpdatePlannerItem(null, PlannerItemStatus.Draft, null, null, "<p>draft</p>", null, null));

        await ownerA.QueueForExecutionAsync(siteItem.Id);

        fixture.Execution.GetJobs(fixture.OwnerA).Should().ContainSingle(job =>
            job.SiteId == fixture.SiteA.Id && job.Type == "Planner Publish");
        fixture.Execution.GetJobs(fixture.OwnerB).Should().BeEmpty();

        var generalItem = await ownerA.CreateAsync(new CreatePlannerItem(
            null, null, "General plan", "idea", null, null));
        generalItem = await ownerA.UpdateAsync(
            generalItem.Id,
            new UpdatePlannerItem(null, PlannerItemStatus.Draft, null, null, "<p>draft</p>", null, null));
        var queueGeneral = () => ownerA.QueueForExecutionAsync(generalItem.Id);
        await queueGeneral.Should().ThrowAsync<InvalidOperationException>()
            .WithMessage("*owned site*");
    }

    [Fact]
    public async Task Owner_scope_survives_service_reload()
    {
        await using var fixture = await PlannerFixture.CreateAsync();
        var ownerA = fixture.ServiceFor(fixture.OwnerA, "owner.a", FullPlannerPermissions);
        var ownerB = fixture.ServiceFor(fixture.OwnerB, "owner.b", FullPlannerPermissions);
        var itemA = await ownerA.CreateAsync(new CreatePlannerItem(fixture.SiteA.Id, null, "Persisted A", null, null, null));
        await ownerB.CreateAsync(new CreatePlannerItem(fixture.SiteB.Id, null, "Persisted B", null, null, null));

        var reloadedOwnerA = fixture.ServiceFor(fixture.OwnerA, "owner.a", FullPlannerPermissions);
        var afterReload = reloadedOwnerA.GetItems();

        afterReload.Should().ContainSingle();
        afterReload[0].Id.Should().Be(itemA.Id);
        afterReload[0].Title.Should().Be("Persisted A");
    }

    [Fact]
    public async Task Legacy_rows_are_migrated_to_nullable_owner_column_but_never_silently_claimed()
    {
        await using var fixture = await PlannerFixture.CreateAsync(createPlannerDatabase: false);
        var plannerPath = Path.Combine(fixture.Paths.GetApplicationDataDirectory(), "content-planner.db");
        Directory.CreateDirectory(Path.GetDirectoryName(plannerPath)!);
        var legacyId = Guid.NewGuid();

        await using (var legacy = new SqliteConnection($"Data Source={plannerPath}"))
        {
            await legacy.OpenAsync();
            await using var command = legacy.CreateCommand();
            command.CommandText = """
                CREATE TABLE PlannerItems(
                  Id TEXT PRIMARY KEY, SiteId TEXT NULL, SiteName TEXT NOT NULL, Title TEXT NOT NULL,
                  Status TEXT NOT NULL, Idea TEXT NULL, Brief TEXT NULL, DraftContent TEXT NULL,
                  ScheduledAtUtc TEXT NULL, WordPressPostId INTEGER NULL, CreatedAtUtc TEXT NOT NULL,
                  UpdatedAtUtc TEXT NOT NULL, CreatedBy TEXT NOT NULL);
                INSERT INTO PlannerItems
                (Id,SiteId,SiteName,Title,Status,Idea,Brief,DraftContent,ScheduledAtUtc,WordPressPostId,CreatedAtUtc,UpdatedAtUtc,CreatedBy)
                VALUES ($id,NULL,'','Legacy tenant-unknown item','Idea',NULL,NULL,NULL,NULL,NULL,$now,$now,'legacy');
                """;
            command.Parameters.AddWithValue("$id", legacyId.ToString("D"));
            command.Parameters.AddWithValue("$now", DateTime.UtcNow.ToString("O"));
            await command.ExecuteNonQueryAsync();
        }

        var ownerA = fixture.ServiceFor(fixture.OwnerA, "owner.a", FullPlannerPermissions);
        ownerA.GetItems().Should().BeEmpty("legacy ownerless rows must not be assigned to the first authenticated account");
        ownerA.Get(legacyId).Should().BeNull();

        await using var verify = new SqliteConnection($"Data Source={plannerPath}");
        await verify.OpenAsync();
        await using var columns = verify.CreateCommand();
        columns.CommandText = "PRAGMA table_info(PlannerItems);";
        await using var reader = await columns.ExecuteReaderAsync();
        var hasOwner = false;
        while (await reader.ReadAsync())
            hasOwner |= string.Equals(reader.GetString(1), "OwnerUserId", StringComparison.OrdinalIgnoreCase);
        hasOwner.Should().BeTrue();

        await reader.DisposeAsync();
        await using var ownerValue = verify.CreateCommand();
        ownerValue.CommandText = "SELECT OwnerUserId FROM PlannerItems WHERE Id=$id;";
        ownerValue.Parameters.AddWithValue("$id", legacyId.ToString("D"));
        (await ownerValue.ExecuteScalarAsync()).Should().Be(DBNull.Value);
    }

    private sealed class PlannerFixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;
        private readonly string _root;

        private PlannerFixture(
            string root,
            SqliteConnection connection,
            AppDbContext db,
            TestPaths paths,
            RecordingAi ai,
            ExecutionCenterService execution,
            NotificationInboxService notifications,
            Guid ownerA,
            Guid ownerB,
            Site siteA,
            Site siteB)
        {
            _root = root;
            _connection = connection;
            Db = db;
            Paths = paths;
            Ai = ai;
            Execution = execution;
            Notifications = notifications;
            OwnerA = ownerA;
            OwnerB = ownerB;
            SiteA = siteA;
            SiteB = siteB;
        }

        public AppDbContext Db { get; }
        public TestPaths Paths { get; }
        public RecordingAi Ai { get; }
        public ExecutionCenterService Execution { get; }
        public NotificationInboxService Notifications { get; }
        public Guid OwnerA { get; }
        public Guid OwnerB { get; }
        public Site SiteA { get; }
        public Site SiteB { get; }

        public static async Task<PlannerFixture> CreateAsync(bool createPlannerDatabase = true)
        {
            var root = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", "planner", Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(root);
            var paths = new TestPaths(root);
            Directory.CreateDirectory(paths.GetApplicationDataDirectory());
            if (!createPlannerDatabase)
            {
                var existing = Path.Combine(paths.GetApplicationDataDirectory(), "content-planner.db");
                if (File.Exists(existing)) File.Delete(existing);
            }

            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var db = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await db.Database.EnsureCreatedAsync();

            var ownerA = Guid.NewGuid();
            var ownerB = Guid.NewGuid();
            var siteA = new Site("Owner A authoritative site", new Uri("https://owner-a.example.test"), DateTime.UtcNow, ownerA);
            var siteB = new Site("Owner B authoritative site", new Uri("https://owner-b.example.test"), DateTime.UtcNow, ownerB);
            db.Sites.AddRange(siteA, siteB);
            await db.SaveChangesAsync();

            var ai = new RecordingAi();
            var execution = new ExecutionCenterService(Path.Combine(root, "execution.db"));
            var notifications = NotificationInboxService.ForDatabase(Path.Combine(root, "notifications.db"));
            return new PlannerFixture(root, connection, db, paths, ai, execution, notifications, ownerA, ownerB, siteA, siteB);
        }

        public ContentPlannerService ServiceFor(Guid ownerId, string userName, params string[] permissions)
        {
            var currentUser = new CurrentUserContext(Accessor(ownerId, userName, permissions));
            var audit = new ApplicationSecurityAuditService(Db, currentUser);
            return new ContentPlannerService(
                Ai,
                new TestPromptRegistry(),
                Execution,
                Notifications,
                currentUser,
                Paths,
                Db,
                audit);
        }

        public ContentPlannerService AnonymousService()
        {
            var currentUser = new CurrentUserContext(new TestAccessor(new DefaultHttpContext()));
            return new ContentPlannerService(
                Ai,
                new TestPromptRegistry(),
                Execution,
                Notifications,
                currentUser,
                Paths,
                Db,
                new ApplicationSecurityAuditService(Db, currentUser));
        }

        public async ValueTask DisposeAsync()
        {
            Execution.Dispose();
            await Db.DisposeAsync();
            await _connection.DisposeAsync();
            try { Directory.Delete(_root, recursive: true); } catch { }
        }
    }

    private sealed class RecordingAi : IAIOrchestrator
    {
        public List<AIRequest> Requests { get; } = [];

        public Task<AIResponse> ExecuteAsync(AIRequest request, CancellationToken cancellationToken = default)
        {
            Requests.Add(request);
            return Task.FromResult(new AIResponse(
                true,
                "generated-content",
                "test-provider",
                "test-model",
                10,
                20,
                0m));
        }
    }

    private sealed class TestPromptRegistry : IAIPromptRegistry
    {
        public string Get(string key, string culture = "en") => "test system prompt";
        public IReadOnlyDictionary<string, string> GetAll(string culture = "en") =>
            new Dictionary<string, string> { ["content-brief"] = "test system prompt" };
    }

    public sealed class TestPaths(string root) : IApplicationPathService
    {
        public string GetApplicationDataDirectory() => Path.Combine(root, "data");
        public string GetDatabasePath() => Path.Combine(root, "database.db");
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

    private static IHttpContextAccessor Accessor(Guid actorId, string userName, params string[] permissions)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, actorId.ToString("D")),
            new(ClaimTypes.Name, userName)
        };
        claims.AddRange(permissions.Select(permission =>
            new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
        return new TestAccessor(new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"))
        });
    }
}
