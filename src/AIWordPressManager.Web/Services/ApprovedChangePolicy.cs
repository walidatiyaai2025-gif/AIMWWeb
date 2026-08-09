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
