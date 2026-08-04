using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Components.Forms;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressMediaWebService(
    AppDbContext dbContext,
    IHttpClientFactory httpClientFactory,
    ISecretProtectionService secretProtectionService)
{
    private const long MaxUploadSize = 25 * 1024 * 1024;

    public async Task<MediaActionResult> UploadAsync(Guid siteId, IBrowserFile file, string? title, string? altText, string? caption, CancellationToken cancellationToken = default)
    {
        if (file.Size <= 0) return MediaActionResult.Fail("الملف فارغ.");
        if (file.Size > MaxUploadSize) return MediaActionResult.Fail("حجم الملف أكبر من الحد المسموح وهو 25 MB.");

        var connection = await CreateConnectionAsync(siteId, cancellationToken);
        if (!connection.Success) return MediaActionResult.Fail(connection.Message);

        try
        {
            await using var stream = file.OpenReadStream(MaxUploadSize, cancellationToken);
            using var content = new MultipartFormDataContent();
            using var fileContent = new StreamContent(stream);
            fileContent.Headers.ContentType = new MediaTypeHeaderValue(string.IsNullOrWhiteSpace(file.ContentType) ? "application/octet-stream" : file.ContentType);
            content.Add(fileContent, "file", file.Name);
            if (!string.IsNullOrWhiteSpace(title)) content.Add(new StringContent(title.Trim()), "title");
            if (!string.IsNullOrWhiteSpace(altText)) content.Add(new StringContent(altText.Trim()), "alt_text");
            if (!string.IsNullOrWhiteSpace(caption)) content.Add(new StringContent(caption.Trim()), "caption");

            using var response = await connection.Client!.PostAsync($"{connection.RootUrl}/wp-json/wp/v2/media", content, cancellationToken);
            if (!response.IsSuccessStatusCode)
                return MediaActionResult.Fail(await ReadErrorAsync(response, "تعذر رفع الملف إلى WordPress.", cancellationToken));

            await using var responseStream = await response.Content.ReadAsStreamAsync(cancellationToken);
            using var json = await JsonDocument.ParseAsync(responseStream, cancellationToken: cancellationToken);
            var root = json.RootElement;
            return MediaActionResult.Ok(
                GetInt(root, "id"),
                GetString(root, "source_url"),
                "تم رفع الملف إلى WordPress بنجاح.");
        }
        catch (Exception ex)
        {
            return MediaActionResult.Fail(ex.Message);
        }
        finally
        {
            connection.Client?.Dispose();
        }
    }

    public async Task<MediaActionResult> DeleteAsync(Guid siteId, int mediaId, CancellationToken cancellationToken = default)
    {
        var connection = await CreateConnectionAsync(siteId, cancellationToken);
        if (!connection.Success) return MediaActionResult.Fail(connection.Message);
        try
        {
            using var response = await connection.Client!.DeleteAsync($"{connection.RootUrl}/wp-json/wp/v2/media/{mediaId}?force=true", cancellationToken);
            if (!response.IsSuccessStatusCode)
                return MediaActionResult.Fail(await ReadErrorAsync(response, "تعذر حذف ملف الوسائط من WordPress.", cancellationToken));
            return MediaActionResult.Ok(mediaId, string.Empty, "تم حذف ملف الوسائط من WordPress بنجاح.");
        }
        catch (Exception ex)
        {
            return MediaActionResult.Fail(ex.Message);
        }
        finally
        {
            connection.Client?.Dispose();
        }
    }

    private async Task<ConnectionResult> CreateConnectionAsync(Guid siteId, CancellationToken ct)
    {
        var site = await dbContext.Sites.AsNoTracking().FirstOrDefaultAsync(x => x.Id == siteId && !x.IsDeleted, ct);
        if (site is null) return ConnectionResult.Fail("الموقع غير موجود.");
        var credential = await dbContext.SiteCredentials.AsNoTracking().FirstOrDefaultAsync(x => x.SiteId == siteId, ct);
        if (credential is null) return ConnectionResult.Fail("احفظ بيانات اتصال WordPress واختبرها أولًا.");

        try
        {
            var password = await secretProtectionService.UnprotectAsync(credential.ProtectedApplicationPassword, ct);
            var client = httpClientFactory.CreateClient(nameof(WordPressMediaWebService));
            client.Timeout = TimeSpan.FromMinutes(5);
            client.DefaultRequestHeaders.UserAgent.ParseAdd("AIWordPressManager/1.0");
            var raw = $"{credential.UserName}:{password.Replace(" ", string.Empty, StringComparison.Ordinal)}";
            client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Basic", Convert.ToBase64String(Encoding.UTF8.GetBytes(raw)));
            return ConnectionResult.Ok(client, site.SiteUrl.TrimEnd('/'));
        }
        catch
        {
            return ConnectionResult.Fail("تعذر قراءة كلمة المرور المشفرة. أعد حفظ بيانات الاتصال.");
        }
    }

    private static async Task<string> ReadErrorAsync(HttpResponseMessage response, string fallback, CancellationToken ct)
    {
        var body = await response.Content.ReadAsStringAsync(ct);
        if (response.StatusCode is System.Net.HttpStatusCode.Unauthorized or System.Net.HttpStatusCode.Forbidden)
            return "فشل تسجيل الدخول أو لا يملك المستخدم صلاحية إدارة الوسائط.";
        try
        {
            using var json = JsonDocument.Parse(body);
            if (json.RootElement.TryGetProperty("message", out var message) && !string.IsNullOrWhiteSpace(message.GetString()))
                return message.GetString()!;
        }
        catch (JsonException) { }
        return $"{fallback} HTTP {(int)response.StatusCode}";
    }

    private static int GetInt(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.TryGetInt32(out var result) ? result : 0;
    private static string GetString(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() ?? string.Empty : string.Empty;
    private sealed record ConnectionResult(bool Success, string Message, HttpClient? Client, string RootUrl)
    {
        public static ConnectionResult Ok(HttpClient client, string rootUrl) => new(true, string.Empty, client, rootUrl);
        public static ConnectionResult Fail(string message) => new(false, message, null, string.Empty);
    }
}

public sealed record MediaActionResult(bool IsSuccess, string Message, int MediaId, string SourceUrl)
{
    public static MediaActionResult Ok(int id, string url, string message) => new(true, message, id, url);
    public static MediaActionResult Fail(string message) => new(false, message, 0, string.Empty);
}
