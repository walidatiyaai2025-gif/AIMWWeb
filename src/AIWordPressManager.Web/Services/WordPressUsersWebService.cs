using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressUsersWebService(
    AppDbContext dbContext,
    IHttpClientFactory httpClientFactory,
    ISecretProtectionService secretProtectionService)
{
    public async Task<UserPageResult> GetAsync(Guid siteId, string search = "", string role = "all", int page = 1, int perPage = 50, CancellationToken ct = default)
    {
        var connection = await CreateConnectionAsync(siteId, ct);
        var query = new List<string>
        {
            $"page={Math.Max(1, page)}", $"per_page={Math.Clamp(perPage, 1, 100)}",
            "context=edit", "orderby=registered_date", "order=desc"
        };
        if (!string.IsNullOrWhiteSpace(search)) query.Add($"search={Uri.EscapeDataString(search.Trim())}");
        if (!string.IsNullOrWhiteSpace(role) && role != "all") query.Add($"roles={Uri.EscapeDataString(role)}");

        using var response = await connection.Client.GetAsync($"{connection.RootUrl}/wp-json/wp/v2/users?{string.Join('&', query)}", ct);
        if (!response.IsSuccessStatusCode)
            return new(false, await ReadErrorAsync(response, "تعذر تحميل مستخدمي WordPress.", ct), Array.Empty<WordPressUserView>(), 0, 1);

        using var json = JsonDocument.Parse(await response.Content.ReadAsStringAsync(ct));
        var items = new List<WordPressUserView>();
        foreach (var item in json.RootElement.EnumerateArray())
        {
            items.Add(new WordPressUserView(
                GetInt(item, "id"), GetString(item, "username"), GetString(item, "name"),
                GetString(item, "first_name"), GetString(item, "last_name"), GetString(item, "email"),
                GetString(item, "url"), GetString(item, "description"), GetString(item, "link"),
                GetStringArray(item, "roles"), GetAvatar(item), GetDate(item, "registered_date")));
        }
        return new(true, string.Empty, items, TryHeaderInt(response, "X-WP-Total"), Math.Max(1, TryHeaderInt(response, "X-WP-TotalPages")));
    }

    public async Task<UserOperationResult> CreateAsync(Guid siteId, WordPressUserEditModel model, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(model.Username)) return new(false, "اسم المستخدم مطلوب.");
        if (string.IsNullOrWhiteSpace(model.Email)) return new(false, "البريد الإلكتروني مطلوب.");
        if (string.IsNullOrWhiteSpace(model.Password) || model.Password.Length < 6) return new(false, "كلمة المرور يجب ألا تقل عن 6 أحرف.");
        var connection = await CreateConnectionAsync(siteId, ct);
        var payload = new { username = model.Username.Trim(), email = model.Email.Trim(), password = model.Password, name = model.Name?.Trim(), first_name = model.FirstName?.Trim(), last_name = model.LastName?.Trim(), url = model.Url?.Trim(), description = model.Description?.Trim(), roles = model.Roles };
        return await SendAsync(connection, HttpMethod.Post, "/wp-json/wp/v2/users", payload, "تعذر إنشاء مستخدم WordPress.", "تم إنشاء المستخدم بنجاح.", ct);
    }

    public async Task<UserOperationResult> UpdateAsync(Guid siteId, int userId, WordPressUserEditModel model, CancellationToken ct = default)
    {
        if (userId <= 0) return new(false, "رقم المستخدم غير صحيح.");
        if (string.IsNullOrWhiteSpace(model.Email)) return new(false, "البريد الإلكتروني مطلوب.");
        var connection = await CreateConnectionAsync(siteId, ct);
        var payload = new Dictionary<string, object?>
        {
            ["email"] = model.Email.Trim(), ["name"] = model.Name?.Trim(), ["first_name"] = model.FirstName?.Trim(),
            ["last_name"] = model.LastName?.Trim(), ["url"] = model.Url?.Trim(), ["description"] = model.Description?.Trim(), ["roles"] = model.Roles
        };
        if (!string.IsNullOrWhiteSpace(model.Password)) payload["password"] = model.Password;
        return await SendAsync(connection, HttpMethod.Post, $"/wp-json/wp/v2/users/{userId}", payload, "تعذر تحديث المستخدم.", "تم تحديث المستخدم بنجاح.", ct);
    }

    public async Task<UserOperationResult> RemoveRolesAsync(Guid siteId, int userId, CancellationToken ct = default)
    {
        var connection = await CreateConnectionAsync(siteId, ct);
        var currentId = await GetCurrentUserIdAsync(connection, ct);
        if (currentId == userId) return new(false, "لا يمكن تعطيل حساب المستخدم الحالي.");
        return await SendAsync(connection, HttpMethod.Post, $"/wp-json/wp/v2/users/{userId}", new { roles = Array.Empty<string>() }, "تعذر تعطيل المستخدم.", "تم تعطيل المستخدم بإزالة جميع أدواره.", ct);
    }

    public async Task<UserOperationResult> DeleteAsync(Guid siteId, int userId, CancellationToken ct = default)
    {
        var connection = await CreateConnectionAsync(siteId, ct);
        var currentId = await GetCurrentUserIdAsync(connection, ct);
        if (currentId == userId) return new(false, "لا يمكن حذف حساب المستخدم الحالي.");
        using var response = await connection.Client.DeleteAsync($"{connection.RootUrl}/wp-json/wp/v2/users/{userId}?force=true&reassign={currentId}", ct);
        if (!response.IsSuccessStatusCode) return new(false, await ReadErrorAsync(response, "تعذر حذف المستخدم.", ct));
        return new(true, "تم حذف المستخدم وإعادة إسناد محتواه إلى الحساب الحالي.");
    }

    private static async Task<UserOperationResult> SendAsync(ConnectionContext connection, HttpMethod method, string path, object payload, string fallback, string success, CancellationToken ct)
    {
        using var request = new HttpRequestMessage(method, connection.RootUrl + path) { Content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json") };
        using var response = await connection.Client.SendAsync(request, ct);
        return response.IsSuccessStatusCode ? new(true, success) : new(false, await ReadErrorAsync(response, fallback, ct));
    }

    private static async Task<int> GetCurrentUserIdAsync(ConnectionContext connection, CancellationToken ct)
    {
        using var response = await connection.Client.GetAsync($"{connection.RootUrl}/wp-json/wp/v2/users/me?context=edit", ct);
        if (!response.IsSuccessStatusCode) throw new InvalidOperationException("تعذر تحديد المستخدم الحالي.");
        using var json = JsonDocument.Parse(await response.Content.ReadAsStringAsync(ct));
        return GetInt(json.RootElement, "id");
    }

    private async Task<ConnectionContext> CreateConnectionAsync(Guid siteId, CancellationToken ct)
    {
        var site = await dbContext.Sites.AsNoTracking().FirstOrDefaultAsync(x => x.Id == siteId && !x.IsDeleted, ct) ?? throw new InvalidOperationException("الموقع غير موجود.");
        var credential = await dbContext.SiteCredentials.AsNoTracking().FirstOrDefaultAsync(x => x.SiteId == siteId, ct) ?? throw new InvalidOperationException("احفظ بيانات اتصال WordPress واختبرها أولًا.");
        var password = await secretProtectionService.UnprotectAsync(credential.ProtectedApplicationPassword, ct);
        var client = httpClientFactory.CreateClient(nameof(WordPressUsersWebService));
        client.Timeout = TimeSpan.FromMinutes(2);
        client.DefaultRequestHeaders.UserAgent.ParseAdd("AIWordPressManager/1.0");
        var raw = $"{credential.UserName}:{password.Replace(" ", string.Empty, StringComparison.Ordinal)}";
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Basic", Convert.ToBase64String(Encoding.UTF8.GetBytes(raw)));
        return new(client, site.SiteUrl.TrimEnd('/'));
    }

    private static int TryHeaderInt(HttpResponseMessage response, string name) => response.Headers.TryGetValues(name, out var values) && int.TryParse(values.FirstOrDefault(), out var value) ? value : 0;
    private static int GetInt(JsonElement item, string name) => item.TryGetProperty(name, out var node) && node.TryGetInt32(out var value) ? value : 0;
    private static string GetString(JsonElement item, string name) => item.TryGetProperty(name, out var node) ? node.GetString() ?? string.Empty : string.Empty;
    private static DateTime? GetDate(JsonElement item, string name) => DateTime.TryParse(GetString(item, name), out var value) ? value : null;
    private static IReadOnlyList<string> GetStringArray(JsonElement item, string name) => item.TryGetProperty(name, out var node) && node.ValueKind == JsonValueKind.Array ? node.EnumerateArray().Select(x => x.GetString() ?? string.Empty).Where(x => x.Length > 0).ToArray() : Array.Empty<string>();
    private static string GetAvatar(JsonElement item)
    {
        if (!item.TryGetProperty("avatar_urls", out var avatars)) return string.Empty;
        foreach (var size in new[] { "96", "48", "24" }) if (avatars.TryGetProperty(size, out var url)) return url.GetString() ?? string.Empty;
        return string.Empty;
    }
    private static async Task<string> ReadErrorAsync(HttpResponseMessage response, string fallback, CancellationToken ct)
    {
        var body = await response.Content.ReadAsStringAsync(ct);
        try { using var json = JsonDocument.Parse(body); if (json.RootElement.TryGetProperty("message", out var message) && !string.IsNullOrWhiteSpace(message.GetString())) return message.GetString()!; } catch (JsonException) { }
        return $"{fallback} HTTP {(int)response.StatusCode}";
    }
    private sealed record ConnectionContext(HttpClient Client, string RootUrl);
}

public sealed record WordPressUserView(int Id, string Username, string Name, string FirstName, string LastName, string Email, string Url, string Description, string Link, IReadOnlyList<string> Roles, string AvatarUrl, DateTime? RegisteredAt);
public sealed record UserPageResult(bool Success, string Message, IReadOnlyList<WordPressUserView> Items, int Total, int TotalPages);
public sealed record UserOperationResult(bool Success, string Message);
public sealed class WordPressUserEditModel
{
    public string Username { get; set; } = string.Empty;
    public string Email { get; set; } = string.Empty;
    public string Password { get; set; } = string.Empty;
    public string Name { get; set; } = string.Empty;
    public string FirstName { get; set; } = string.Empty;
    public string LastName { get; set; } = string.Empty;
    public string Url { get; set; } = string.Empty;
    public string Description { get; set; } = string.Empty;
    public string[] Roles { get; set; } = ["subscriber"];
}
