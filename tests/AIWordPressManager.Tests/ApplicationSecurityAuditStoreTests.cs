using System.Text.Json;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class ApplicationSecurityAuditStoreTests
{
    [Fact]
    public async Task Append_persists_and_reload_returns_latest_event()
    {
        await using var fixture = await Fixture.CreateAsync();
        var actorId = Guid.NewGuid();

        var created = await fixture.Store.AppendAsync(new SecurityAuditEvent(
            "Account",
            "User.Disabled",
            "Succeeded",
            actorId,
            "admin",
            "ApplicationUser",
            Guid.NewGuid().ToString("D"),
            "alice",
            "trace-123",
            "10.0.0.4",
            "Browser",
            new Dictionary<string, string> { ["reason"] = "Administrative action" }));

        await using var reloadContext = fixture.CreateContext();
        var reloaded = new ApplicationSecurityAuditStore(reloadContext);
        var events = await reloaded.ListAsync();

        events.Should().ContainSingle();
        events[0].EventId.Should().Be(created.EventId);
        events[0].ActorUserId.Should().Be(actorId);
        events[0].Metadata["reason"].Should().Be("Administrative action");
    }

    [Fact]
    public async Task Append_drops_sensitive_metadata_before_serialization()
    {
        await using var fixture = await Fixture.CreateAsync();

        await fixture.Store.AppendAsync(new SecurityAuditEvent(
            "Account",
            "Password.Reset",
            "Succeeded",
            Guid.NewGuid(),
            "admin",
            "ApplicationUser",
            Guid.NewGuid().ToString("D"),
            "alice",
            Metadata: new Dictionary<string, string>
            {
                ["Password"] = "NeverPersistMe-1",
                ["api_token"] = "NeverPersistMe-2",
                ["AuthorizationHeader"] = "NeverPersistMe-3",
                ["role"] = "User"
            }));

        var raw = await fixture.Context.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == ApplicationSecurityAuditStore.SettingsKey)
            .Select(x => x.Value)
            .SingleAsync();
        raw.Contains("NeverPersistMe", StringComparison.Ordinal).Should().BeFalse();

        var record = (await fixture.Store.ListAsync()).Single();
        record.Metadata.Should().ContainKey("role");
        record.Metadata.Keys.Should().NotContain(key => key.Contains("password", StringComparison.OrdinalIgnoreCase));
        record.Metadata.Keys.Should().NotContain(key => key.Contains("token", StringComparison.OrdinalIgnoreCase));
        record.Metadata.Keys.Should().NotContain(key => key.Contains("authorization", StringComparison.OrdinalIgnoreCase));
    }

    [Fact]
    public async Task Corrupt_registry_fails_closed_and_is_not_overwritten()
    {
        await using var fixture = await Fixture.CreateAsync();
        const string broken = "{broken-json";
        fixture.Context.ApplicationSettings.Add(new ApplicationSetting(ApplicationSecurityAuditStore.SettingsKey, broken, DateTime.UtcNow));
        await fixture.Context.SaveChangesAsync();

        var action = () => fixture.Store.AppendAsync(new SecurityAuditEvent(
            "Session", "Session.Revoked", "Succeeded", null, null, "Session"));

        await action.Should().ThrowAsync<InvalidOperationException>();
        var value = await fixture.Context.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == ApplicationSecurityAuditStore.SettingsKey)
            .Select(x => x.Value)
            .SingleAsync();
        value.Should().Be(broken);
    }

    [Fact]
    public async Task List_filters_by_category_outcome_actor_and_search()
    {
        await using var fixture = await Fixture.CreateAsync();
        var actorId = Guid.NewGuid();
        await fixture.Store.AppendAsync(new SecurityAuditEvent("Account", "User.Created", "Succeeded", actorId, "admin", "ApplicationUser", "1", "alice"));
        await fixture.Store.AppendAsync(new SecurityAuditEvent("Session", "Session.Revoked", "Succeeded", actorId, "admin", "Session", "2", "bob browser"));
        await fixture.Store.AppendAsync(new SecurityAuditEvent("Authentication", "SignIn", "Failed", null, "unknown", "ApplicationUser", "", "charlie"));

        (await fixture.Store.ListAsync(new SecurityAuditQuery(Category: "Session"))).Should().ContainSingle(x => x.Action == "Session.Revoked");
        (await fixture.Store.ListAsync(new SecurityAuditQuery(Outcome: "Failed"))).Should().ContainSingle(x => x.TargetDisplayName == "charlie");
        (await fixture.Store.ListAsync(new SecurityAuditQuery(ActorUserId: actorId))).Should().HaveCount(2);
        (await fixture.Store.ListAsync(new SecurityAuditQuery(Search: "bob"))).Should().ContainSingle(x => x.TargetId == "2");
    }

    [Fact]
    public async Task Concurrent_appends_from_separate_contexts_do_not_lose_events()
    {
        await using var fixture = await Fixture.CreateAsync();
        var tasks = Enumerable.Range(0, 8).Select(async index =>
        {
            await using var context = fixture.CreateContext();
            var store = new ApplicationSecurityAuditStore(context);
            await store.AppendAsync(new SecurityAuditEvent(
                "Concurrency",
                "Append",
                "Succeeded",
                null,
                null,
                "Test",
                index.ToString()));
        });

        await Task.WhenAll(tasks);

        await using var verifyContext = fixture.CreateContext();
        var events = await new ApplicationSecurityAuditStore(verifyContext)
            .ListAsync(new SecurityAuditQuery(Category: "Concurrency", Take: 20));
        events.Should().HaveCount(8);
        events.Select(x => x.TargetId).Should().BeEquivalentTo(Enumerable.Range(0, 8).Select(x => x.ToString()));
    }

    [Fact]
    public async Task Mutation_prunes_expired_records_and_caps_registry()
    {
        await using var fixture = await Fixture.CreateAsync();
        var now = DateTime.UtcNow;
        var records = Enumerable.Range(0, 10_001)
            .Select(index => new
            {
                eventId = Guid.NewGuid(),
                occurredAtUtc = index == 0 ? now.AddDays(-366) : now.AddMinutes(-index),
                category = "Test",
                action = "Seed",
                outcome = "Succeeded",
                actorUserId = (Guid?)null,
                actorUserName = "",
                targetType = "Test",
                targetId = index.ToString(),
                targetDisplayName = "",
                correlationId = "",
                ipAddress = "",
                userAgent = "",
                metadata = new Dictionary<string, string>()
            })
            .ToArray();
        var payload = JsonSerializer.Serialize(new { version = 1, events = records }, new JsonSerializerOptions(JsonSerializerDefaults.Web));
        fixture.Context.ApplicationSettings.Add(new ApplicationSetting(ApplicationSecurityAuditStore.SettingsKey, payload, now));
        await fixture.Context.SaveChangesAsync();

        await fixture.Store.AppendAsync(new SecurityAuditEvent("Test", "Append", "Succeeded", null, null, "Test"));

        var retained = await fixture.Store.ListAsync(new SecurityAuditQuery(Take: 500));
        retained.Should().Contain(x => x.Action == "Append");
        var raw = await fixture.Context.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == ApplicationSecurityAuditStore.SettingsKey)
            .Select(x => x.Value)
            .SingleAsync();
        using var document = JsonDocument.Parse(raw);
        document.RootElement.GetProperty("events").GetArrayLength().Should().BeLessThanOrEqualTo(10_000);
        raw.Contains(records[0].eventId.ToString("D"), StringComparison.OrdinalIgnoreCase).Should().BeFalse();
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private readonly SqliteConnection _keeper;
        private readonly string _connectionString;
        public AppDbContext Context { get; }
        public ApplicationSecurityAuditStore Store { get; }

        private Fixture(SqliteConnection keeper, string connectionString, AppDbContext context)
        {
            _keeper = keeper;
            _connectionString = connectionString;
            Context = context;
            Store = new ApplicationSecurityAuditStore(context);
        }

        public static async Task<Fixture> CreateAsync()
        {
            var name = $"audit-{Guid.NewGuid():N}";
            var connectionString = $"Data Source={name};Mode=Memory;Cache=Shared";
            var keeper = new SqliteConnection(connectionString);
            await keeper.OpenAsync();
            var context = CreateContext(connectionString);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(keeper, connectionString, context);
        }

        public AppDbContext CreateContext() => CreateContext(_connectionString);

        private static AppDbContext CreateContext(string connectionString) =>
            new(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connectionString).Options);

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await _keeper.DisposeAsync();
        }
    }
}
