using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressPostEditorWebService(
    IWordPressApiClient apiClient) : IWordPressPostEditorService
{
    public async Task<Result<WordPressEditableContent>> GetAsync(
        Guid siteId,
        string contentType,
        int wordPressId,
        CancellationToken cancellationToken = default)
    {
        try
        {
            var endpoint = BuildEndpoint(contentType, wordPressId) + "?context=edit";
            var response = await apiClient.GetAsync(siteId, endpoint, cancellationToken);
            if (!response.IsSuccess || response.Value is null)
                return Result.Failure<WordPressEditableContent>(ToError(response.ErrorMessage, response.StatusCode));

            using var json = response.Value;
            var item = json.RootElement;
            var editable = new WordPressEditableContent(
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
                item.GetRawText());

            return Result.Success(editable);
        }
        catch (Exception ex)
        {
            return Result.Failure<WordPressEditableContent>(Error.Failure(ex.Message));
        }
    }

    public async Task<Result<WordPressContentUpdateResult>> UpdateAsync(
        Guid siteId,
        WordPressContentUpdateRequest request,
        CancellationToken cancellationToken = default)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(request.Title))
                return Result.Failure<WordPressContentUpdateResult>(Error.Validation("عنوان المحتوى مطلوب."));

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
                return Result.Failure<WordPressContentUpdateResult>(ToError(response.ErrorMessage, response.StatusCode));

            using var json = response.Value;
            var item = json.RootElement;
            return Result.Success(new WordPressContentUpdateResult(
                true,
                "تم حفظ التعديلات في WordPress بنجاح.",
                string.Empty,
                GetInt(item, "id"),
                GetString(item, "status"),
                GetString(item, "link"),
                GetDate(item, "modified_gmt")));
        }
        catch (Exception ex)
        {
            return Result.Failure<WordPressContentUpdateResult>(Error.Failure(ex.Message));
        }
    }

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
