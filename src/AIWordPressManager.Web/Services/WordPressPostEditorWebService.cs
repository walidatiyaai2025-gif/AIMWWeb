using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Application.Common.Results;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressPostEditorWebService(
    AppDbContext dbContext,
    IHttpClientFactory httpClientFactory,
    ISecretProtectionService secretProtectionService) : IWordPressPostEditorService
{
    public async Task<Result<WordPressEditableContent>> GetAsync(Guid siteId, string contentType, int wordPressId, CancellationToken cancellationToken = default)
    {
        try
        {
            var connection = await CreateConnectionAsync(siteId, cancellationToken);
            if (connection.IsFailure) return Result.Failure<WordPressEditableContent>(connection.Error);

            var endpoint = BuildEndpoint(connection.Value.RootUrl, contentType, wordPressId);
            using var response = await connection.Value.Client.GetAsync($"{endpoint}?context=edit", cancellationToken);
            if (!response.IsSuccessStatusCode)
                return Result.Failure<WordPressEditableContent>(await CreateHttpErrorAsync(response, "تعذر تحميل المحتوى من WordPress.", cancellationToken));

            await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            using var json = await JsonDocument.ParseAsync(stream, cancellationToken: cancellationToken);
            var item = json.RootElement;

            var editable = new WordPressEditableContent(
                NormalizeType(contentType), GetInt(item, "id"), GetRaw(item, "title"), GetString(item, "slug"), GetString(item, "status"),
                GetRaw(item, "content"), GetRaw(item, "excerpt"), GetString(item, "link"), GetDate(item, "date_gmt"), GetDate(item, "modified_gmt"),
                GetInt(item, "featured_media"), GetIntArray(item, "categories"), GetIntArray(item, "tags"), GetString(item, "template"),
                GetInt(item, "author"), GetString(item, "comment_status"), GetString(item, "ping_status"), GetString(item, "format"),
                GetBool(item, "sticky"), !string.IsNullOrEmpty(GetString(item, "password")), item.GetRawText());

            return Result.Success(editable);
        }
        catch (Exception ex)
        {
            return Result.Failure<WordPressEditableContent>(Error.Failure(ex.Message));
        }
    }

    public async Task<Result<WordPressContentUpdateResult>> UpdateAsync(Guid siteId, WordPressContentUpdateRequest request, CancellationToken cancellationToken = default)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(request.Title))
                return Result.Failure<WordPressContentUpdateResult>(Error.Validation("عنوان المحتوى مطلوب."));

            var connection = await CreateConnectionAsync(siteId, cancellationToken);
            if (connection.IsFailure) return Result.Failure<WordPressContentUpdateResult>(connection.Error);

            var payload = new Dictionary<string, object?>
            {
                ["title"] = request.Title.Trim(), ["slug"] = request.Slug.Trim(), ["status"] = request.Status,
                ["content"] = request.Content, ["excerpt"] = request.Excerpt, ["featured_media"] = request.FeaturedMediaId,
                ["template"] = request.Template, ["comment_status"] = request.CommentStatus, ["ping_status"] = request.PingStatus
            };
            if (request.DateGmt.HasValue) payload["date_gmt"] = request.DateGmt.Value.UtcDateTime.ToString("O");
            if (NormalizeType(request.ContentType) == "post")
            {
                payload["categories"] = request.CategoryIds;
                payload["tags"] = request.TagIds;
                payload["format"] = request.Format;
                payload["sticky"] = request.Sticky;
            }

            var endpoint = BuildEndpoint(connection.Value.RootUrl, request.ContentType, request.Id);
            using var body = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
            using var response = await connection.Value.Client.PostAsync(endpoint, body, cancellationToken);
            if (!response.IsSuccessStatusCode)
                return Result.Failure<WordPressContentUpdateResult>(await CreateHttpErrorAsync(response, "تعذر حفظ التعديلات في WordPress.", cancellationToken));

            await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            using var json = await JsonDocument.ParseAsync(stream, cancellationToken: cancellationToken);
            var item = json.RootElement;
            var result = new WordPressContentUpdateResult(true, "تم حفظ التعديلات في WordPress بنجاح.", string.Empty,
                GetInt(item, "id"), GetString(item, "status"), GetString(item, "link"), GetDate(item, "modified_gmt"));
            return Result.Success(result);
        }
        catch (Exception ex)
        {
            return Result.Failure<WordPressContentUpdateResult>(Error.Failure(ex.Message));
        }
    }

    private async Task<Result<ConnectionContext>> CreateConnectionAsync(Guid siteId, CancellationToken ct)
    {
        var site = await dbContext.Sites.AsNoTracking().FirstOrDefaultAsync(x => x.Id == siteId && !x.IsDeleted, ct);
        if (site is null) return Result.Failure<ConnectionContext>(Error.NotFound("الموقع غير موجود."));
        var credential = await dbContext.SiteCredentials.AsNoTracking().FirstOrDefaultAsync(x => x.SiteId == siteId, ct);
        if (credential is null) return Result.Failure<ConnectionContext>(Error.Validation("احفظ بيانات اتصال WordPress واختبرها أولًا."));

        var password = await secretProtectionService.UnprotectAsync(credential.ProtectedApplicationPassword, ct);
        var client = httpClientFactory.CreateClient(nameof(WordPressPostEditorWebService));
        client.Timeout = TimeSpan.FromMinutes(2);
        client.DefaultRequestHeaders.UserAgent.ParseAdd("AIWordPressManager/1.0");
        var raw = $"{credential.UserName}:{password.Replace(" ", string.Empty, StringComparison.Ordinal)}";
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Basic", Convert.ToBase64String(Encoding.UTF8.GetBytes(raw)));
        return Result.Success(new ConnectionContext(client, site.SiteUrl.TrimEnd('/')));
    }

    private static string BuildEndpoint(string root, string type, int id) => $"{root}/wp-json/wp/v2/{(NormalizeType(type) == "page" ? "pages" : "posts")}/{id}";
    private static string NormalizeType(string? type) => string.Equals(type, "page", StringComparison.OrdinalIgnoreCase) ? "page" : "post";
    private static async Task<Error> CreateHttpErrorAsync(HttpResponseMessage response, string fallback, CancellationToken ct)
    {
        var body = await response.Content.ReadAsStringAsync(ct);
        if (response.StatusCode is System.Net.HttpStatusCode.Unauthorized or System.Net.HttpStatusCode.Forbidden)
            return Error.Forbidden("فشل تسجيل الدخول أو لا يملك المستخدم صلاحية تعديل المحتوى.");
        try
        {
            using var json = JsonDocument.Parse(body);
            if (json.RootElement.TryGetProperty("message", out var message) && !string.IsNullOrWhiteSpace(message.GetString()))
                return Error.Failure(message.GetString()!);
        }
        catch (JsonException) { }
        return Error.Failure($"{fallback} HTTP {(int)response.StatusCode}");
    }

    private static int GetInt(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.TryGetInt32(out var result) ? result : 0;
    private static bool GetBool(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.ValueKind is JsonValueKind.True or JsonValueKind.False && value.GetBoolean();
    private static string GetString(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() ?? string.Empty : string.Empty;
    private static string GetRaw(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.Object && value.TryGetProperty("raw", out var raw) ? raw.GetString() ?? string.Empty : string.Empty;
    private static DateTimeOffset? GetDate(JsonElement item, string name) => DateTimeOffset.TryParse(GetString(item, name), out var value) ? value : null;
    private static IReadOnlyList<int> GetIntArray(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.Array ? value.EnumerateArray().Where(x => x.TryGetInt32(out _)).Select(x => x.GetInt32()).ToArray() : Array.Empty<int>();
    private sealed record ConnectionContext(HttpClient Client, string RootUrl);
}
