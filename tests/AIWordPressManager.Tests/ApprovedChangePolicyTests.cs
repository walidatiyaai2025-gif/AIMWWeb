using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class ApprovedChangePolicyTests
{
    [Fact]
    public void Typed_wordpress_content_update_with_version_is_executable()
    {
        var item = CreateApproval(CreateRequest(DateTimeOffset.Parse("2026-08-09T10:00:00Z")));
        var capability = ApprovedChangePolicy.Evaluate(item);
        capability.CanExecute.Should().BeTrue();
        ApprovedChangePolicy.TryGetRequest(item, out var request, out _).Should().BeTrue();
        request.Id.Should().Be(42);
        request.ContentType.Should().Be("post");
    }

    [Fact]
    public void Generic_ai_proposal_is_approval_only()
    {
        var item = CreateApproval(CreateRequest(DateTimeOffset.UtcNow), "AI.ContentUpdate");
        var capability = ApprovedChangePolicy.Evaluate(item);
        capability.CanExecute.Should().BeFalse();
        capability.Message.Should().Contain("not enabled");
    }

    [Fact]
    public void Missing_remote_version_blocks_automatic_execution()
    {
        var item = CreateApproval(CreateRequest(null));
        var capability = ApprovedChangePolicy.Evaluate(item);
        capability.CanExecute.Should().BeFalse();
        capability.Message.Should().Contain("remote version");
    }

    [Fact]
    public void Force_overwrite_is_never_allowed_for_background_approval_execution()
    {
        var item = CreateApproval(CreateRequest(DateTimeOffset.UtcNow) with { ForceOverwrite = true });
        ApprovedChangePolicy.Evaluate(item).CanExecute.Should().BeFalse();
    }

    [Fact]
    public void Remote_matches_detects_idempotent_replay_without_second_post()
    {
        var modified = DateTimeOffset.Parse("2026-08-09T10:05:00Z");
        var request = CreateRequest(DateTimeOffset.Parse("2026-08-09T10:00:00Z"));
        var remote = new WordPressEditableContent(
            "post", 42, "Approved title", "approved-title", "draft",
            "<p>Approved body</p>", "Approved excerpt", "https://example.test/approved-title",
            null, modified, 9, [1, 2], [3], "", 1, "open", "open", "standard", false, false, "{}");

        ApprovedChangePolicy.RemoteMatches(remote, request).Should().BeTrue();
        ApprovedChangePolicy.RemoteMatches(remote with { Title = "Different" }, request).Should().BeFalse();
    }

    [Fact]
    public void FromRemote_builds_version_guarded_non_force_request()
    {
        var modified = DateTimeOffset.Parse("2026-08-09T10:05:00Z");
        var remote = new WordPressEditableContent(
            "page", 7, "Page", "page", "publish", "Body", "Excerpt", "https://example.test/page",
            null, modified, 0, [], [], "", 1, "closed", "closed", "standard", false, false, "{}");

        var request = ApprovedChangePolicy.FromRemote(remote);

        request.ContentType.Should().Be("page");
        request.Id.Should().Be(7);
        request.ExpectedModifiedGmt.Should().Be(modified);
        request.ForceOverwrite.Should().BeFalse();
    }

    private static WordPressContentUpdateRequest CreateRequest(DateTimeOffset? expectedModifiedGmt) => new(
        "post", 42, "Approved title", "approved-title", "draft", "<p>Approved body</p>", "Approved excerpt",
        null, 9, [1, 2], [3], "", "open", "open", "standard", false, expectedModifiedGmt, false);

    private static ApprovalItem CreateApproval(
        WordPressContentUpdateRequest request,
        string operationType = ApprovedChangePolicy.WordPressContentUpdateOperation)
    {
        var json = System.Text.Json.JsonSerializer.Serialize(request);
        return new ApprovalItem(
            Guid.NewGuid(), Guid.NewGuid(), Guid.NewGuid(), "Test Site", operationType, "Test approval",
            ApprovalRiskLevel.Medium, ApprovalStatus.Pending, json, json, "tester", DateTime.UtcNow,
            null, null, null, null, Guid.NewGuid().ToString("N"), Guid.NewGuid().ToString("N"));
    }
}
