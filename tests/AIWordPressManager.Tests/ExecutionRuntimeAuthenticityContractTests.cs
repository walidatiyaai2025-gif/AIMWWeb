using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ExecutionRuntimeAuthenticityContractTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));

    public ExecutionRuntimeAuthenticityContractTests() => Directory.CreateDirectory(_directory);

    [Fact]
    public void Production_execution_service_contains_no_timer_seed_or_synthetic_completion_path()
    {
        var source = ReadRepositoryFile("src/AIWordPressManager.Web/Services/ExecutionCenterService.cs");

        source.Should().NotContain("SimulatedExecutionMode");
        source.Should().NotContain("SeedIfEmpty");
        source.Should().NotContain("TickSafely");
        source.Should().NotContain("Job completed successfully.");
        source.Should().NotContain("new Timer(");
        source.Should().NotContain("enableSeedData");
        source.Should().NotContain("enableBackgroundWorker");
        source.Should().Contain("TrackedExecutionMode");
        source.Should().Contain("UnavailableExecutionMode");
        source.Should().Contain("Legacy synthetic execution was retired because it did not represent real production work.");
    }

    [Fact]
    public void Automation_and_UI_do_not_route_generic_content_operation_to_execution_ledger()
    {
        var service = ReadRepositoryFile("src/AIWordPressManager.Web/Services/AutomationCenterService.cs");
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AutomationCenter.razor");

        service.Should().NotContain("execution.Enqueue(");
        service.Should().NotContain("new(\"Queued\"");
        service.Should().Contain("RequireSupportedType(job.Type)");
        service.Should().Contain("Type IN ('Synchronization','SEO Audit')");
        page.Should().NotContain("<option>Content Operation</option>");
        page.Should().Contain("data-runtime-unavailable=\"Content Operation\"");
        page.Should().Contain("real bulk worker requires explicit targets and a target action");
    }

    [Fact]
    public void Unsupported_automation_is_rejected_and_legacy_rows_are_retired_noninteractively()
    {
        var paths = new TestPaths(_directory);
        var service = new AutomationCenterService(paths);
        var siteId = Guid.NewGuid();

        FluentActions.Invoking(() => service.Save(new AutomationJobEditModel
        {
            Name = "Unsupported content automation",
            SiteId = siteId,
            SiteName = "Site",
            Type = "Content Operation",
            Enabled = true
        })).Should().Throw<InvalidOperationException>()
            .WithMessage("*no production runtime worker*");

        var databasePath = Path.Combine(_directory, "automation-center.db");
        using (var connection = new SqliteConnection($"Data Source={databasePath}"))
        {
            connection.Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                INSERT INTO AutomationJobs
                    (Id,Name,SiteId,SiteName,Type,Frequency,IntervalValue,TimeOfDay,Enabled,RetryCount,LastRunUtc,NextRunUtc,LastStatus,CreatedAtUtc,UpdatedAtUtc)
                VALUES
                    ($id,'Legacy content op',$siteId,'Site','Content Operation','daily',1,'08:00',1,3,NULL,$next,'Scheduled',$created,$created);
                """;
            command.Parameters.AddWithValue("$id", Guid.NewGuid().ToString());
            command.Parameters.AddWithValue("$siteId", siteId.ToString());
            command.Parameters.AddWithValue("$next", DateTime.UtcNow.AddMinutes(-1).ToString("O"));
            command.Parameters.AddWithValue("$created", DateTime.UtcNow.ToString("O"));
            command.ExecuteNonQuery();
        }

        var restarted = new AutomationCenterService(paths);
        var legacy = restarted.GetJobs().Single(x => x.Name == "Legacy content op");
        legacy.Enabled.Should().BeFalse();
        legacy.LastStatus.Should().Be(AutomationCenterService.UnavailableStatus);
        restarted.ClaimDueJobs(DateTime.UtcNow.AddHours(1)).Should().NotContain(x => x.Id == legacy.Id);
        FluentActions.Invoking(() => restarted.QueueNow(legacy.Id)).Should().Throw<InvalidOperationException>()
            .WithMessage("*no production runtime worker*");
    }

    [Fact]
    public void Tracked_job_does_not_progress_or_complete_without_runtime_reports()
    {
        var databasePath = Path.Combine(_directory, "execution-center-test.db");
        using var service = new ExecutionCenterService(databasePath);
        var job = service.Enqueue(Guid.NewGuid(), Guid.NewGuid(), "Real tracked work", "Synchronization", "Site", 10);

        var persisted = service.GetJobs().Single(x => x.Id == job.Id);
        persisted.ExecutionMode.Should().Be(ExecutionCenterService.TrackedExecutionMode);
        persisted.Status.Should().Be("Waiting");
        persisted.Progress.Should().Be(0);
        persisted.ProcessedItems.Should().Be(0);
        persisted.CompletedAtUtc.Should().BeNull();
    }

    public void Dispose()
    {
        SqliteConnection.ClearAllPools();
        try { Directory.Delete(_directory, recursive: true); } catch { }
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            var solution = Path.Combine(current.FullName, "AIWordPressManager.Web.sln");
            if (File.Exists(solution)) return File.ReadAllText(Path.Combine(current.FullName, relativePath));
            current = current.Parent;
        }
        throw new DirectoryNotFoundException("Could not locate repository root from test output.");
    }

    private sealed class TestPaths(string root) : IApplicationPathService
    {
        public string GetApplicationDataDirectory() => root;
        public string GetDatabasePath() => Path.Combine(root, "app.db");
        public string GetLogsDirectory() => Path.Combine(root, "logs");
        public string GetScreenshotsDirectory() => Path.Combine(root, "screenshots");
        public string GetBackupsDirectory() => Path.Combine(root, "backups");
        public string GetExportsDirectory() => Path.Combine(root, "exports");
        public string GetTemporaryDirectory() => Path.Combine(root, "temp");
    }
}
