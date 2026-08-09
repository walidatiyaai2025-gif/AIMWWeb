using System.Text.Json;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class SiteOperationHistoryIdentityTests : IDisposable
{
    private readonly string _directory;
    private readonly string _path;
    private readonly SiteOperationHistoryService _service;

    public SiteOperationHistoryIdentityTests()
    {
        _directory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_directory);
        _path = Path.Combine(_directory, "site-operation-history.json");
        _service = new SiteOperationHistoryService(_path);
    }

    [Fact]
    public void Owner_site_and_execution_job_identity_round_trip()
    {
        var owner = Guid.NewGuid();
        var site = Guid.NewGuid();
        var job = Guid.NewGuid();
        var started = DateTime.UtcNow.AddSeconds(-2);

        _service.Record(owner, site, "synchronization", true, "Done", null, started, DateTime.UtcNow, 12, job);

        var item = _service.Get(owner, [site], site).Single();
        item.OwnerUserId.Should().Be(owner);
        item.SiteId.Should().Be(site);
        item.ExecutionJobId.Should().Be(job);
        item.AffectedRecords.Should().Be(12);
    }

    [Fact]
    public void Explicit_owner_isolation_wins_even_when_site_id_is_known()
    {
        var ownerA = Guid.NewGuid();
        var ownerB = Guid.NewGuid();
        var site = Guid.NewGuid();
        _service.Record(ownerB, site, "connection-test", false, "Private failure", "details", DateTime.UtcNow, DateTime.UtcNow);

        _service.GetAll(ownerA, [site], 100).Should().BeEmpty();
        var operationId = _service.GetAll().Single().Id;
        _service.GetById(ownerA, [site], operationId).Should().BeNull();
        _service.GetById(ownerB, [site], operationId).Should().NotBeNull();
    }

    [Fact]
    public void Legacy_ownerless_records_follow_current_owned_site_ids_only()
    {
        var ownedSite = Guid.NewGuid();
        var foreignSite = Guid.NewGuid();
        var legacy = new[]
        {
            new SiteOperationHistoryItem(Guid.NewGuid(), ownedSite, "legacy", true, "owned", null, DateTime.UtcNow, DateTime.UtcNow, null),
            new SiteOperationHistoryItem(Guid.NewGuid(), foreignSite, "legacy", true, "foreign", null, DateTime.UtcNow, DateTime.UtcNow, null)
        };
        File.WriteAllText(_path, JsonSerializer.Serialize(legacy, new JsonSerializerOptions(JsonSerializerDefaults.Web)));
        var owner = Guid.NewGuid();

        var visible = _service.GetAll(owner, [ownedSite], 100);

        visible.Should().ContainSingle(x => x.SiteId == ownedSite);
        visible.Should().NotContain(x => x.SiteId == foreignSite);
    }

    [Fact]
    public void Owner_scoped_cleanup_never_removes_other_tenants_records()
    {
        var ownerA = Guid.NewGuid();
        var ownerB = Guid.NewGuid();
        var siteA = Guid.NewGuid();
        var siteB = Guid.NewGuid();
        var old = DateTime.UtcNow.AddDays(-120);
        _service.Record(ownerA, siteA, "sync", true, "A old", null, old, old.AddSeconds(1));
        _service.Record(ownerB, siteB, "sync", true, "B old", null, old, old.AddSeconds(1));

        var result = _service.Cleanup(ownerA, [siteA], olderThanDays: 30, keepLatest: 0);

        result.RemovedCount.Should().Be(1);
        _service.GetAll(ownerA, [siteA], 100).Should().BeEmpty();
        _service.GetAll(ownerB, [siteB], 100).Should().ContainSingle();
    }

    public void Dispose()
    {
        try { Directory.Delete(_directory, recursive: true); }
        catch { }
    }
}
