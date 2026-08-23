using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ExecutionCenterTests : IDisposable
{
    private readonly string _testDirectory;
    private readonly string _databasePath;
    private readonly ExecutionCenterService _service;

    public ExecutionCenterTests()
    {
        _testDirectory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_testDirectory);
        _databasePath = Path.Combine(_testDirectory, "execution-center.db");
        _service = new ExecutionCenterService(_databasePath);
    }

    [Fact]
    public void Enqueue_PersistsRealTrackedJobAndActivity()
    {
        var ownerUserId = Guid.NewGuid();
        var siteId = Guid.NewGuid();
        var job = _service.Enqueue(ownerUserId, siteId, "Synchronize content", "Synchronization", "Test Site", 25);

        var persisted = _service.GetJobs(ownerUserId).Single(x => x.Id == job.Id);
        persisted.OwnerUserId.Should().Be(ownerUserId);
        persisted.SiteId.Should().Be(siteId);
        persisted.Status.Should().Be("Waiting");
        persisted.ExecutionMode.Should().Be(ExecutionCenterService.TrackedExecutionMode);
        _service.GetActivities(ownerUserId, 100).Should().Contain(x => x.JobId == job.Id && x.Message.Contains("Registered"));
    }

    [Fact]
    public void Owner_scoped_queries_isolate_same_named_sites()
    {
        var ownerA = Guid.NewGuid();
        var ownerB = Guid.NewGuid();
        var jobA = _service.Enqueue(ownerA, Guid.NewGuid(), "A job", "Synchronization", "Shared Name", 2);
        var jobB = _service.Enqueue(ownerB, Guid.NewGuid(), "B job", "Synchronization", "Shared Name", 2);

        _service.GetJobs(ownerA).Select(x => x.Id).Should().Equal(jobA.Id);
        _service.GetJobs(ownerB).Select(x => x.Id).Should().Equal(jobB.Id);
        _service.GetActivities(ownerA, 100).Should().OnlyContain(x => x.JobId == jobA.Id);
        _service.GetActivities(ownerB, 100).Should().OnlyContain(x => x.JobId == jobB.Id);
    }

    [Fact]
    public void Owner_scoped_lifecycle_actions_cannot_mutate_another_tenants_job()
    {
        var ownerA = Guid.NewGuid();
        var ownerB = Guid.NewGuid();
        var job = _service.Enqueue(ownerB, Guid.NewGuid(), "Protected job", "Bulk Update", "Shared Name", 10);

        _service.Cancel(job.Id, ownerA);
        _service.GetJobs(ownerB).Single(x => x.Id == job.Id).Status.Should().Be("Waiting");

        _service.Cancel(job.Id, ownerB);
        _service.GetJobs(ownerB).Single(x => x.Id == job.Id).Status.Should().Be("Cancelled");

        // A tracked job cannot be requeued by changing only its ledger state; the originating
        // runtime would need to recreate the actual work request.
        _service.Retry(job.Id, ownerB);
        _service.GetJobs(ownerB).Single(x => x.Id == job.Id).Status.Should().Be("Cancelled");
    }

    [Fact]
    public void Tracked_cancel_does_not_fabricate_a_retry_queue()
    {
        var job = _service.Enqueue("Bulk update", "Bulk Update", "Test Site", 10);

        _service.Cancel(job.Id);
        _service.GetJobs().Single(x => x.Id == job.Id).Status.Should().Be("Cancelled");

        _service.Retry(job.Id);
        var persisted = _service.GetJobs().Single(x => x.Id == job.Id);
        persisted.Status.Should().Be("Cancelled");
        persisted.Progress.Should().Be(0);
        persisted.ProcessedItems.Should().Be(0);
    }

    [Fact]
    public void Restart_PreservesOwnerAndSiteIdentity()
    {
        var ownerUserId = Guid.NewGuid();
        var siteId = Guid.NewGuid();
        var job = _service.Enqueue(ownerUserId, siteId, "Persistent job", "Test", "Test Site", 5);
        _service.Dispose();
        SqliteConnection.ClearAllPools();

        using var restarted = new ExecutionCenterService(_databasePath);

        var persisted = restarted.GetJobs(ownerUserId).Single(x => x.Id == job.Id);
        persisted.SiteId.Should().Be(siteId);
        persisted.OwnerUserId.Should().Be(ownerUserId);
        persisted.ExecutionMode.Should().Be(ExecutionCenterService.TrackedExecutionMode);
    }

    [Fact]
    public void Legacy_schema_is_upgraded_without_assigning_untrusted_owner_identity_or_fake_execution()
    {
        _service.Dispose();
        SqliteConnection.ClearAllPools();
        File.Delete(_databasePath);
        var legacyJobId = Guid.NewGuid();

        using (var connection = new SqliteConnection($"Data Source={_databasePath}"))
        {
            connection.Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                CREATE TABLE ExecutionCenterJobs (
                    Id TEXT PRIMARY KEY, Title TEXT NOT NULL, Type TEXT NOT NULL, SiteName TEXT NOT NULL,
                    Status TEXT NOT NULL, Progress INTEGER NOT NULL DEFAULT 0, TotalItems INTEGER NOT NULL,
                    ProcessedItems INTEGER NOT NULL DEFAULT 0, CreatedAtUtc TEXT NOT NULL,
                    StartedAtUtc TEXT NULL, CompletedAtUtc TEXT NULL, Error TEXT NULL);
                CREATE TABLE ExecutionCenterActivities (
                    Id TEXT PRIMARY KEY, JobId TEXT NOT NULL, CreatedAtUtc TEXT NOT NULL, Level TEXT NOT NULL,
                    Message TEXT NOT NULL, FOREIGN KEY (JobId) REFERENCES ExecutionCenterJobs(Id) ON DELETE CASCADE);
                INSERT INTO ExecutionCenterJobs
                    (Id,Title,Type,SiteName,Status,Progress,TotalItems,ProcessedItems,CreatedAtUtc)
                VALUES ($id,'Legacy','Test','Shared Name','Completed',100,1,1,$created);
                """;
            command.Parameters.AddWithValue("$id", legacyJobId.ToString());
            command.Parameters.AddWithValue("$created", DateTime.UtcNow.ToString("O"));
            command.ExecuteNonQuery();
        }

        using var upgraded = new ExecutionCenterService(_databasePath);

        var legacy = upgraded.GetJobs().Single(x => x.Id == legacyJobId);
        legacy.OwnerUserId.Should().BeNull();
        legacy.SiteId.Should().BeNull();
        legacy.ExecutionMode.Should().Be(ExecutionCenterService.UnavailableExecutionMode);
        legacy.Status.Should().Be("Failed");
        legacy.Progress.Should().Be(0);
        legacy.Error.Should().Contain("did not represent real production work");
        upgraded.GetJobs(Guid.NewGuid()).Should().BeEmpty();

        using var verify = new SqliteConnection($"Data Source={_databasePath}");
        verify.Open();
        using var pragma = verify.CreateCommand();
        pragma.CommandText = "PRAGMA table_info(ExecutionCenterJobs);";
        using var reader = pragma.ExecuteReader();
        var columns = new List<string>();
        while (reader.Read()) columns.Add(reader.GetString(1));
        columns.Should().Contain(["OwnerUserId", "SiteId", "ExecutionMode"]);
    }

    public void Dispose()
    {
        _service.Dispose();
        SqliteConnection.ClearAllPools();
        TryDeleteDirectory(_testDirectory);
    }

    private static void TryDeleteDirectory(string directory)
    {
        if (!Directory.Exists(directory)) return;

        for (var attempt = 1; attempt <= 20; attempt++)
        {
            try
            {
                SqliteConnection.ClearAllPools();
                GC.Collect();
                GC.WaitForPendingFinalizers();
                GC.Collect();
                Directory.Delete(directory, recursive: true);
                return;
            }
            catch (IOException) when (attempt < 20)
            {
                Thread.Sleep(100);
            }
            catch (UnauthorizedAccessException) when (attempt < 20)
            {
                Thread.Sleep(100);
            }
        }
    }
}
