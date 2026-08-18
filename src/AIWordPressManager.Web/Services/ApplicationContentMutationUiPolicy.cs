namespace AIWordPressManager.Web.Services;

/// <summary>
/// Identifies interactive content workspaces where event-driven controls can mutate WordPress
/// content. This is a presentation-layer affordance policy; service authorization remains the
/// authoritative security boundary.
/// </summary>
public static class ApplicationContentMutationUiPolicy
{
    private static readonly HashSet<string> GlobalContentPaths = new(StringComparer.OrdinalIgnoreCase)
    {
        "/content",
        "/module/posts",
        "/module/pages",
        "/module/media",
        "/module/taxonomy",
        "/module/comments",
        "/module/users"
    };

    private static readonly HashSet<string> SiteContentSegments = new(StringComparer.OrdinalIgnoreCase)
    {
        "explorer",
        "content",
        "posts",
        "pages",
        "media",
        "taxonomy",
        "comments",
        "users"
    };

    public static bool RequiresContentEdit(string? path)
    {
        if (string.IsNullOrWhiteSpace(path))
            return false;

        var normalized = Normalize(path);
        if (GlobalContentPaths.Any(configured => Matches(configured, normalized)))
            return true;

        var segments = normalized.Split('/', StringSplitOptions.RemoveEmptyEntries);
        return segments.Length >= 3 &&
               string.Equals(segments[0], "sites", StringComparison.OrdinalIgnoreCase) &&
               Guid.TryParse(segments[1], out _) &&
               SiteContentSegments.Contains(segments[2]);
    }

    private static bool Matches(string configuredPath, string candidatePath) =>
        string.Equals(configuredPath, candidatePath, StringComparison.OrdinalIgnoreCase) ||
        candidatePath.StartsWith(configuredPath + "/", StringComparison.OrdinalIgnoreCase);

    private static string Normalize(string path)
    {
        var queryIndex = path.IndexOfAny(['?', '#']);
        if (queryIndex >= 0)
            path = path[..queryIndex];

        if (!path.StartsWith('/'))
            path = "/" + path;

        return path.Length > 1 ? path.TrimEnd('/') : path;
    }
}
