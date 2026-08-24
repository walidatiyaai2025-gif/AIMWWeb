using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class BulkResultReconciliationTests : IDisposable
{
    private readonly string _directory;
    private readonly string _databasePath;
    private readonly ExecutionCenterService _executionCenter;
    private readonly ExecutionOperationTracker _tracker;

    public BulkResultReconciliationTests()
    {
        _directory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_directory);
        _databasePath = Path.Combine(_directory, "execution-center.db");
        _executionCenter = new ExecutionCenterService(_databasePath);
        _tracker = new ExecutionOperationTracker(_executionCenter, _databasePath);
    }

    [Fact]
    public void WordPress_mutation_failure_is_failed_not_completed()
    {
        BulkExecutionOutcomePolicy.Resolve(0, 2, reconciliationSucceeded: true)
            .Should().Be(BulkExecutionDisposition.Failed);
    }

    [Fact]
    public void WordPress_success_with_sync_failure_requires_reconciliation()
    {
        BulkExecutionOutcomePolicy.Resolve(2, 0, reconciliationSucceeded: false)
            .Should().Be(BulkExecutionDisposition.NeedsReconciliation);
    }

    [Fact]
    public void WordPress_success_with_successful_reconciliation_is_completed()
    {
        BulkExecutionOutcomePolicy.Resolve(2, 0, reconciliationSucceeded: true)
            .Should().Be(BulkExecutionDisposition.Completed);
    }

    [Fact]
    public void Partial_remote_success_that_reconciles_is_completed_with_warnings()
    {
        BulkExecutionOutcomePolicy.Resolve(1, 1, reconciliationSucceeded: true)
            .Should().Be(BulkExecutionDisposition.CompletedWithWarnings);
    }

    [Fact]
    public void Reconciliation_retry_transitions_same_durable_job_without_requeueing_mutation()
    {
        var owner = Guid.NewGuid();
        var site = Guid.NewGuid();
        var jobId = _tracker.Start(owner, site, "Bulk status", "Global Bulk Status: draft", "Site", 2);

        _tracker.NeedsReconciliation(jobId, 2, 2,
            "Remote WordPress changes were applied; local reconciliation failed.");

        var pending = _executionCenter.GetJobs(owner).Single(x => x.Id == jobId);
        pending.Status.Should().Be("NeedsReconciliation");
        pending.Error.Should().Contain("Remote WordPress changes were applied");
        pending.CompletedAtUtc.Should().BeNull();

        _tracker.Complete(jobId, 2, 2,
            "Local cache reconciled with the WordPress state that was already applied remotely.");

        var reconciled = _executionCenter.GetJobs(owner).Single(x => x.Id == jobId);
        reconciled.Id.Should().Be(jobId);
        reconciled.Status.Should().Be("Completed");
        reconciled.Error.Should().BeNull();
        reconciled.CompletedAtUtc.Should().NotBeNull();
    }

    [Fact]
    public void Retry_after_partial_remote_success_preserves_unapplied_intent_as_warning()
    {
        var owner = Guid.NewGuid();
        var site = Guid.NewGuid();
        var jobId = _tracker.Start(owner, site, "Partial trash", "Bulk Trash", "Site", 3);

        _tracker.NeedsReconciliation(jobId, 1, 3,
            "One remote mutation was confirmed before interruption.");
        _tracker.CompleteWithWarnings(jobId, 1, 3,
            "Local state reconciled; two requested mutations were not replayed.");

        var recovered = _executionCenter.GetJobs(owner).Single(x => x.Id == jobId);
        recovered.Status.Should().Be("CompletedWithWarnings");
        recovered.ProcessedItems.Should().Be(1);
        recovered.TotalItems.Should().Be(3);
        recovered.Error.Should().Contain("not replayed");
    }

    [Fact]
    public void Production_recovery_is_sync_only_and_status_retry_skips_already_applied_remote_state()
    {
        var root = FindRepositoryRoot();
        var policy = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Services", "BulkExecutionOutcomePolicy.cs"));
        var worker = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Services", "BulkContentOperationWorker.cs"));
        var directStatus = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Services", "BulkStatusExecutionService.cs"));
        var trash = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Services", "BulkTrashExecutionService.cs"));
        var executionPage = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Components", "Pages", "ExecutionCenter.razor"));

        policy.Should().Contain("SynchronizeAsync(site.Id");
        policy.Should().NotContain("UpdateAsync(");
        policy.Should().NotContain("SendAsync(");
        policy.Should().Contain("RequirePermission(ApplicationPermissionCatalog.ContentEdit)");
        policy.Should().Contain("GetJobs(ownerUserId)");
        policy.Should().Contain("wasPartialMutation");
        policy.Should().Contain("tracker.CompleteWithWarnings");
        policy.Should().Contain("[\"replayedMutations\"] = \"0\"");

        worker.Should().Contain("already has status {request.TargetStatus}; duplicate mutation skipped");
        directStatus.Should().Contain("already has status {status}; duplicate mutation skipped");
        worker.Should().Contain("tracker.NeedsReconciliation(request.JobId, succeeded, total");
        worker.Should().Contain("before execution was interrupted");
        worker.Should().NotContain("Bulk operation {JobId} completed but local cache refresh failed.");
        directStatus.Should().Contain("tracker.NeedsReconciliation(jobId, succeeded, targets.Count");
        trash.Should().Contain("tracker.NeedsReconciliation(jobId, succeeded, targets.Count");
        trash.Should().Contain("throw new BulkReconciliationRequiredException");

        executionPage.Should().Contain("NeedsReconciliation");
        executionPage.Should().Contain("Retry reconciliation only");
        executionPage.Should().Contain("WordPress mutations will not be replayed");
    }

    [Fact]
    public void Needs_reconciliation_and_completed_with_warnings_are_persisted_as_distinct_visible_states()
    {
        var owner = Guid.NewGuid();
        var site = Guid.NewGuid();
        var needs = _tracker.Start(owner, site, "Trash", "Bulk Trash", "Site", 1);
        var warnings = _tracker.Start(owner, site, "Status", "Global Bulk Status: publish", "Site", 2);

        _tracker.NeedsReconciliation(needs, 1, 1, "Remote applied; local cache stale.");
        _tracker.CompleteWithWarnings(warnings, 2, 2, "One target failed; successful targets reconciled.");

        var jobs = _executionCenter.GetJobs(owner);
        jobs.Single(x => x.Id == needs).Status.Should().Be("NeedsReconciliation");
        jobs.Single(x => x.Id == warnings).Status.Should().Be("CompletedWithWarnings");
        jobs.Single(x => x.Id == warnings).Error.Should().Contain("One target failed");
    }

    public void Dispose()
    {
        _executionCenter.Dispose();
        SqliteConnection.ClearAllPools();
        if (Directory.Exists(_directory)) Directory.Delete(_directory, recursive: true);
    }

    private static DirectoryInfo FindRepositoryRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln"))) return current;
            current = current.Parent;
        }
        throw new DirectoryNotFoundException("Could not locate AIMWWeb repository root from the test output directory.");
    }
}
