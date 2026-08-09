namespace AIWordPressManager.Web.Services;

public static class GlobalContentBulkPolicy
{
    public const int MaxTargets = 100;

    private static readonly HashSet<string> AllowedStatuses = new(StringComparer.OrdinalIgnoreCase)
    {
        "publish", "draft", "pending", "private"
    };

    public static string NormalizeStatus(string? status)
    {
        var normalized = (status ?? string.Empty).Trim().ToLowerInvariant();
        if (!AllowedStatuses.Contains(normalized))
            throw new InvalidOperationException("Unsupported bulk content status.");

        return normalized;
    }

    public static IReadOnlyList<GlobalBulkContentTarget> NormalizeTargets(IEnumerable<GlobalBulkContentTarget>? targets)
    {
        var source = targets?.ToList() ?? [];
        if (source.Count == 0)
            throw new InvalidOperationException("Select at least one content item.");
        if (source.Count > MaxTargets)
            throw new InvalidOperationException($"Bulk operations are limited to {MaxTargets} items at a time.");

        var normalized = new List<GlobalBulkContentTarget>(source.Count);
        foreach (var target in source)
        {
            var contentType = (target.ContentType ?? string.Empty).Trim().ToLowerInvariant();
            if (target.SiteId == Guid.Empty || target.WordPressId <= 0 || contentType is not ("post" or "page"))
                throw new InvalidOperationException("The bulk selection contains an invalid content target.");

            normalized.Add(target with
            {
                ContentType = contentType,
                Title = string.IsNullOrWhiteSpace(target.Title) ? $"#{target.WordPressId}" : target.Title.Trim()
            });
        }

        var distinct = normalized
            .DistinctBy(x => (x.SiteId, x.ContentType, x.WordPressId))
            .ToArray();

        if (distinct.Length > MaxTargets)
            throw new InvalidOperationException($"Bulk operations are limited to {MaxTargets} items at a time.");

        return distinct;
    }

    public static IReadOnlyList<GlobalBulkSiteGroup> GroupBySite(IEnumerable<GlobalBulkContentTarget>? targets)
    {
        return NormalizeTargets(targets)
            .GroupBy(x => x.SiteId)
            .Select(group => new GlobalBulkSiteGroup(group.Key, group.ToArray()))
            .OrderBy(group => group.SiteId)
            .ToArray();
    }
}

public sealed record GlobalBulkContentTarget(
    Guid SiteId,
    string ContentType,
    int WordPressId,
    string Title);

public sealed record GlobalBulkSiteGroup(
    Guid SiteId,
    IReadOnlyList<GlobalBulkContentTarget> Targets);
