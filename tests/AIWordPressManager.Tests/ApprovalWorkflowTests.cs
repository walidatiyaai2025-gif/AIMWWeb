using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ApprovalWorkflowTests : IDisposable
{
    private readonly ExecutionCenterService _executionCenter;
    private readonly ApprovalWorkflowService _approvals;

    public ApprovalWorkflowTests()
    {
        DeleteDatabase("approval-workflow.db");
        DeleteDatabase("execution-center.db");

        _executionCenter = new ExecutionCenterService();
        _approvals = new ApprovalWorkflowService(_executionCenter);
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

    private static void DeleteDatabase(string fileName)
    {
        var directory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AIWordPressManager",
            "Data");

        foreach (var suffix in new[] { string.Empty, "-wal", "-shm" })
        {
            var path = Path.Combine(directory, fileName + suffix);
            if (File.Exists(path)) File.Delete(path);
        }
    }

    public void Dispose()
    {
        _executionCenter.Dispose();
    }
}
