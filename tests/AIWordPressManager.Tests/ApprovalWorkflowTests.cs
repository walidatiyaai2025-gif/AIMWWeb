using System.Security.Claims;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.DependencyInjection;

namespace AIWordPressManager.Tests;

[Collection(WorkflowTestCollection.Name)]
public sealed class ApprovalWorkflowTests : IDisposable
{
    private readonly string _testDirectory;
    private readonly ExecutionCenterService _executionCenter;
    private readonly ApprovalWorkflowService _approvals;
    private readonly Guid _ownerA = Guid.NewGuid();
    private readonly Guid _ownerB = Guid.NewGuid();
    private readonly Guid _siteA = Guid.NewGuid();
    private readonly Guid _siteB = Guid.NewGuid();

    public ApprovalWorkflowTests()
    {
        _testDirectory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_testDirectory);
        _executionCenter = new ExecutionCenterService(Path.Combine(_testDirectory, "execution-center.db"), false, false);
        _approvals = new ApprovalWorkflowService(
            _executionCenter,
            Path.Combine(_testDirectory, "approval-workflow.db"),
            siteId => siteId == _siteA ? _ownerA : siteId == _siteB ? _ownerB : null);
    }

    [Fact]
    public void Submit_WithSameIdempotencyKey_ReturnsExistingPendingItem_ForSameOwner()
    {
        var request = CreateSubmission(_siteA, "same-key", "Bulk SEO Update");
        var first = _approvals.Submit(_ownerA, request, "owner-a@example.com");
        var second = _approvals.Submit(_ownerA, request, "owner-a@example.com");
        second.Id.Should().Be(first.Id);
        second.Status.Should().Be(ApprovalStatus.Pending);
        _approvals.GetItems(_ownerA, ApprovalStatus.Pending).Count(x => x.Id == first.Id).Should().Be(1);
    }

    [Fact]
    public void SameLogicalIdempotencyKey_IsIsolatedBetweenOwners()
    {
        var first = _approvals.Submit(_ownerA, CreateSubmission(_siteA, "shared-key", "SEO Update"), "owner-a@example.com");
        var second = _approvals.Submit(_ownerB, CreateSubmission(_siteB, "shared-key", "SEO Update"), "owner-b@example.com");
        first.Id.Should().NotBe(second.Id);
        first.IdempotencyKey.Should().NotBe(second.IdempotencyKey);
        _approvals.GetItems(_ownerA).Should().ContainSingle(x => x.Id == first.Id);
        _approvals.GetItems(_ownerA).Should().NotContain(x => x.Id == second.Id);
        _approvals.GetItems(_ownerB).Should().ContainSingle(x => x.Id == second.Id);
    }

    [Fact]
    public void Submit_AssignsCriticalRisk_ToDeleteOperations()
    {
        var item = _approvals.Submit(_ownerA, CreateSubmission(_siteA, Guid.NewGuid().ToString("N"), "Bulk Media Delete"), "owner-a@example.com");
        item.OwnerUserId.Should().Be(_ownerA);
        item.RiskLevel.Should().Be(ApprovalRiskLevel.Critical);
    }

    [Fact]
    public void Direct_read_audit_and_review_are_owner_scoped()
    {
        var item = _approvals.Submit(_ownerA, CreateSubmission(_siteA, Guid.NewGuid().ToString("N"), "SEO Update"), "owner-a@example.com");
        _approvals.GetById(_ownerB, item.Id).Should().BeNull();
        _approvals.GetAudit(_ownerB, item.Id).Should().BeEmpty();
        Action crossTenant = () => _approvals.Reject(_ownerB, item.Id, "owner-b@example.com", "Cross tenant");
        crossTenant.Should().Throw<InvalidOperationException>();

        var rejected = _approvals.Reject(_ownerA, item.Id, "owner-a@example.com", "Unsafe change");
        rejected.Status.Should().Be(ApprovalStatus.Rejected);
        rejected.ReviewedBy.Should().Be("owner-a@example.com");
        _approvals.GetAudit(_ownerA, item.Id).Should().Contain(x => x.Action == "Rejected" && x.Actor == "owner-a@example.com");
    }

    [Fact]
    public void Approve_WithImmediateExecution_QueuesExternalJob_ButRemainsApprovedUntilRealSuccess()
    {
        var item = _approvals.Submit(_ownerA, CreateExecutableSubmission(_siteA, "execute-key"), "owner-a@example.com");
        var approved = _approvals.Approve(_ownerA, item.Id, "owner-a@example.com", "Approved", true);

        approved.Status.Should().Be(ApprovalStatus.Approved);
        approved.ExecutionJobId.Should().NotBeNull();
        var job = _executionCenter.GetJobs(_ownerA).Single(x => x.Id == approved.ExecutionJobId);
        job.OwnerUserId.Should().Be(_ownerA);
        job.SiteId.Should().Be(_siteA);
        job.ExecutionMode.Should().Be(ExecutionCenterService.ExternalExecutionMode);
        job.Status.Should().Be("Waiting");
        job.IdempotencyKey.Should().Be(approved.IdempotencyKey);
        job.CorrelationId.Should().Be(approved.CorrelationId);
        _executionCenter.GetJobs(_ownerB).Should().NotContain(x => x.Id == approved.ExecutionJobId);
        _approvals.GetAudit(_ownerA, item.Id).Should().Contain(x => x.Action == "QueuedForExecution");
    }

    [Fact]
    public void Unsupported_proposal_cannot_request_immediate_execution_and_remains_pending()
    {
        var item = _approvals.Submit(_ownerA, CreateSubmission(_siteA, "unsupported", "AI.ContentUpdate"), "owner-a@example.com");
        Action action = () => _approvals.Approve(_ownerA, item.Id, "owner-a@example.com", null, true);
        action.Should().Throw<InvalidOperationException>().WithMessage("*not enabled*");
        _approvals.GetById(_ownerA, item.Id)!.Status.Should().Be(ApprovalStatus.Pending);
        _executionCenter.GetJobs(_ownerA).Should().BeEmpty();
    }

    [Fact]
    public void Execution_failure_is_audited_without_false_executed_status()
    {
        var item = _approvals.Submit(_ownerA, CreateExecutableSubmission(_siteA, "failure-key"), "owner-a@example.com");
        var queued = _approvals.Approve(_ownerA, item.Id, "owner-a@example.com", null, true);
        var jobId = queued.ExecutionJobId!.Value;
        _approvals.RecordExecutionFailed(_ownerA, item.Id, jobId, "Remote conflict");
        _approvals.GetById(_ownerA, item.Id)!.Status.Should().Be(ApprovalStatus.Approved);
        _approvals.GetAudit(_ownerA, item.Id).Should().Contain(x => x.Action == "ExecutionFailed" && x.Notes == "Remote conflict");
    }

    [Fact]
    public void Only_success_callback_transitions_approval_to_executed()
    {
        var item = _approvals.Submit(_ownerA, CreateExecutableSubmission(_siteA, "success-key"), "owner-a@example.com");
        var queued = _approvals.Approve(_ownerA, item.Id, "owner-a@example.com", null, true);
        var executed = _approvals.MarkExecutionSucceeded(_ownerA, item.Id, queued.ExecutionJobId!.Value, "WordPress POST succeeded");
        executed.Status.Should().Be(ApprovalStatus.Executed);
        _approvals.GetAudit(_ownerA, item.Id).Should().Contain(x => x.Action == "Executed" && x.Notes == "WordPress POST succeeded");
    }

    [Fact]
    public void Approval_cannot_be_approved_after_site_ownership_changes()
    {
        var currentOwner = _ownerA;
        var site = Guid.NewGuid();
        var path = Path.Combine(_testDirectory, "ownership-change.db");
        var service = new ApprovalWorkflowService(_executionCenter, path, id => id == site ? currentOwner : null);
        var item = service.Submit(_ownerA, CreateSubmission(site, "ownership-change", "SEO Update"), "owner-a@example.com");
        currentOwner = _ownerB;
        Action action = () => service.Approve(_ownerA, item.Id, "owner-a@example.com", null, true);
        action.Should().Throw<InvalidOperationException>();
        service.GetById(_ownerA, item.Id)!.Status.Should().Be(ApprovalStatus.Pending);
    }

    [Fact]
    public void UpdateProposal_ChangesAfterJson_OnlyForOwnerWhilePending()
    {
        var item = _approvals.Submit(_ownerA, CreateSubmission(_siteA, Guid.NewGuid().ToString("N"), "Content Update"), "owner-a@example.com");
        Action crossTenant = () => _approvals.UpdateProposal(_ownerB, item.Id, new { title = "Injected" }, "owner-b@example.com", "Cross tenant");
        crossTenant.Should().Throw<InvalidOperationException>();
        var updated = _approvals.UpdateProposal(_ownerA, item.Id, new { title = "Updated title" }, "owner-a@example.com", "Edited before approval");
        updated.AfterJson.Should().Contain("Updated title");
        _approvals.GetAudit(_ownerA, item.Id).Should().Contain(x => x.Action == "Edited");
    }

    [Fact]
    public void Legacy_schema_is_upgraded_and_ownerless_rows_use_current_site_ownership_only()
    {
        var path = Path.Combine(_testDirectory, "legacy-approval.db");
        var legacyId = Guid.NewGuid();
        CreateLegacyDatabase(path, legacyId, _siteA);
        var service = new ApprovalWorkflowService(_executionCenter, path, siteId => siteId == _siteA ? _ownerA : null);
        service.GetItems(_ownerA).Should().ContainSingle(x => x.Id == legacyId && x.OwnerUserId == null);
        service.GetItems(_ownerB).Should().BeEmpty();
        service.GetById(_ownerB, legacyId).Should().BeNull();
        service.Reject(_ownerA, legacyId, "owner-a@example.com", "Retire legacy proposal").Status.Should().Be(ApprovalStatus.Rejected);
    }

    [Fact]
    public void Http_compatibility_uses_authenticated_owner_and_actor_not_request_strings()
    {
        var httpContext = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(
                [
                    new Claim(ClaimTypes.NameIdentifier, _ownerA.ToString()),
                    new Claim(ClaimTypes.Name, "alice@example.com"),
                    new Claim(ApplicationPermissionCatalog.ClaimType, ApplicationPermissionCatalog.ApprovalsDecide)
                ],
                "test"))
        };
        var accessor = new HttpContextAccessor { HttpContext = httpContext };
        var notifications = NotificationInboxService.ForDatabase(Path.Combine(_testDirectory, "http-notifications.db"));
        var services = new ServiceCollection().BuildServiceProvider();
        var service = new ApprovalWorkflowService(
            _executionCenter,
            notifications,
            services.GetRequiredService<IServiceScopeFactory>(),
            accessor);
        var ownerAItem = service.Submit(_ownerA, CreateSubmission(null, "http-a", "AI Suggestion"), "seed-a@example.com");
        var ownerBItem = _approvals.Submit(_ownerB, CreateSubmission(null, "http-b", "AI Suggestion"), "seed-b@example.com");
        service.GetItems().Should().ContainSingle(x => x.Id == ownerAItem.Id);
        service.GetById(ownerBItem.Id).Should().BeNull();
        var rejected = service.Reject(ownerAItem.Id, "spoofed-reviewer@example.com", "No");
        rejected.ReviewedBy.Should().Be("alice@example.com");
        service.GetAudit(ownerAItem.Id).Should().Contain(x => x.Action == "Rejected" && x.Actor == "alice@example.com");
    }

    private static ApprovalSubmission CreateExecutableSubmission(Guid siteId, string key)
    {
        var expected = DateTimeOffset.Parse("2026-08-09T10:00:00Z");
        return new ApprovalSubmission(
            siteId,
            "Test Site",
            ApprovedChangePolicy.WordPressContentUpdateOperation,
            "Update post #42",
            CreateUpdateRequest("Before", "<p>Before</p>", expected),
            CreateUpdateRequest("After", "<p>After</p>", expected),
            "spoofable-display-value@example.com",
            ApprovalRiskLevel.Medium,
            Guid.NewGuid().ToString("N"),
            key);
    }

    private static WordPressContentUpdateRequest CreateUpdateRequest(string title, string content, DateTimeOffset expected) => new(
        "post", 42, title, "post-42", "draft", content, "excerpt", null, 0, [], [], "", "open", "open", "standard", false, expected, false);

    private static ApprovalSubmission CreateSubmission(Guid? siteId, string idempotencyKey, string operationType) => new(
        siteId,
        siteId.HasValue ? "Test Site" : string.Empty,
        operationType,
        "Test approval",
        new { title = "Before" },
        new { title = "After" },
        "spoofable-display-value@example.com",
        null,
        Guid.NewGuid().ToString("N"),
        idempotencyKey);

    private static void CreateLegacyDatabase(string path, Guid approvalId, Guid siteId)
    {
        using var connection = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = path }.ToString());
        connection.Open();
        using var command = connection.CreateCommand();
        command.CommandText = """
            CREATE TABLE ApprovalItems (
                Id TEXT PRIMARY KEY, SiteId TEXT NULL, SiteName TEXT NOT NULL, OperationType TEXT NOT NULL,
                Title TEXT NOT NULL, RiskLevel TEXT NOT NULL, Status TEXT NOT NULL, BeforeJson TEXT NOT NULL,
                AfterJson TEXT NOT NULL, RequestedBy TEXT NOT NULL, RequestedAtUtc TEXT NOT NULL,
                ReviewedBy TEXT NULL, ReviewedAtUtc TEXT NULL, ReviewerNotes TEXT NULL, ExecutionJobId TEXT NULL,
                CorrelationId TEXT NOT NULL, IdempotencyKey TEXT NOT NULL UNIQUE);
            CREATE TABLE ApprovalAudit (
                Id TEXT PRIMARY KEY, ApprovalId TEXT NOT NULL, Action TEXT NOT NULL, Actor TEXT NOT NULL,
                Notes TEXT NULL, CreatedAtUtc TEXT NOT NULL,
                FOREIGN KEY (ApprovalId) REFERENCES ApprovalItems(Id) ON DELETE CASCADE);
            INSERT INTO ApprovalItems
            (Id, SiteId, SiteName, OperationType, Title, RiskLevel, Status, BeforeJson, AfterJson,
             RequestedBy, RequestedAtUtc, CorrelationId, IdempotencyKey)
            VALUES
            ($id, $siteId, 'Legacy Site', 'SEO Update', 'Legacy approval', 'High', 'Pending', '{}', '{}',
             'legacy@example.com', $requestedAt, $correlation, $key);
            INSERT INTO ApprovalAudit(Id, ApprovalId, Action, Actor, Notes, CreatedAtUtc)
            VALUES($auditId, $id, 'Submitted', 'legacy@example.com', NULL, $requestedAt);
            """;
        command.Parameters.AddWithValue("$id", approvalId.ToString());
        command.Parameters.AddWithValue("$siteId", siteId.ToString());
        command.Parameters.AddWithValue("$requestedAt", DateTime.UtcNow.AddDays(-1).ToString("O"));
        command.Parameters.AddWithValue("$correlation", Guid.NewGuid().ToString("N"));
        command.Parameters.AddWithValue("$key", "legacy-key");
        command.Parameters.AddWithValue("$auditId", Guid.NewGuid().ToString());
        command.ExecuteNonQuery();
    }

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
                Directory.Delete(directory, true);
                return;
            }
            catch (IOException) when (attempt < 20) { Thread.Sleep(100); }
            catch (UnauthorizedAccessException) when (attempt < 20) { Thread.Sleep(100); }
        }
    }
}
