using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ApprovalWorkflowTests : IDisposable
{
    private readonly string _testDirectory;
    private readonly ExecutionCenterService _executionCenter;
    private readonly ApprovalWorkflowService _approvals;

    public ApprovalWorkflowTests()
    {
        _testDirectory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_testDirectory);

        _executionCenter = new ExecutionCenterService(
            Path.Combine(_testDirectory, "execution-center.db"),
            enableBackgroundWorker: false,
            enableSeedData: false);

        _approvals = new ApprovalWorkflowService(
            _executionCenter,
            Path.Combine(_testDirectory, "approval-workflow.db"));
    }

    [Fact]
    public void Submit_WithSameIdempotencyKey_ReturnsExistingPendingItem()
    {
        var request = CreateSubmission("same-key", "Bulk SEO Update");

        var first = _approvals.Submit(request);
        var second = _approvals.Submit(request);

        second.Id.Should().Be(first.Id);
        second.Status.Should().Be(ApprovalStatus.Pending);
        _approvals.GetItems(ApprovalStatus.Pending).Count(x => x.IdempotencyKey == "same-key")
            .Should().Be(1);
    }

    [Fact]
    public void Submit_AssignsCriticalRisk_ToDeleteOperations()
    {
        var item = _approvals.Submit(CreateSubmission(Guid.NewGuid().ToString("N"), "Bulk Media Delete"));

        item.RiskLevel.Should().Be(ApprovalRiskLevel.Critical);
    }

    [Fact]
    public void Reject_StoresReviewerDecisionAndAuditEntry()
    {
        var item = _approvals.Submit(CreateSubmission(Guid.NewGuid().ToString("N"), "SEO Update"));

        var rejected = _approvals.Reject(item.Id, "reviewer@example.com", "Unsafe change");
        var audit = _approvals.GetAudit(item.Id);

        rejected.Status.Should().Be(ApprovalStatus.Rejected);
        rejected.ReviewedBy.Should().Be("reviewer@example.com");
        rejected.ReviewerNotes.Should().Be("Unsafe change");
        audit.Should().Contain(x => x.Action == "Rejected" && x.Actor == "reviewer@example.com");
    }

    [Fact]
    public void Approve_WithImmediateExecution_CreatesExecutionJob()
    {
        var item = _approvals.Submit(CreateSubmission(Guid.NewGuid().ToString("N"), "Bulk SEO Update"));

        var approved = _approvals.Approve(item.Id, "owner@example.com", "Approved", executeImmediately: true);

        approved.Status.Should().Be(ApprovalStatus.Executed);
        approved.ExecutionJobId.Should().NotBeNull();
        _executionCenter.GetJobs().Should().Contain(x => x.Id == approved.ExecutionJobId);
        _approvals.GetAudit(item.Id).Should().Contain(x => x.Action == "QueuedForExecution");
    }

    [Fact]
    public void UpdateProposal_ChangesAfterJson_OnlyWhilePending()
    {
        var item = _approvals.Submit(CreateSubmission(Guid.NewGuid().ToString("N"), "Content Update"));

        var updated = _approvals.UpdateProposal(
            item.Id,
            new { title = "Updated title" },
            "editor@example.com",
            "Edited before approval");

        updated.AfterJson.Should().Contain("Updated title");
        _approvals.GetAudit(item.Id).Should().Contain(x => x.Action == "Edited");
    }

    private static ApprovalSubmission CreateSubmission(string idempotencyKey, string operationType) => new(
        SiteId: Guid.NewGuid(),
        SiteName: "Test Site",
        OperationType: operationType,
        Title: "Test approval",
        Before: new { title = "Before" },
        After: new { title = "After" },
        RequestedBy: "tester@example.com",
        RiskLevel: null,
        CorrelationId: Guid.NewGuid().ToString("N"),
        IdempotencyKey: idempotencyKey);

    public void Dispose()
    {
        _executionCenter.Dispose();
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
