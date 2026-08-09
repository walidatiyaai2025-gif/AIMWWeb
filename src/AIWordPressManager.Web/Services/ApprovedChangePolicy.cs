using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

public sealed record ApprovedChangeCapability(bool CanExecute, string Message)
{
    public static ApprovedChangeCapability Allowed(string message = "This approved change can be executed automatically.") => new(true, message);
    public static ApprovedChangeCapability Blocked(string message) => new(false, message);
}

public static class ApprovedChangePolicy
{
    public const string WordPressContentUpdateOperation = "WordPress.Content.Update";

    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        PropertyNameCaseInsensitive = true
    };

    public static ApprovedChangeCapability Evaluate(ApprovalItem item)
    {
        if (item.SiteId is null || item.SiteId == Guid.Empty)
            return ApprovedChangeCapability.Blocked("Automatic execution requires a site-scoped approval.");
        if (!string.Equals(item.OperationType, WordPressContentUpdateOperation, StringComparison.Ordinal))
            return ApprovedChangeCapability.Blocked("This proposal can be approved, but its operation type is not enabled for automatic execution.");
        if (!TryReadRequest(item.AfterJson, out var request, out var error))
            return ApprovedChangeCapability.Blocked(error);
        if (request.Id <= 0)
            return ApprovedChangeCapability.Blocked("Automatic execution requires a concrete WordPress content ID.");
        if (request.ContentType is not ("post" or "page"))
            return ApprovedChangeCapability.Blocked("Automatic execution supports WordPress posts and pages only.");
        if (string.IsNullOrWhiteSpace(request.Title))
            return ApprovedChangeCapability.Blocked("The approved content payload must contain a title.");
        if (!request.ExpectedModifiedGmt.HasValue)
            return ApprovedChangeCapability.Blocked("The approved content payload must include the remote version captured during review.");
        if (request.ForceOverwrite)
            return ApprovedChangeCapability.Blocked("Force-overwrite is not allowed for background approved changes.");
        return ApprovedChangeCapability.Allowed();
    }

    public static bool TryGetRequest(ApprovalItem item, out WordPressContentUpdateRequest request, out string error)
    {
        request = default!;
        var capability = Evaluate(item);
        if (!capability.CanExecute)
        {
            error = capability.Message;
            return false;
        }
        return TryReadRequest(item.AfterJson, out request, out error);
    }

    public static bool TryGetBeforeRequest(ApprovalItem item, out WordPressContentUpdateRequest request, out string error) =>
        TryReadRequest(item.BeforeJson, out request, out error);

    public static bool TryBuildRollbackSubmission(
        ApprovalItem executed,
        WordPressEditableContent remote,
        out ApprovalSubmission submission,
        out string error)
    {
        submission = default!;
        if (executed.Status != ApprovalStatus.Executed)
        {
            error = "Only successfully executed approvals can create rollback proposals.";
            return false;
        }
        if (!executed.SiteId.HasValue || executed.SiteId.Value == Guid.Empty)
        {
            error = "Rollback requires a site-scoped approval.";
            return false;
        }
        if (!string.Equals(executed.OperationType, WordPressContentUpdateOperation, StringComparison.Ordinal))
        {
            error = "Rollback is not enabled for this operation type.";
            return false;
        }
        if (!TryGetBeforeRequest(executed, out var original, out error)) return false;
        if (remote.Id != original.Id || !string.Equals(remote.ContentType, original.ContentType, StringComparison.OrdinalIgnoreCase))
        {
            error = "The current remote content does not match the original approval target.";
            return false;
        }
        if (!remote.ModifiedGmt.HasValue)
        {
            error = "The current WordPress version is required before creating a rollback proposal.";
            return false;
        }

        var rollback = WithExpectedVersion(original, remote.ModifiedGmt);
        if (RemoteMatches(remote, rollback))
        {
            error = "WordPress already matches the original state; no rollback is required.";
            return false;
        }

        var current = FromRemote(remote, remote.ModifiedGmt);
        var versionKey = remote.ModifiedGmt.Value.ToUniversalTime().Ticks;
        submission = new ApprovalSubmission(
            executed.SiteId,
            executed.SiteName,
            WordPressContentUpdateOperation,
            $"Rollback: {executed.Title}",
            current,
            rollback,
            null,
            ApprovalRiskLevel.High,
            $"{executed.CorrelationId}:rollback:{versionKey}",
            $"rollback:{executed.Id:N}:{versionKey}");
        error = string.Empty;
        return true;
    }

    public static bool RemoteMatches(WordPressEditableContent remote, WordPressContentUpdateRequest desired)
    {
        if (remote.Id != desired.Id || !string.Equals(remote.ContentType, desired.ContentType, StringComparison.OrdinalIgnoreCase))
            return false;

        return string.Equals(remote.Title, desired.Title, StringComparison.Ordinal)
            && string.Equals(remote.Slug, desired.Slug, StringComparison.Ordinal)
            && string.Equals(remote.Status, desired.Status, StringComparison.OrdinalIgnoreCase)
            && string.Equals(remote.Content, desired.Content, StringComparison.Ordinal)
            && string.Equals(remote.Excerpt, desired.Excerpt, StringComparison.Ordinal)
            && SameInstant(remote.DateGmt, desired.DateGmt)
            && remote.FeaturedMediaId == desired.FeaturedMediaId
            && SameIds(remote.CategoryIds, desired.CategoryIds)
            && SameIds(remote.TagIds, desired.TagIds)
            && string.Equals(remote.Template, desired.Template, StringComparison.Ordinal)
            && string.Equals(remote.CommentStatus, desired.CommentStatus, StringComparison.OrdinalIgnoreCase)
            && string.Equals(remote.PingStatus, desired.PingStatus, StringComparison.OrdinalIgnoreCase)
            && string.Equals(remote.Format, desired.Format, StringComparison.OrdinalIgnoreCase)
            && remote.Sticky == desired.Sticky;
    }

    public static WordPressContentUpdateRequest FromRemote(
        WordPressEditableContent remote,
        DateTimeOffset? expectedModifiedGmt = null) => new(
        remote.ContentType,
        remote.Id,
        remote.Title,
        remote.Slug,
        remote.Status,
        remote.Content,
        remote.Excerpt,
        remote.DateGmt,
        remote.FeaturedMediaId,
        remote.CategoryIds,
        remote.TagIds,
        remote.Template,
        remote.CommentStatus,
        remote.PingStatus,
        remote.Format,
        remote.Sticky,
        expectedModifiedGmt ?? remote.ModifiedGmt,
        false);

    public static WordPressContentUpdateRequest WithExpectedVersion(
        WordPressContentUpdateRequest request,
        DateTimeOffset? expectedModifiedGmt) =>
        request with { ExpectedModifiedGmt = expectedModifiedGmt, ForceOverwrite = false };

    private static bool TryReadRequest(string json, out WordPressContentUpdateRequest request, out string error)
    {
        request = default!;
        if (string.IsNullOrWhiteSpace(json))
        {
            error = "The approved change payload is empty.";
            return false;
        }

        try
        {
            request = JsonSerializer.Deserialize<WordPressContentUpdateRequest>(json, JsonOptions)!;
            if (request is null)
            {
                error = "The approved change payload is invalid.";
                return false;
            }
            error = string.Empty;
            return true;
        }
        catch (JsonException)
        {
            error = "The approved change payload is not a supported WordPress content update.";
            return false;
        }
    }

    private static bool SameInstant(DateTimeOffset? left, DateTimeOffset? right)
    {
        if (!left.HasValue && !right.HasValue) return true;
        if (!left.HasValue || !right.HasValue) return false;
        return left.Value.ToUniversalTime() == right.Value.ToUniversalTime();
    }

    private static bool SameIds(IReadOnlyList<int> left, IReadOnlyList<int> right) =>
        left.OrderBy(x => x).SequenceEqual(right.OrderBy(x => x));
}
