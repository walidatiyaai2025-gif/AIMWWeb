using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Abstractions.WordPress;
using Microsoft.AspNetCore.Components.Forms;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressMediaWebService(
    IWordPressApiClient apiClient,
    IAIOrchestrator ai,
    IAIPromptRegistry prompts,
    ApprovalWorkflowService approvals,
    ExecutionCenterService execution,
    NotificationInboxService notifications)
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
        if (file.Size <= 0) return MediaActionResult.Fail("The selected file is empty.");
        if (file.Size > MaxUploadSize) return MediaActionResult.Fail("The file exceeds the 25 MB upload limit.");

        try
        {
            await using var stream = file.OpenReadStream(MaxUploadSize, cancellationToken);
            using var content = new MultipartFormDataContent();
            using var fileContent = new StreamContent(stream);
            fileContent.Headers.ContentType = new MediaTypeHeaderValue(
                string.IsNullOrWhiteSpace(file.ContentType) ? "application/octet-stream" : file.ContentType);
            content.Add(fileContent, "file", file.Name);
            AddText(content, "title", title);
            AddText(content, "alt_text", altText);
            AddText(content, "caption", caption);

            var response = await apiClient.SendContentAsync(siteId, HttpMethod.Post, "/wp-json/wp/v2/media", content, cancellationToken);
            if (!response.IsSuccess || response.Value is null) return MediaActionResult.Fail(response.ErrorMessage);

            using var json = response.Value;
            var root = json.RootElement;
            var result = MediaActionResult.Ok(GetInt(root, "id"), GetString(root, "source_url"), "Media uploaded to WordPress successfully.");
            notifications.Create("System", "Media uploaded", file.Name, NotificationSeverity.Success, siteId);
            return result;
        }
        catch (Exception ex) { return MediaActionResult.Fail(ex.Message); }
    }

    public async Task<MediaActionResult> UpdateMetadataAsync(Guid siteId, int mediaId, MediaMetadataUpdate request, CancellationToken cancellationToken = default)
    {
        if (mediaId <= 0) return MediaActionResult.Fail("Invalid media ID.");
        var payload = JsonSerializer.Serialize(new
        {
            title = request.Title,
            alt_text = request.AltText,
            caption = request.Caption,
            description = request.Description,
            slug = request.Slug
        });
        using var content = new StringContent(payload, Encoding.UTF8, "application/json");
        var response = await apiClient.SendContentAsync(siteId, HttpMethod.Post, $"/wp-json/wp/v2/media/{mediaId}", content, cancellationToken);
        if (!response.IsSuccess || response.Value is null) return MediaActionResult.Fail(response.ErrorMessage);
        using var json = response.Value;
        notifications.Create("System", "Media updated", $"Media #{mediaId}", NotificationSeverity.Success, siteId);
        return MediaActionResult.Ok(mediaId, GetString(json.RootElement, "source_url"), "Media metadata updated successfully.");
    }

    public async Task<MediaActionResult> ReplaceAsync(Guid siteId, int mediaId, IBrowserFile replacement, string? title, CancellationToken cancellationToken = default)
    {
        if (mediaId <= 0) return MediaActionResult.Fail("Invalid media ID.");
        if (replacement.Size <= 0 || replacement.Size > MaxUploadSize) return MediaActionResult.Fail("Replacement file is empty or exceeds 25 MB.");

        var upload = await UploadAsync(siteId, replacement, title, null, null, cancellationToken);
        if (!upload.IsSuccess) return upload;

        var approval = approvals.Submit(new ApprovalSubmission(
            siteId, string.Empty, "Media Replace", $"Replace media #{mediaId}",
            new { mediaId }, new { replacementMediaId = upload.MediaId, upload.SourceUrl },
            "System", ApprovalRiskLevel.High, null, null));

        return upload with { Message = $"Replacement uploaded and submitted for approval ({approval.Id})." };
    }

    public async Task<string> SuggestAltTextAsync(Guid siteId, int mediaId, string imageContext, string culture, string? userId, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(imageContext)) throw new InvalidOperationException("Image context is required.");
        var prompt = prompts.Get("alt-text", culture);
        var result = await ai.ExecuteAsync(new AIRequest(imageContext, prompt, null, 0.2, 180, siteId, userId, "alt-text"), cancellationToken);
        if (!result.IsSuccess) throw new InvalidOperationException(result.Error ?? "Alt text generation failed.");
        return result.Content.Trim();
    }

    public async Task<MediaActionResult> DeleteAsync(Guid siteId, int mediaId, CancellationToken cancellationToken = default)
    {
        if (mediaId <= 0) return MediaActionResult.Fail("Invalid media ID.");
        try
        {
            var response = await apiClient.SendAsync(siteId, HttpMethod.Delete, $"/wp-json/wp/v2/media/{mediaId}?force=true", cancellationToken: cancellationToken);
            response.Value?.Dispose();
            if (!response.IsSuccess) return MediaActionResult.Fail(response.ErrorMessage);
            notifications.Create("System", "Media deleted", $"Media #{mediaId}", NotificationSeverity.Warning, siteId);
            return MediaActionResult.Ok(mediaId, string.Empty, "Media deleted from WordPress successfully.");
        }
        catch (Exception ex) { return MediaActionResult.Fail(ex.Message); }
    }

    public ApprovalItem RequestBulkDelete(Guid siteId, string siteName, IReadOnlyCollection<int> mediaIds, string requestedBy)
    {
        var validIds = mediaIds.Where(id => id > 0).Distinct().ToArray();
        if (validIds.Length == 0) throw new InvalidOperationException("Select at least one valid media item.");
        return approvals.Submit(new ApprovalSubmission(
            siteId, siteName, "Bulk Media Delete", $"Delete {validIds.Length} media items",
            new { mediaIds = validIds }, new { deleted = true }, requestedBy,
            ApprovalRiskLevel.Critical, null, $"media-delete:{siteId:N}:{string.Join('-', validIds)}"));
    }

    public ExecutionJob QueueMetadataUpdate(Guid siteId, string siteName, IReadOnlyCollection<int> mediaIds)
    {
        var count = mediaIds.Count(id => id > 0);
        if (count == 0) throw new InvalidOperationException("Select at least one media item.");
        return execution.Enqueue("Bulk media metadata update", "Bulk Media Metadata", siteName, count);
    }

    private static void AddText(MultipartFormDataContent content, string name, string? value)
    {
        if (!string.IsNullOrWhiteSpace(value)) content.Add(new StringContent(value.Trim()), name);
    }

    private static int GetInt(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.TryGetInt32(out var result) ? result : 0;
    private static string GetString(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() ?? string.Empty : string.Empty;
}

public sealed record MediaMetadataUpdate(string? Title, string? AltText, string? Caption, string? Description, string? Slug);
public sealed record MediaBulkRequest(string SiteName, int[] MediaIds, string? RequestedBy);
public sealed record MediaAltTextRequest(string Context, string? Culture, string? UserId);

public sealed record MediaActionResult(bool IsSuccess, string Message, int MediaId, string SourceUrl)
{
    public static MediaActionResult Ok(int id, string url, string message) => new(true, message, id, url);
    public static MediaActionResult Fail(string message) => new(false, message, 0, string.Empty);
}
