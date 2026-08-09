using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressPostEditorWebService(
    IWordPressApiClient apiClient,
    AppNotificationService notifications) : IWordPressPostEditorService
{
    public const string ConflictMessage = "This content changed in WordPress after you opened the editor. Reload the latest remote version before saving again.";
    private readonly Dictionary<(Guid SiteId, string ContentType, int WordPressId), DateTimeOffset?> _loadedVersions = [];

    public async Task<Result<WordPressEditableContent>> GetAsync(
        Guid siteId,
        string contentType,
        int wordPressId,
        CancellationToken cancellationToken = default)
    {
        var result = await FetchAsync(siteId, contentType, wordPressId, cancellationToken);
        if (result.IsSuccess)
            _loadedVersions[VersionKey(siteId, contentType, wordPressId)] = result.Value.ModifiedGmt;
        return result;
    }

    public async Task<Result<WordPressContentUpdateResult>> UpdateAsync(
        Guid siteId,
        WordPressContentUpdateRequest request,
        CancellationToken cancellationToken = default)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(request.Title))
            {
                const string validationMessage = "Content title is required.";
                notifications.Warning(validationMessage, "Validation");
                return Result.Failure<WordPressContentUpdateResult>(Error.Validation(validationMessage));
            }

            if (!IsSupportedStatus(request.Status))
            {
                const string validationMessage = "Select a supported WordPress content status before saving.";
                notifications.Warning(validationMessage, "Validation");
                return Result.Failure<WordPressContentUpdateResult>(Error.Validation(validationMessage));
            }

            var key = VersionKey(siteId, request.ContentType, request.Id);
            var expectedModifiedGmt = request.ExpectedModifiedGmt;
            if (!expectedModifiedGmt.HasValue && _loadedVersions.TryGetValue(key, out var loadedVersion))
                expectedModifiedGmt = loadedVersion;

            if (!request.ForceOverwrite && expectedModifiedGmt.HasValue)
            {
                var latest = await FetchAsync(siteId, request.ContentType, request.Id, cancellationToken);
                if (latest.IsFailure)
                    return Result.Failure<WordPressContentUpdateResult>(latest.Error);

                if (HasRemoteChanged(expectedModifiedGmt, latest.Value.ModifiedGmt))
                {
                    notifications.Warning(ConflictMessage, "Content conflict");
                    return Result.Failure<WordPressContentUpdateResult>(Error.Conflict(ConflictMessage));
                }
            }

            notifications.Info(
                $"Sending {NormalizeType(request.ContentType)} #{request.Id} changes to WordPress.",
                "Saving content");

            var payload = new Dictionary<string, object?>
            {
                ["title"] = request.Title.Trim(),
                ["slug"] = request.Slug.Trim(),
                ["status"] = request.Status,
                ["content"] = request.Content,
                ["excerpt"] = request.Excerpt,
                ["featured_media"] = request.FeaturedMediaId,
                ["template"] = request.Template,
                ["comment_status"] = request.CommentStatus,
                ["ping_status"] = request.PingStatus
            };

            if (request.DateGmt.HasValue)
                payload["date_gmt"] = request.DateGmt.Value.UtcDateTime.ToString("O");

            if (NormalizeType(request.ContentType) == "post")
            {
                payload["categories"] = request.CategoryIds;
                payload["tags"] = request.TagIds;
                payload["format"] = request.Format;
                payload["sticky"] = request.Sticky;
            }

            var response = await apiClient.SendAsync(
                siteId,
                HttpMethod.Post,
                BuildEndpoint(request.ContentType, request.Id),
                payload,
                cancellationToken);

            if (!response.IsSuccess || response.Value is null)
            {
                var error = ToError(response.ErrorMessage, response.StatusCode);
                notifications.Error(
                    error.Message,
                    "WordPress update failed",
                    $"HTTP {(int)response.StatusCode} ({response.StatusCode})");
                return Result.Failure<WordPressContentUpdateResult>(error);
            }

            using var json = response.Value;
            var item = json.RootElement;
            var id = GetInt(item, "id");
            var status = GetString(item, "status");
            var link = GetString(item, "link");
            var modifiedGmt = GetDate(item, "modified_gmt");
            const string successMessage = "Content was updated in WordPress successfully.";

            _loadedVersions[key] = modifiedGmt;
            notifications.Success(
                $"Content #{id} was saved with status '{status}'.",
                "WordPress updated");

            return Result.Success(new WordPressContentUpdateResult(
                true,
                successMessage,
                string.Empty,
                id,
                status,
                link,
                modifiedGmt));
        }
        catch (Exception ex)
        {
            notifications.Error(ex.Message, "WordPress update failed", ex.ToString());
            return Result.Failure<WordPressContentUpdateResult>(Error.Failure(ex.Message));
        }
    }

    public static bool HasRemoteChanged(DateTimeOffset? expectedModifiedGmt, DateTimeOffset? remoteModifiedGmt)
    {
        if (!expectedModifiedGmt.HasValue || !remoteModifiedGmt.HasValue)
            return false;

        return expectedModifiedGmt.Value.ToUniversalTime() != remoteModifiedGmt.Value.ToUniversalTime();
    }

    private async Task<Result<WordPressEditableContent>> FetchAsync(
        Guid siteId,
        string contentType,
        int wordPressId,
        CancellationToken cancellationToken)
    {
        try
        {
            var endpoint = BuildEndpoint(contentType, wordPressId) + "?context=edit";
            var response = await apiClient.GetAsync(siteId, endpoint, cancellationToken);
            if (!response.IsSuccess || response.Value is null)
                return Result.Failure<WordPressEditableContent>(ToError(response.ErrorMessage, response.StatusCode));

            using var json = response.Value;
            var item = json.RootElement;
            return Result.Success(new WordPressEditableContent(
                NormalizeType(contentType),
                GetInt(item, "id"),
                GetRaw(item, "title"),
                GetString(item, "slug"),
                GetString(item, "status"),
                GetRaw(item, "content"),
                GetRaw(item, "excerpt"),
                GetString(item, "link"),
                GetDate(item, "date_gmt"),
                GetDate(item, "modified_gmt"),
                GetInt(item, "featured_media"),
                GetIntArray(item, "categories"),
                GetIntArray(item, "tags"),
                GetString(item, "template"),
                GetInt(item, "author"),
                GetString(item, "comment_status"),
                GetString(item, "ping_status"),
                GetString(item, "format"),
                GetBool(item, "sticky"),
                !string.IsNullOrEmpty(GetString(item, "password")),
                item.GetRawText()));
        }
        catch (Exception ex)
        {
            return Result.Failure<WordPressEditableContent>(Error.Failure(ex.Message));
        }
    }

    private static (Guid SiteId, string ContentType, int WordPressId) VersionKey(Guid siteId, string contentType, int wordPressId) =>
        (siteId, NormalizeType(contentType), wordPressId);

    private static bool IsSupportedStatus(string? status) =>
        status is "draft" or "pending" or "publish" or "future" or "private";

    private static string BuildEndpoint(string type, int id) =>
        $"/wp-json/wp/v2/{(NormalizeType(type) == "page" ? "pages" : "posts")}/{id}";

    private static string NormalizeType(string? type) =>
        string.Equals(type, "page", StringComparison.OrdinalIgnoreCase) ? "page" : "post";

    private static Error ToError(string message, System.Net.HttpStatusCode statusCode) =>
        statusCode is System.Net.HttpStatusCode.Unauthorized or System.Net.HttpStatusCode.Forbidden
            ? Error.Forbidden(message)
            : statusCode == System.Net.HttpStatusCode.NotFound
                ? Error.NotFound(message)
                : Error.Failure(message);

    private static int GetInt(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.TryGetInt32(out var result) ? result : 0;

    private static bool GetBool(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) &&
        value.ValueKind is JsonValueKind.True or JsonValueKind.False && value.GetBoolean();

    private static string GetString(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String
            ? value.GetString() ?? string.Empty
            : string.Empty;

    private static string GetRaw(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) &&
        value.ValueKind == JsonValueKind.Object &&
        value.TryGetProperty("raw", out var raw)
            ? raw.GetString() ?? string.Empty
            : string.Empty;

    private static DateTimeOffset? GetDate(JsonElement item, string name) =>
        DateTimeOffset.TryParse(GetString(item, name), out var value) ? value : null;

    private static IReadOnlyList<int> GetIntArray(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.Array
            ? value.EnumerateArray().Where(x => x.TryGetInt32(out _)).Select(x => x.GetInt32()).ToArray()
            : Array.Empty<int>();
}
