namespace AIWordPressManager.Web.Services;

public static class SiteBulkOperationPolicy
{
    public const int MaxSitesPerOperation = 100;

    public static IReadOnlyList<Guid> NormalizeIds(IEnumerable<Guid>? siteIds)
    {
        if (siteIds is null)
            throw new InvalidOperationException("Select at least one site.");

        var normalized = siteIds
            .Where(id => id != Guid.Empty)
            .Distinct()
            .ToArray();

        if (normalized.Length == 0)
            throw new InvalidOperationException("Select at least one site.");

        if (normalized.Length > MaxSitesPerOperation)
            throw new InvalidOperationException($"A bulk site operation can include at most {MaxSitesPerOperation} sites.");

        return normalized;
    }

    public static bool AreAllVisibleSelected(IEnumerable<Guid> visibleSiteIds, IReadOnlySet<Guid> selectedSiteIds)
    {
        var visible = visibleSiteIds.Where(id => id != Guid.Empty).Distinct().ToArray();
        return visible.Length > 0 && visible.All(selectedSiteIds.Contains);
    }
}
