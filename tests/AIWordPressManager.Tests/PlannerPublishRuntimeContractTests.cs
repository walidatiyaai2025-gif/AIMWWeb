using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class PlannerPublishRuntimeContractTests
{
    [Fact]
    public void Publish_idempotency_key_is_stable_for_same_revision_and_changes_with_publishable_content()
    {
        var id = Guid.NewGuid();
        var siteId = Guid.NewGuid();
        var scheduled = DateTime.UtcNow.AddHours(2);
        var first = Planner(id, siteId, "Release title", "<p>Revision A</p>", scheduled, DateTime.UtcNow);
        var sameRevisionLater = Planner(id, siteId, "Release title", "<p>Revision A</p>", scheduled, DateTime.UtcNow.AddMinutes(10));
        var changedDraft = Planner(id, siteId, "Release title", "<p>Revision B</p>", scheduled, DateTime.UtcNow.AddMinutes(11));

        var firstKey = ContentPlannerService.CreatePublishIdempotencyKey(first);
        var sameKey = ContentPlannerService.CreatePublishIdempotencyKey(sameRevisionLater);
        var changedKey = ContentPlannerService.CreatePublishIdempotencyKey(changedDraft);

        firstKey.Should().StartWith($"planner-publish:{id:N}:");
        sameKey.Should().Be(firstKey, "non-publish bookkeeping timestamps must not create a duplicate WordPress mutation");
        changedKey.Should().NotBe(firstKey, "a changed draft is a distinct publish revision");
    }

    [Fact]
    public void Final_runtime_registers_the_worker_and_preserves_bulk_reconciliation_states()
    {
        var root = FindRepositoryRoot();
        var program = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Program.cs"));
        var tracker = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Services", "ExecutionOperationTracker.cs"));
        var worker = File.ReadAllText(Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Services", "PlannerPublishWorker.cs"));

        program.Should().Contain("AddHostedService<PlannerPublishWorker>()");
        tracker.Should().Contain("TryStartTracked");
        tracker.Should().Contain("RetryTracked");
        tracker.Should().Contain("RecoverInterruptedTracked");
        tracker.Should().Contain("CompleteWithWarnings");
        tracker.Should().Contain("NeedsReconciliation");
        worker.Should().Contain("EnsureRemotePostAsync");
        worker.Should().Contain("forceFullRefresh: true");
        worker.Should().Contain("ReconcilePublishAsync");
        worker.Should().Contain("duplicate mutation skipped");
    }

    private static PlannerItem Planner(
        Guid id,
        Guid siteId,
        string title,
        string draft,
        DateTime scheduled,
        DateTime updated) =>
        new(
            id,
            siteId,
            "Owned Site",
            title,
            PlannerItemStatus.Draft,
            "Idea",
            "Brief",
            draft,
            scheduled,
            null,
            updated.AddMinutes(-1),
            updated,
            "owner");

    private static DirectoryInfo FindRepositoryRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
                return current;
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Repository root was not found.");
    }
}
