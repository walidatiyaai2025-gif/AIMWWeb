using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class SyncConflictPolicyTests
{
    [Fact]
    public void BuildReview_IdenticalMirrors_HasNoConflicts()
    {
        var modified = DateTimeOffset.Parse("2026-08-09T10:00:00Z");
        var local = Item("post", 10, "Title", modified);
        var remote = local with { ModifiedAtUtc = DateTimeOffset.Parse("2026-08-09T13:00:00+03:00") };

        var review = SyncConflictPolicy.BuildReview([local], [remote], modified.UtcDateTime);

        review.HasBaseline.Should().BeTrue();
        review.HasConflicts.Should().BeFalse();
        review.RemoteAdditions.Should().Be(0);
    }

    [Fact]
    public void BuildReview_RemoteFieldChange_IsRemoteUpdatedConflict()
    {
        var local = Item("post", 10, "Old title");
        var remote = local with { Title = "New title" };

        var review = SyncConflictPolicy.BuildReview([local], [remote], DateTime.UtcNow);

        review.Conflicts.Should().ContainSingle();
        review.Conflicts[0].Kind.Should().Be(SyncConflictKind.RemoteUpdated);
        review.Conflicts[0].Local.Title.Should().Be("Old title");
        review.Conflicts[0].Remote!.Title.Should().Be("New title");
    }

    [Fact]
    public void BuildReview_MissingRemoteItem_IsRemoteDeletedConflict()
    {
        var local = Item("page", 22, "Cached page");

        var review = SyncConflictPolicy.BuildReview([local], [], DateTime.UtcNow);

        review.RemoteDeletions.Should().Be(1);
        review.Conflicts[0].Kind.Should().Be(SyncConflictKind.RemoteDeleted);
        review.Conflicts[0].Remote.Should().BeNull();
    }

    [Fact]
    public void BuildReview_NewRemoteItem_IsAdditionNotConflict()
    {
        var remote = Item("post", 55, "New remote post");

        var review = SyncConflictPolicy.BuildReview([], [remote], DateTime.UtcNow);

        review.RemoteAdditions.Should().Be(1);
        review.HasConflicts.Should().BeFalse();
    }

    [Fact]
    public void BuildReview_TracksUpdatesDeletionsAndAdditionsTogether()
    {
        var localUpdated = Item("post", 1, "Old");
        var localDeleted = Item("page", 2, "Deleted remotely");
        var remoteUpdated = localUpdated with { Title = "Remote" };
        var remoteAdded = Item("post", 3, "Added remotely");

        var review = SyncConflictPolicy.BuildReview(
            [localUpdated, localDeleted],
            [remoteUpdated, remoteAdded],
            DateTime.UtcNow);

        review.RemoteUpdates.Should().Be(1);
        review.RemoteDeletions.Should().Be(1);
        review.RemoteAdditions.Should().Be(1);
    }

    [Fact]
    public void BuildReview_WithoutAnyBaseline_ReportsInitialState()
    {
        var review = SyncConflictPolicy.BuildReview([], [Item("post", 1, "Remote")], null);

        review.HasBaseline.Should().BeFalse();
        review.RemoteAdditions.Should().Be(1);
    }

    private static SyncComparableContent Item(
        string type,
        int id,
        string title,
        DateTimeOffset? modified = null) =>
        new(type, id, title, $"item-{id}", "publish", $"https://example.com/{id}", "<p>Content</p>", "Excerpt", modified ?? DateTimeOffset.Parse("2026-08-09T10:00:00Z"));
}
