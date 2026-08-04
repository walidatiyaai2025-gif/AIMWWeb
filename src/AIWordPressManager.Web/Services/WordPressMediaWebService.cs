using System.Net.Http.Headers;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using Microsoft.AspNetCore.Components.Forms;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressMediaWebService(IWordPressApiClient apiClient)
{
    private const long MaxUploadSize = 25 * 1024 * 1024;

    public async Task<MediaActionResult> UploadAsync(
        Guid siteId,
        IBrowserFile file,
        string? title,
        string? altText,
        string? caption,
        CancellationToken cancellationToken = default)
    {
        if (file.Size <= 0)
            return MediaActionResult.Fail("الملف فارغ.");

        if (file.Size > MaxUploadSize)
            return MediaActionResult.Fail("حجم الملف أكبر من الحد المسموح وهو 25 MB.");

        try
        {
            await using var stream = file.OpenReadStream(MaxUploadSize, cancellationToken);
            using var content = new MultipartFormDataContent();
            using var fileContent = new StreamContent(stream);
            fileContent.Headers.ContentType = new MediaTypeHeaderValue(
                string.IsNullOrWhiteSpace(file.ContentType)
                    ? "application/octet-stream"
                    : file.ContentType);

            content.Add(fileContent, "file", file.Name);

            if (!string.IsNullOrWhiteSpace(title))
                content.Add(new StringContent(title.Trim()), "title");

            if (!string.IsNullOrWhiteSpace(altText))
                content.Add(new StringContent(altText.Trim()), "alt_text");

            if (!string.IsNullOrWhiteSpace(caption))
                content.Add(new StringContent(caption.Trim()), "caption");

            var response = await apiClient.SendContentAsync(
                siteId,
                HttpMethod.Post,
                "/wp-json/wp/v2/media",
                content,
                cancellationToken);

            if (!response.IsSuccess || response.Value is null)
                return MediaActionResult.Fail(response.ErrorMessage);

            using var json = response.Value;
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
    }

    public async Task<MediaActionResult> DeleteAsync(
        Guid siteId,
        int mediaId,
        CancellationToken cancellationToken = default)
    {
        if (mediaId <= 0)
            return MediaActionResult.Fail("رقم ملف الوسائط غير صحيح.");

        try
        {
            var response = await apiClient.SendAsync(
                siteId,
                HttpMethod.Delete,
                $"/wp-json/wp/v2/media/{mediaId}?force=true",
                cancellationToken: cancellationToken);

            response.Value?.Dispose();

            return response.IsSuccess
                ? MediaActionResult.Ok(mediaId, string.Empty, "تم حذف ملف الوسائط من WordPress بنجاح.")
                : MediaActionResult.Fail(response.ErrorMessage);
        }
        catch (Exception ex)
        {
            return MediaActionResult.Fail(ex.Message);
        }
    }

    private static int GetInt(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.TryGetInt32(out var result)
            ? result
            : 0;

    private static string GetString(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String
            ? value.GetString() ?? string.Empty
            : string.Empty;
}

public sealed record MediaActionResult(bool IsSuccess, string Message, int MediaId, string SourceUrl)
{
    public static MediaActionResult Ok(int id, string url, string message) =>
        new(true, message, id, url);

    public static MediaActionResult Fail(string message) =>
        new(false, message, 0, string.Empty);
}
