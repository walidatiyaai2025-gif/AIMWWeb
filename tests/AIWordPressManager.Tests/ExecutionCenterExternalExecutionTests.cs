using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ExecutionCenterExternalExecutionTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
    private readonly string _databasePath;

    public ExecutionCenterExternalExecutionTests()
    {
        Directory.CreateDirectory(_directory);
        _databasePath = Path.Combine(_directory, "execution-center.db");
    }

    [Fact]
    public void External_enqueue_persists_identity_idempotency_and_correlation()
    {
        using var service = NewService();
        var owner = Guid.NewGuid();
        var site = Guid.NewGuid();

        var first = service.EnqueueExternal(owner, site, "Approved change", "WordPress.Content.Update", "Site", "idem-1", "corr-1");
        var duplicate = service.EnqueueExternal(owner, site, "Approved change", "WordPress.Content.Update", "Site", "idem-1", "corr-2");

        duplicate.Id.Should().Be(first.Id);
        var persisted = service.GetJobs(owner).Single(x => x.Id == first.Id);
        persisted.ExecutionMode.Should().Be(ExecutionCenterService.ExternalExecutionMode);
        persisted.Status.Should().Be("Waiting");
        persisted.IdempotencyKey.Should().Be("idem-1");
        persisted.CorrelationId.Should().Be("corr-1");
        persisted.SiteId.Should().Be(site);
        service.GetPendingExternalJobs().Should().ContainSingle(x => x.Id == first.Id);
    }

    [Fact]
    public void External_lifecycle_requires_explicit_start_and_completion()
    {
        using var service = NewService();
        var owner = Guid.NewGuid();
        var job = service.EnqueueExternal(owner, Guid.NewGuid(), "Approved change", "WordPress.Content.Update", "Site", "idem-2", "corr-2");

        service.TryStartExternal(job.Id, owner).Should().BeTrue();
        service.GetJobs(owner).Single(x => x.Id == job.Id).Status.Should().Be("Running");

        service.CompleteExternal(job.Id, owner, "Remote mutation succeeded.");
        var completed = service.GetJobs(owner).Single(x => x.Id == job.Id);
        completed.Status.Should().Be("Completed");
        completed.Progress.Should().Be(100);
        completed.ProcessedItems.Should().Be(1);
        service.GetActivities(owner, 50).Should().Contain(x => x.JobId == job.Id && x.Message.Contains("Remote mutation"));
    }

    [Fact]
    public async Task Simulator_never_completes_external_jobs()
    {
        using var service = new ExecutionCenterService(_databasePath, enableBackgroundWorker: true, enableSeedData: false);
        var owner = Guid.NewGuid();
        var job = service.EnqueueExternal(owner, Guid.NewGuid(), "Approved change", "WordPress.Content.Update", "Site", "idem-3", "corr-3");

        await Task.Delay(TimeSpan.FromSeconds(2.6));

        var persisted = service.GetJobs(owner).Single(x => x.Id == job.Id);
        persisted.Status.Should().Be("Waiting");
        persisted.Progress.Should().Be(0);
    }

    [Fact]
    public void Existing_database_is_upgraded_with_external_execution_columns()
    {
        using (var connection = new SqliteConnection($"Data Source={_databasePath}"))
        {
            connection.Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                CREATE TABLE ExecutionCenterJobs (
                    Id TEXT PRIMARY KEY, Title TEXT NOT NULL, Type TEXT NOT NULL, SiteName TEXT NOT NULL,
                    Status TEXT NOT NULL, Progress INTEGER NOT NULL DEFAULT 0, TotalItems INTEGER NOT NULL,
                    ProcessedItems INTEGER NOT NULL DEFAULT 0, CreatedAtUtc TEXT NOT NULL,
                    StartedAtUtc TEXT NULL, CompletedAtUtc TEXT NULL, Error TEXT NULL,
                    OwnerUserId TEXT NULL, SiteId TEXT NULL);
                CREATE TABLE ExecutionCenterActivities (
                    Id TEXT PRIMARY KEY, JobId TEXT NOT NULL, CreatedAtUtc TEXT NOT NULL, Level TEXT NOT NULL,
                    Message TEXT NOT NULL, FOREIGN KEY (JobId) REFERENCES ExecutionCenterJobs(Id) ON DELETE CASCADE);
                """;
            command.ExecuteNonQuery();
        }

        using var service = NewService();
        using var verify = new SqliteConnection($"Data Source={_databasePath}");
        verify.Open();
        using var pragma = verify.CreateCommand();
        pragma.CommandText = "PRAGMA table_info(ExecutionCenterJobs);";
        using var reader = pragma.ExecuteReader();
        var columns = new List<string>();
        while (reader.Read()) columns.Add(reader.GetString(1));

        columns.Should().Contain(["ExecutionMode", "IdempotencyKey", "CorrelationId"]);
    }

    private ExecutionCenterService NewService() => new(_databasePath, enableBackgroundWorker: false, enableSeedData: false);

    public void Dispose()
    {
        SqliteConnection.ClearAllPools();
        TryDeleteDirectory(_directory);
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
                Directory.Delete(directory, recursive: true);
                return;
            }
            catch (IOException) when (attempt < 20) { Thread.Sleep(100); }
            catch (UnauthorizedAccessException) when (attempt < 20) { Thread.Sleep(100); }
        }
    }
}
