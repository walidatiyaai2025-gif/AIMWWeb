using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressUsersWebService(IWordPressApiClient apiClient)
{
    public async Task<UserPageResult> GetAsync(
        Guid siteId,
        string search = "",
        string role = "all",
        int page = 1,
        int perPage = 50,
        CancellationToken ct = default)
    {
        var query = new List<string>
        {
            $"page={Math.Max(1, page)}",
            $"per_page={Math.Clamp(perPage, 1, 100)}",
            "context=edit",
            "orderby=registered_date",
            "order=desc"
        };

        if (!string.IsNullOrWhiteSpace(search))
            query.Add($"search={Uri.EscapeDataString(search.Trim())}");
        if (!string.IsNullOrWhiteSpace(role) && role != "all")
            query.Add($"roles={Uri.EscapeDataString(role)}");

        var response = await apiClient.GetAsync(
            siteId,
            $"/wp-json/wp/v2/users?{string.Join('&', query)}",
            ct);

        if (!response.IsSuccess || response.Value is null)
            return new(false, response.ErrorMessage, Array.Empty<WordPressUserView>(), 0, 1);

        using var json = response.Value;
        var items = new List<WordPressUserView>();
        foreach (var item in json.RootElement.EnumerateArray())
        {
            items.Add(new WordPressUserView(
                GetInt(item, "id"),
                GetString(item, "username"),
                GetString(item, "name"),
                GetString(item, "first_name"),
                GetString(item, "last_name"),
                GetString(item, "email"),
                GetString(item, "url"),
                GetString(item, "description"),
                GetString(item, "link"),
                GetStringArray(item, "roles"),
                GetAvatar(item),
                GetDate(item, "registered_date")));
        }

        return new(
            true,
            string.Empty,
            items,
            TryHeaderInt(response.Headers, "X-WP-Total"),
            Math.Max(1, TryHeaderInt(response.Headers, "X-WP-TotalPages")));
    }

    public async Task<UserOperationResult> CreateAsync(
        Guid siteId,
        WordPressUserEditModel model,
        CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(model.Username))
            return new(false, "اسم المستخدم مطلوب.");
        if (string.IsNullOrWhiteSpace(model.Email))
            return new(false, "البريد الإلكتروني مطلوب.");
        if (string.IsNullOrWhiteSpace(model.Password) || model.Password.Length < 6)
            return new(false, "كلمة المرور يجب ألا تقل عن 6 أحرف.");

        var payload = new
        {
            username = model.Username.Trim(),
            email = model.Email.Trim(),
            password = model.Password,
            name = model.Name?.Trim(),
            first_name = model.FirstName?.Trim(),
            last_name = model.LastName?.Trim(),
            url = model.Url?.Trim(),
            description = model.Description?.Trim(),
            roles = model.Roles
        };

        return await SendAsync(
            siteId,
            HttpMethod.Post,
            "/wp-json/wp/v2/users",
            payload,
            "تم إنشاء المستخدم بنجاح.",
            ct);
    }

    public async Task<UserOperationResult> UpdateAsync(
        Guid siteId,
        int userId,
        WordPressUserEditModel model,
        CancellationToken ct = default)
    {
        if (userId <= 0)
            return new(false, "رقم المستخدم غير صحيح.");
        if (string.IsNullOrWhiteSpace(model.Email))
            return new(false, "البريد الإلكتروني مطلوب.");

        var payload = new Dictionary<string, object?>
        {
            ["email"] = model.Email.Trim(),
            ["name"] = model.Name?.Trim(),
            ["first_name"] = model.FirstName?.Trim(),
            ["last_name"] = model.LastName?.Trim(),
            ["url"] = model.Url?.Trim(),
            ["description"] = model.Description?.Trim(),
            ["roles"] = model.Roles
        };

        if (!string.IsNullOrWhiteSpace(model.Password))
            payload["password"] = model.Password;

        return await SendAsync(
            siteId,
            HttpMethod.Post,
            $"/wp-json/wp/v2/users/{userId}",
            payload,
            "تم تحديث المستخدم بنجاح.",
            ct);
    }

    public async Task<UserOperationResult> RemoveRolesAsync(
        Guid siteId,
        int userId,
        CancellationToken ct = default)
    {
        if (userId <= 0)
            return new(false, "رقم المستخدم غير صحيح.");

        var currentId = await GetCurrentUserIdAsync(siteId, ct);
        if (currentId == userId)
            return new(false, "لا يمكن تعطيل حساب المستخدم الحالي.");

        return await SendAsync(
            siteId,
            HttpMethod.Post,
            $"/wp-json/wp/v2/users/{userId}",
            new { roles = Array.Empty<string>() },
            "تم تعطيل المستخدم بإزالة جميع أدواره.",
            ct);
    }

    public async Task<UserOperationResult> DeleteAsync(
        Guid siteId,
        int userId,
        CancellationToken ct = default)
    {
        if (userId <= 0)
            return new(false, "رقم المستخدم غير صحيح.");

        var currentId = await GetCurrentUserIdAsync(siteId, ct);
        if (currentId == userId)
            return new(false, "لا يمكن حذف حساب المستخدم الحالي.");

        var response = await apiClient.SendAsync(
            siteId,
            HttpMethod.Delete,
            $"/wp-json/wp/v2/users/{userId}?force=true&reassign={currentId}",
            cancellationToken: ct);

        response.Value?.Dispose();
        return response.IsSuccess
            ? new(true, "تم حذف المستخدم وإعادة إسناد محتواه إلى الحساب الحالي.")
            : new(false, response.ErrorMessage);
    }

    private async Task<UserOperationResult> SendAsync(
        Guid siteId,
        HttpMethod method,
        string path,
        object payload,
        string successMessage,
        CancellationToken ct)
    {
        var response = await apiClient.SendAsync(siteId, method, path, payload, ct);
        response.Value?.Dispose();

        return response.IsSuccess
            ? new(true, successMessage)
            : new(false, response.ErrorMessage);
    }

    private async Task<int> GetCurrentUserIdAsync(Guid siteId, CancellationToken ct)
    {
        var response = await apiClient.GetAsync(siteId, "/wp-json/wp/v2/users/me?context=edit", ct);
        if (!response.IsSuccess || response.Value is null)
            throw new InvalidOperationException(string.IsNullOrWhiteSpace(response.ErrorMessage)
                ? "تعذر تحديد المستخدم الحالي."
                : response.ErrorMessage);

        using var json = response.Value;
        return GetInt(json.RootElement, "id");
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
        DateTime.TryParse(GetString(item, name), out var value) ? value : null;

    private static IReadOnlyList<string> GetStringArray(JsonElement item, string name) =>
        item.TryGetProperty(name, out var node) && node.ValueKind == JsonValueKind.Array
            ? node.EnumerateArray()
                .Select(x => x.GetString() ?? string.Empty)
                .Where(x => x.Length > 0)
                .ToArray()
            : Array.Empty<string>();

    private static string GetAvatar(JsonElement item)
    {
        if (!item.TryGetProperty("avatar_urls", out var avatars) || avatars.ValueKind != JsonValueKind.Object)
            return string.Empty;

        foreach (var size in new[] { "96", "48", "24" })
        {
            if (avatars.TryGetProperty(size, out var url))
                return url.GetString() ?? string.Empty;
        }

        return string.Empty;
    }
}

public sealed record WordPressUserView(
    int Id,
    string Username,
    string Name,
    string FirstName,
    string LastName,
    string Email,
    string Url,
    string Description,
    string Link,
    IReadOnlyList<string> Roles,
    string AvatarUrl,
    DateTime? RegisteredAt);

public sealed record UserPageResult(
    bool Success,
    string Message,
    IReadOnlyList<WordPressUserView> Items,
    int Total,
    int TotalPages);

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
