using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressCommentsWebService(IWordPressApiClient apiClient)
{
    public async Task<CommentPageResult> GetAsync(
        Guid siteId,
        string status = "all",
        string search = "",
        int page = 1,
        int perPage = 30,
        CancellationToken ct = default)
    {
        var query = new List<string>
        {
            $"page={Math.Max(1, page)}",
            $"per_page={Math.Clamp(perPage, 1, 100)}",
            "context=edit",
            "orderby=date",
            "order=desc"
        };

        if (!string.IsNullOrWhiteSpace(status) && status != "all")
            query.Add($"status={Uri.EscapeDataString(status)}");
        if (!string.IsNullOrWhiteSpace(search))
            query.Add($"search={Uri.EscapeDataString(search.Trim())}");

        var response = await apiClient.GetAsync(
            siteId,
            $"/wp-json/wp/v2/comments?{string.Join('&', query)}",
            ct);

        if (!response.IsSuccess || response.Value is null)
            return new(false, response.ErrorMessage, Array.Empty<WordPressCommentView>(), 0, 1);

        using var json = response.Value;
        var comments = new List<WordPressCommentView>();
        foreach (var item in json.RootElement.EnumerateArray())
        {
            comments.Add(new WordPressCommentView(
                GetInt(item, "id"),
                GetInt(item, "post"),
                GetInt(item, "parent"),
                GetString(item, "author_name"),
                GetString(item, "author_email"),
                GetString(item, "author_url"),
                GetRendered(item, "content"),
                GetString(item, "status"),
                GetString(item, "link"),
                GetDate(item, "date_gmt"),
                GetString(item, "type")));
        }

        return new(
            true,
            string.Empty,
            comments,
            TryHeaderInt(response.Headers, "X-WP-Total"),
            Math.Max(1, TryHeaderInt(response.Headers, "X-WP-TotalPages")));
    }

    public Task<CommentOperationResult> ApproveAsync(Guid siteId, int commentId, CancellationToken ct = default) =>
        UpdateStatusAsync(siteId, commentId, "approved", ct);

    public Task<CommentOperationResult> HoldAsync(Guid siteId, int commentId, CancellationToken ct = default) =>
        UpdateStatusAsync(siteId, commentId, "hold", ct);

    public Task<CommentOperationResult> SpamAsync(Guid siteId, int commentId, CancellationToken ct = default) =>
        UpdateStatusAsync(siteId, commentId, "spam", ct);

    public Task<CommentOperationResult> TrashAsync(Guid siteId, int commentId, CancellationToken ct = default) =>
        UpdateStatusAsync(siteId, commentId, "trash", ct);

    public async Task<CommentOperationResult> ReplyAsync(
        Guid siteId,
        int postId,
        int parentId,
        string contentText,
        CancellationToken ct = default)
    {
        if (postId <= 0 || parentId <= 0)
            return new(false, "بيانات التعليق غير صحيحة.");
        if (string.IsNullOrWhiteSpace(contentText))
            return new(false, "نص الرد مطلوب.");

        var response = await apiClient.SendAsync(
            siteId,
            HttpMethod.Post,
            "/wp-json/wp/v2/comments",
            new { post = postId, parent = parentId, content = contentText.Trim(), status = "approved" },
            ct);

        response.Value?.Dispose();
        return response.IsSuccess
            ? new(true, "تم إرسال الرد إلى WordPress بنجاح.")
            : new(false, response.ErrorMessage);
    }

    public async Task<CommentOperationResult> DeleteAsync(
        Guid siteId,
        int commentId,
        bool force,
        CancellationToken ct = default)
    {
        if (commentId <= 0)
            return new(false, "رقم التعليق غير صحيح.");

        var response = await apiClient.SendAsync(
            siteId,
            HttpMethod.Delete,
            $"/wp-json/wp/v2/comments/{commentId}?force={force.ToString().ToLowerInvariant()}",
            cancellationToken: ct);

        response.Value?.Dispose();
        return response.IsSuccess
            ? new(true, force ? "تم حذف التعليق نهائيًا." : "تم نقل التعليق إلى سلة المهملات.")
            : new(false, response.ErrorMessage);
    }

    private async Task<CommentOperationResult> UpdateStatusAsync(
        Guid siteId,
        int commentId,
        string status,
        CancellationToken ct)
    {
        if (commentId <= 0)
            return new(false, "رقم التعليق غير صحيح.");

        var response = await apiClient.SendAsync(
            siteId,
            HttpMethod.Post,
            $"/wp-json/wp/v2/comments/{commentId}",
            new { status },
            ct);

        response.Value?.Dispose();
        return response.IsSuccess
            ? new(true, "تم تحديث حالة التعليق بنجاح.")
            : new(false, response.ErrorMessage);
    }

    private static int TryHeaderInt(IReadOnlyDictionary<string, string> headers, string name) =>
        headers.TryGetValue(name, out var value) && int.TryParse(value, out var result) ? result : 0;

    private static int GetInt(JsonElement item, string name) =>
        item.TryGetProperty(name, out var node) && node.TryGetInt32(out var value) ? value : 0;

    private static string GetString(JsonElement item, string name) =>
        item.TryGetProperty(name, out var node) && node.ValueKind == JsonValueKind.String
            ? node.GetString() ?? string.Empty
            : string.Empty;

    private static DateTime? GetDate(JsonElement item, string name) =>
        DateTime.TryParse(GetString(item, name), out var value)
            ? DateTime.SpecifyKind(value, DateTimeKind.Utc)
            : null;

    private static string GetRendered(JsonElement item, string name) =>
        item.TryGetProperty(name, out var node) &&
        node.ValueKind == JsonValueKind.Object &&
        node.TryGetProperty("rendered", out var rendered)
            ? rendered.GetString() ?? string.Empty
            : string.Empty;
}

public sealed record WordPressCommentView(
    int Id,
    int PostId,
    int ParentId,
    string AuthorName,
    string AuthorEmail,
    string AuthorUrl,
    string Content,
    string Status,
    string Link,
    DateTime? DateUtc,
    string Type);

public sealed record CommentPageResult(
    bool Success,
    string Message,
    IReadOnlyList<WordPressCommentView> Items,
    int Total,
    int TotalPages);

public sealed record CommentOperationResult(bool Success, string Message);
