namespace AIWordPressManager.Web.Services;

public static class SyncConflictPolicy
{
    public static SyncConflictReview BuildReview(
        IEnumerable<SyncComparableContent>? localItems,
        IEnumerable<SyncComparableContent>? remoteItems,
        DateTime? localSynchronizedAtUtc)
    {
        var local = Normalize(localItems);
        var remote = Normalize(remoteItems);
        var localMap = local.ToDictionary(Key);
        var remoteMap = remote.ToDictionary(Key);
        var conflicts = new List<SyncConflictItem>();

        foreach (var localItem in local)
        {
            var key = Key(localItem);
            if (!remoteMap.TryGetValue(key, out var remoteItem))
            {
                conflicts.Add(new SyncConflictItem(
                    localItem.ContentType,
                    localItem.WordPressId,
                    SyncConflictKind.RemoteDeleted,
                    ToVersion(localItem),
                    null));
                continue;
            }

            if (HasMeaningfulDifference(localItem, remoteItem))
            {
                conflicts.Add(new SyncConflictItem(
                    localItem.ContentType,
                    localItem.WordPressId,
                    SyncConflictKind.RemoteUpdated,
                    ToVersion(localItem),
                    ToVersion(remoteItem)));
            }
        }

        var additions = remote.Count(item => !localMap.ContainsKey(Key(item)));
        return new SyncConflictReview(
            local.Count > 0 || localSynchronizedAtUtc.HasValue,
            localSynchronizedAtUtc,
            additions,
            conflicts
                .OrderByDescending(x => x.Remote?.ModifiedAtUtc ?? x.Local.ModifiedAtUtc)
                .ThenBy(x => x.ContentType)
                .ThenBy(x => x.WordPressId)
                .ToArray());
    }

    public static bool HasMeaningfulDifference(SyncComparableContent local, SyncComparableContent remote)
    {
        ArgumentNullException.ThrowIfNull(local);
        ArgumentNullException.ThrowIfNull(remote);

        return !string.Equals(NormalizeType(local.ContentType), NormalizeType(remote.ContentType), StringComparison.Ordinal) ||
               local.WordPressId != remote.WordPressId ||
               !string.Equals(local.Title, remote.Title, StringComparison.Ordinal) ||
               !string.Equals(local.Slug, remote.Slug, StringComparison.Ordinal) ||
               !string.Equals(local.Status, remote.Status, StringComparison.Ordinal) ||
               !string.Equals(local.Link, remote.Link, StringComparison.Ordinal) ||
               !string.Equals(local.RenderedContent, remote.RenderedContent, StringComparison.Ordinal) ||
               !string.Equals(local.RenderedExcerpt, remote.RenderedExcerpt, StringComparison.Ordinal) ||
               !SameInstant(local.ModifiedAtUtc, remote.ModifiedAtUtc);
    }

    private static IReadOnlyList<SyncComparableContent> Normalize(IEnumerable<SyncComparableContent>? source)
    {
        return (source ?? [])
            .Where(x => x.WordPressId > 0 && NormalizeType(x.ContentType) is "post" or "page")
            .Select(x => x with
            {
                ContentType = NormalizeType(x.ContentType),
                Title = x.Title ?? string.Empty,
                Slug = x.Slug ?? string.Empty,
                Status = x.Status ?? string.Empty,
                Link = x.Link ?? string.Empty,
                RenderedContent = x.RenderedContent ?? string.Empty,
                RenderedExcerpt = x.RenderedExcerpt ?? string.Empty
            })
            .GroupBy(Key)
            .Select(group => group.OrderByDescending(x => x.ModifiedAtUtc).First())
            .ToArray();
    }

    private static string Key(SyncComparableContent item) =>
        $"{NormalizeType(item.ContentType)}:{item.WordPressId}";

    private static string NormalizeType(string? type) =>
        string.Equals(type, "page", StringComparison.OrdinalIgnoreCase) ? "page" :
        string.Equals(type, "post", StringComparison.OrdinalIgnoreCase) ? "post" : string.Empty;

    private static bool SameInstant(DateTimeOffset? left, DateTimeOffset? right)
    {
        if (!left.HasValue && !right.HasValue) return true;
        if (!left.HasValue || !right.HasValue) return false;
        return left.Value.ToUniversalTime() == right.Value.ToUniversalTime();
    }

    private static SyncConflictVersion ToVersion(SyncComparableContent item) => new(
        item.Title,
        item.Slug,
        item.Status,
        item.Link,
        item.RenderedContent,
        item.RenderedExcerpt,
        item.ModifiedAtUtc);
}

public static class SyncConflictKind
{
    public const string RemoteUpdated = "RemoteUpdated";
    public const string RemoteDeleted = "RemoteDeleted";
}

public sealed record SyncComparableContent(
    string ContentType,
    int WordPressId,
    string Title,
    string Slug,
    string Status,
    string Link,
    string RenderedContent,
    string RenderedExcerpt,
    DateTimeOffset? ModifiedAtUtc);

public sealed record SyncConflictReview(
    bool HasBaseline,
    DateTime? LocalSynchronizedAtUtc,
    int RemoteAdditions,
    IReadOnlyList<SyncConflictItem> Conflicts)
{
    public int RemoteUpdates => Conflicts.Count(x => x.Kind == SyncConflictKind.RemoteUpdated);
    public int RemoteDeletions => Conflicts.Count(x => x.Kind == SyncConflictKind.RemoteDeleted);
    public bool HasConflicts => Conflicts.Count > 0;
}

public sealed record SyncConflictItem(
    string ContentType,
    int WordPressId,
    string Kind,
    SyncConflictVersion Local,
    SyncConflictVersion? Remote);

public sealed record SyncConflictVersion(
    string Title,
    string Slug,
    string Status,
    string Link,
    string RenderedContent,
    string RenderedExcerpt,
    DateTimeOffset? ModifiedAtUtc);
