using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressCommentsWebService(
    AppDbContext dbContext,
    IHttpClientFactory httpClientFactory,
    ISecretProtectionService secretProtectionService)
{
    public async Task<CommentPageResult> GetAsync(Guid siteId, string status = "all", string search = "", int page = 1, int perPage = 30, CancellationToken ct = default)
    {
        var connection = await CreateConnectionAsync(siteId, ct);
        var query = new List<string>
        {
            $"page={Math.Max(1, page)}",
            $"per_page={Math.Clamp(perPage, 1, 100)}",
            "context=edit",
            "orderby=date",
            "order=desc"
        };
        if (!string.IsNullOrWhiteSpace(status) && status != "all") query.Add($"status={Uri.EscapeDataString(status)}");
        if (!string.IsNullOrWhiteSpace(search)) query.Add($"search={Uri.EscapeDataString(search.Trim())}");

        using var response = await connection.Client.GetAsync($"{connection.RootUrl}/wp-json/wp/v2/comments?{string.Join('&', query)}", ct);
        if (!response.IsSuccessStatusCode)
            return new(false, await ReadErrorAsync(response, "تعذر تحميل تعليقات WordPress.", ct), Array.Empty<WordPressCommentView>(), 0, 1);

        var body = await response.Content.ReadAsStringAsync(ct);
        using var json = JsonDocument.Parse(body);
        var comments = new List<WordPressCommentView>();
        foreach (var item in json.RootElement.EnumerateArray())
        {
            comments.Add(new WordPressCommentView(
                GetInt(item, "id"), GetInt(item, "post"), GetInt(item, "parent"),
                GetString(item, "author_name"), GetString(item, "author_email"), GetString(item, "author_url"),
                GetRendered(item, "content"), GetString(item, "status"), GetString(item, "link"),
                GetDate(item, "date_gmt"), GetString(item, "type")));
        }

        var total = TryHeaderInt(response, "X-WP-Total");
        var pages = Math.Max(1, TryHeaderInt(response, "X-WP-TotalPages"));
        return new(true, string.Empty, comments, total, pages);
    }

    public Task<CommentOperationResult> ApproveAsync(Guid siteId, int commentId, CancellationToken ct = default) => UpdateStatusAsync(siteId, commentId, "approved", ct);
    public Task<CommentOperationResult> HoldAsync(Guid siteId, int commentId, CancellationToken ct = default) => UpdateStatusAsync(siteId, commentId, "hold", ct);
    public Task<CommentOperationResult> SpamAsync(Guid siteId, int commentId, CancellationToken ct = default) => UpdateStatusAsync(siteId, commentId, "spam", ct);
    public Task<CommentOperationResult> TrashAsync(Guid siteId, int commentId, CancellationToken ct = default) => UpdateStatusAsync(siteId, commentId, "trash", ct);

    public async Task<CommentOperationResult> ReplyAsync(Guid siteId, int postId, int parentId, string contentText, CancellationToken ct = default)
    {
        if (postId <= 0 || parentId <= 0) return new(false, "بيانات التعليق غير صحيحة.");
        if (string.IsNullOrWhiteSpace(contentText)) return new(false, "نص الرد مطلوب.");
        var connection = await CreateConnectionAsync(siteId, ct);
        var payload = new { post = postId, parent = parentId, content = contentText.Trim(), status = "approved" };
        using var content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
        using var response = await connection.Client.PostAsync($"{connection.RootUrl}/wp-json/wp/v2/comments", content, ct);
        if (!response.IsSuccessStatusCode)
            return new(false, await ReadErrorAsync(response, "تعذر إرسال الرد إلى WordPress.", ct));
        return new(true, "تم إرسال الرد إلى WordPress بنجاح.");
    }

    public async Task<CommentOperationResult> DeleteAsync(Guid siteId, int commentId, bool force, CancellationToken ct = default)
    {
        if (commentId <= 0) return new(false, "رقم التعليق غير صحيح.");
        var connection = await CreateConnectionAsync(siteId, ct);
        using var response = await connection.Client.DeleteAsync($"{connection.RootUrl}/wp-json/wp/v2/comments/{commentId}?force={force.ToString().ToLowerInvariant()}", ct);
        if (!response.IsSuccessStatusCode)
            return new(false, await ReadErrorAsync(response, "تعذر حذف التعليق من WordPress.", ct));
        return new(true, force ? "تم حذف التعليق نهائيًا." : "تم نقل التعليق إلى سلة المهملات.");
    }

    private async Task<CommentOperationResult> UpdateStatusAsync(Guid siteId, int commentId, string status, CancellationToken ct)
    {
        if (commentId <= 0) return new(false, "رقم التعليق غير صحيح.");
        var connection = await CreateConnectionAsync(siteId, ct);
        using var content = new StringContent(JsonSerializer.Serialize(new { status }), Encoding.UTF8, "application/json");
        using var response = await connection.Client.PostAsync($"{connection.RootUrl}/wp-json/wp/v2/comments/{commentId}", content, ct);
        if (!response.IsSuccessStatusCode)
            return new(false, await ReadErrorAsync(response, "تعذر تحديث حالة التعليق.", ct));
        return new(true, "تم تحديث حالة التعليق بنجاح.");
    }

    private async Task<ConnectionContext> CreateConnectionAsync(Guid siteId, CancellationToken ct)
    {
        var site = await dbContext.Sites.AsNoTracking().FirstOrDefaultAsync(x => x.Id == siteId && !x.IsDeleted, ct)
            ?? throw new InvalidOperationException("الموقع غير موجود.");
        var credential = await dbContext.SiteCredentials.AsNoTracking().FirstOrDefaultAsync(x => x.SiteId == siteId, ct)
            ?? throw new InvalidOperationException("احفظ بيانات اتصال WordPress واختبرها أولًا.");
        var password = await secretProtectionService.UnprotectAsync(credential.ProtectedApplicationPassword, ct);
        var client = httpClientFactory.CreateClient(nameof(WordPressCommentsWebService));
        client.Timeout = TimeSpan.FromMinutes(2);
        client.DefaultRequestHeaders.UserAgent.ParseAdd("AIWordPressManager/1.0");
        var raw = $"{credential.UserName}:{password.Replace(" ", string.Empty, StringComparison.Ordinal)}";
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Basic", Convert.ToBase64String(Encoding.UTF8.GetBytes(raw)));
        return new(client, site.SiteUrl.TrimEnd('/'));
    }

    private static int TryHeaderInt(HttpResponseMessage response, string name) =>
        response.Headers.TryGetValues(name, out var values) && int.TryParse(values.FirstOrDefault(), out var value) ? value : 0;
    private static int GetInt(JsonElement item, string name) => item.TryGetProperty(name, out var node) && node.TryGetInt32(out var value) ? value : 0;
    private static string GetString(JsonElement item, string name) => item.TryGetProperty(name, out var node) ? node.GetString() ?? string.Empty : string.Empty;
    private static DateTime? GetDate(JsonElement item, string name) => DateTime.TryParse(GetString(item, name), out var value) ? DateTime.SpecifyKind(value, DateTimeKind.Utc) : null;
    private static string GetRendered(JsonElement item, string name) => item.TryGetProperty(name, out var node) && node.TryGetProperty("rendered", out var rendered) ? rendered.GetString() ?? string.Empty : string.Empty;
    private static async Task<string> ReadErrorAsync(HttpResponseMessage response, string fallback, CancellationToken ct)
    {
        var body = await response.Content.ReadAsStringAsync(ct);
        try
        {
            using var json = JsonDocument.Parse(body);
            if (json.RootElement.TryGetProperty("message", out var message) && !string.IsNullOrWhiteSpace(message.GetString())) return message.GetString()!;
        }
        catch (JsonException) { }
        return $"{fallback} HTTP {(int)response.StatusCode}";
    }
    private sealed record ConnectionContext(HttpClient Client, string RootUrl);
}

public sealed record WordPressCommentView(int Id, int PostId, int ParentId, string AuthorName, string AuthorEmail, string AuthorUrl, string Content, string Status, string Link, DateTime? DateUtc, string Type);
public sealed record CommentPageResult(bool Success, string Message, IReadOnlyList<WordPressCommentView> Items, int Total, int TotalPages);
public sealed record CommentOperationResult(bool Success, string Message);
