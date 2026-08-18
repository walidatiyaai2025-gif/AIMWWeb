using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Components.Forms;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressMediaWebService(
    IWordPressApiClient apiClient,
    IAIOrchestrator ai,
    IAIPromptRegistry prompts,
    ApprovalWorkflowService approvals,
    ExecutionCenterService execution,
    ExecutionOperationTracker executionTracker,
    NotificationInboxService notifications,
    CurrentUserContext currentUser,
    AppDbContext dbContext,
    ILogger<WordPressMediaWebService> logger)
{
    public const string MetadataConflictMessage = "This media item changed in WordPress after you opened it. Reload the latest metadata before saving.";

    public async Task<MediaActionResult> UploadAsync(
        Guid siteId,
        IBrowserFile file,
        string? title,
        string? altText,
        string? caption,
        CancellationToken cancellationToken = default)
    {
        RequireEditPermission();

        var validation = MediaUploadPolicy.Validate(file.Name, file.Size, file.ContentType);
        if (!validation.IsValid) return MediaActionResult.Fail(validation.Message);

        var ownerUserId = currentUser.UserId;
        var jobId = executionTracker.Start(ownerUserId, siteId, "Upload WordPress media", "Media Upload", siteId.ToString(), 3);
        try
        {
            logger.LogInformation("Uploading media {FileName} ({FileSize} bytes) to site {SiteId}", validation.SafeFileName, file.Size, siteId);
            executionTracker.Report(jobId, 1, 3, $"Preparing {validation.SafeFileName} for upload.");

            await using var stream = file.OpenReadStream(MediaUploadPolicy.MaxUploadSize, cancellationToken);
            using var content = new MultipartFormDataContent();
            using var fileContent = new StreamContent(stream);
            fileContent.Headers.ContentType = new MediaTypeHeaderValue(validation.ContentType);
            content.Add(fileContent, "file", validation.SafeFileName);
            AddText(content, "title", title);
            AddText(content, "alt_text", altText);
            AddText(content, "caption", caption);

            executionTracker.Report(jobId, 2, 3, "Sending media to WordPress.");
            var response = await apiClient.SendContentAsync(siteId, HttpMethod.Post, "/wp-json/wp/v2/media", content, cancellationToken);
            if (!response.IsSuccess || response.Value is null)
            {
                executionTracker.Fail(jobId, response.ErrorMessage);
                Notify(ownerUserId, "Media upload failed", response.ErrorMessage, NotificationSeverity.Error, siteId, jobId);
                logger.LogWarning("Media upload failed for {FileName} on site {SiteId}: {Error}", validation.SafeFileName, siteId, response.ErrorMessage);
                return MediaActionResult.Fail(response.ErrorMessage);
            }

            using var json = response.Value;
            var root = json.RootElement;
            var mediaId = GetInt(root, "id");
            var sourceUrl = GetString(root, "source_url");
            var result = MediaActionResult.Ok(mediaId, sourceUrl, "Media uploaded to WordPress successfully.");

            Notify(ownerUserId, "Media uploaded", validation.SafeFileName, NotificationSeverity.Success, siteId, jobId);
            executionTracker.Complete(jobId, 3, 3, $"Uploaded media #{mediaId}: {validation.SafeFileName}");
            logger.LogInformation("Media upload completed for {FileName} as WordPress media {MediaId}", validation.SafeFileName, mediaId);
            return result;
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            executionTracker.Fail(jobId, "Media upload was cancelled.");
            logger.LogWarning("Media upload cancelled for {FileName} on site {SiteId}", validation.SafeFileName, siteId);
            throw;
        }
        catch (Exception ex)
        {
            executionTracker.Fail(jobId, ex.Message);
            Notify(ownerUserId, "Media upload failed", ex.Message, NotificationSeverity.Error, siteId, jobId);
            logger.LogError(ex, "Unexpected media upload failure for {FileName} on site {SiteId}", validation.SafeFileName, siteId);
            return MediaActionResult.Fail(ex.Message);
        }
    }

    public async Task<MediaDetailsResult> GetDetailsAsync(
        Guid siteId,
        int mediaId,
        CancellationToken cancellationToken = default)
    {
        if (mediaId <= 0) return MediaDetailsResult.Fail("Invalid media ID.");

        try
        {
            var response = await apiClient.GetAsync(siteId, $"/wp-json/wp/v2/media/{mediaId}?context=edit", cancellationToken);
            if (!response.IsSuccess || response.Value is null)
                return MediaDetailsResult.Fail(response.ErrorMessage);

            using var json = response.Value;
            var root = json.RootElement;
            return MediaDetailsResult.Ok(new MediaEditableDetails(
                GetInt(root, "id"),
                GetRawOrRendered(root, "title"),
                GetString(root, "slug"),
                GetString(root, "alt_text"),
                GetRawOrRendered(root, "caption"),
                GetRawOrRendered(root, "description"),
                GetString(root, "media_type"),
                GetString(root, "mime_type"),
                GetString(root, "source_url"),
                GetDate(root, "modified_gmt") ?? GetDate(root, "modified"),
                GetNestedInt(root, "media_details", "width"),
                GetNestedInt(root, "media_details", "height"),
                GetNestedLong(root, "media_details", "filesize")));
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Failed to load media details for {MediaId} on site {SiteId}", mediaId, siteId);
            return MediaDetailsResult.Fail(ex.Message);
        }
    }

    public async Task<MediaActionResult> UpdateMetadataAsync(
        Guid siteId,
        int mediaId,
        MediaMetadataUpdate request,
        CancellationToken cancellationToken = default)
    {
        RequireEditPermission();

        if (mediaId <= 0) return MediaActionResult.Fail("Invalid media ID.");
        if (string.IsNullOrWhiteSpace(request.Title)) return MediaActionResult.Fail("Media title is required.");

        var ownerUserId = currentUser.UserId;
        var jobId = executionTracker.Start(ownerUserId, siteId, "Update WordPress media metadata", "Media Metadata", siteId.ToString(), 3);
        try
        {
            logger.LogInformation("Updating media metadata for {MediaId} on site {SiteId}", mediaId, siteId);
            executionTracker.Report(jobId, 1, 3, $"Checking media #{mediaId} for remote changes.");

            if (!request.ForceOverwrite && request.ExpectedModifiedGmt.HasValue)
            {
                var latest = await GetDetailsAsync(siteId, mediaId, cancellationToken);
                if (!latest.IsSuccess || latest.Details is null)
                {
                    executionTracker.Fail(jobId, latest.Message);
                    Notify(ownerUserId, "Media update failed", latest.Message, NotificationSeverity.Error, siteId, jobId);
                    return MediaActionResult.Fail(latest.Message);
                }

                if (HasRemoteChanged(request.ExpectedModifiedGmt, latest.Details.ModifiedGmt))
                {
                    executionTracker.Fail(jobId, MetadataConflictMessage);
                    Notify(ownerUserId, "Media update blocked", MetadataConflictMessage, NotificationSeverity.Warning, siteId, jobId);
                    return MediaActionResult.Fail(MetadataConflictMessage);
                }
            }

            executionTracker.Report(jobId, 2, 3, $"Updating media #{mediaId} metadata.");
            var payload = JsonSerializer.Serialize(new
            {
                title = request.Title.Trim(),
                alt_text = request.AltText?.Trim(),
                caption = request.Caption?.Trim(),
                description = request.Description?.Trim(),
                slug = request.Slug?.Trim()
            });
            using var content = new StringContent(payload, Encoding.UTF8, "application/json");
            var response = await apiClient.SendContentAsync(siteId, HttpMethod.Post, $"/wp-json/wp/v2/media/{mediaId}", content, cancellationToken);
            if (!response.IsSuccess || response.Value is null)
            {
                executionTracker.Fail(jobId, response.ErrorMessage);
                Notify(ownerUserId, "Media update failed", response.ErrorMessage, NotificationSeverity.Error, siteId, jobId);
                return MediaActionResult.Fail(response.ErrorMessage);
            }

            using var json = response.Value;
            Notify(ownerUserId, "Media updated", $"Media #{mediaId}", NotificationSeverity.Success, siteId, jobId);
            executionTracker.Complete(jobId, 3, 3, $"Updated metadata for media #{mediaId}.");
            return MediaActionResult.Ok(mediaId, GetString(json.RootElement, "source_url"), "Media metadata updated successfully.");
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            executionTracker.Fail(jobId, "Media metadata update was cancelled.");
            throw;
        }
        catch (Exception ex)
        {
            executionTracker.Fail(jobId, ex.Message);
            Notify(ownerUserId, "Media update failed", ex.Message, NotificationSeverity.Error, siteId, jobId);
            logger.LogError(ex, "Failed to update media metadata for {MediaId} on site {SiteId}", mediaId, siteId);
            return MediaActionResult.Fail(ex.Message);
        }
    }

    public async Task<MediaActionResult> ReplaceAsync(Guid siteId, int mediaId, IBrowserFile replacement, string? title, CancellationToken cancellationToken = default)
    {
        RequireEditPermission();

        if (mediaId <= 0) return MediaActionResult.Fail("Invalid media ID.");

        var validation = MediaUploadPolicy.Validate(replacement.Name, replacement.Size, replacement.ContentType);
        if (!validation.IsValid) return MediaActionResult.Fail(validation.Message);

        var upload = await UploadAsync(siteId, replacement, title, null, null, cancellationToken);
        if (!upload.IsSuccess) return upload;

        var approval = approvals.Submit(new ApprovalSubmission(
            siteId, string.Empty, "Media Replace", $"Replace media #{mediaId}",
            new { mediaId }, new { replacementMediaId = upload.MediaId, upload.SourceUrl },
            "System", ApprovalRiskLevel.High, null, null));

        logger.LogInformation("Media replacement request created for media {MediaId}; replacement media {ReplacementMediaId}; approval {ApprovalId}", mediaId, upload.MediaId, approval.Id);
        return upload with { Message = $"Replacement uploaded and submitted for approval ({approval.Id})." };
    }

    public async Task<string> SuggestAltTextAsync(Guid siteId, int mediaId, string imageContext, string culture, string? userId, CancellationToken cancellationToken = default)
    {
        if (mediaId <= 0) throw new InvalidOperationException("Invalid media ID.");
        if (string.IsNullOrWhiteSpace(imageContext)) throw new InvalidOperationException("Image context is required.");

        logger.LogInformation("Generating AI alt text for media {MediaId} on site {SiteId}", mediaId, siteId);
        var prompt = prompts.Get("alt-text", culture);
        var result = await ai.ExecuteAsync(new AIRequest(imageContext.Trim(), prompt, null, 0.2, 180, siteId, userId, "alt-text"), cancellationToken);
        if (!result.IsSuccess)
        {
            logger.LogWarning("AI alt text generation failed for media {MediaId}: {Error}", mediaId, result.Error);
            throw new InvalidOperationException(result.Error ?? "Alt text generation failed.");
        }

        return result.Content.Trim();
    }

    public async Task<MediaActionResult> DeleteAsync(Guid siteId, int mediaId, CancellationToken cancellationToken = default)
    {
        RequireEditPermission();

        if (mediaId <= 0) return MediaActionResult.Fail("Invalid media ID.");

        var ownerUserId = currentUser.UserId;
        var jobId = executionTracker.Start(ownerUserId, siteId, "Delete WordPress media", "Media Delete", siteId.ToString(), 2);
        try
        {
            logger.LogInformation("Deleting media {MediaId} from site {SiteId}", mediaId, siteId);
            executionTracker.Report(jobId, 1, 2, $"Deleting media #{mediaId} from WordPress.");

            var response = await apiClient.SendAsync(siteId, HttpMethod.Delete, $"/wp-json/wp/v2/media/{mediaId}?force=true", cancellationToken: cancellationToken);
            response.Value?.Dispose();
            if (!response.IsSuccess)
            {
                executionTracker.Fail(jobId, response.ErrorMessage);
                Notify(ownerUserId, "Media deletion failed", response.ErrorMessage, NotificationSeverity.Error, siteId, jobId);
                return MediaActionResult.Fail(response.ErrorMessage);
            }

            var cacheMessage = await ReconcileDeletedMediaCacheAsync(siteId, mediaId, cancellationToken);
            Notify(ownerUserId, "Media deleted", $"Media #{mediaId}", NotificationSeverity.Warning, siteId, jobId);
            executionTracker.Complete(jobId, 2, 2, $"Deleted media #{mediaId} from WordPress.");
            return MediaActionResult.Ok(mediaId, string.Empty, cacheMessage);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            executionTracker.Fail(jobId, "Media deletion was cancelled.");
            throw;
        }
        catch (Exception ex)
        {
            executionTracker.Fail(jobId, ex.Message);
            Notify(ownerUserId, "Media deletion failed", ex.Message, NotificationSeverity.Error, siteId, jobId);
            logger.LogError(ex, "Failed to delete media {MediaId} from site {SiteId}", mediaId, siteId);
            return MediaActionResult.Fail(ex.Message);
        }
    }

    public ApprovalItem RequestBulkDelete(Guid siteId, string siteName, IReadOnlyCollection<int> mediaIds, string requestedBy)
    {
        RequireEditPermission();

        var validIds = mediaIds.Where(id => id > 0).Distinct().ToArray();
        if (validIds.Length == 0) throw new InvalidOperationException("Select at least one valid media item.");
        return approvals.Submit(new ApprovalSubmission(
            siteId, siteName, "Bulk Media Delete", $"Delete {validIds.Length} media items",
            new { mediaIds = validIds }, new { deleted = true }, requestedBy,
            ApprovalRiskLevel.Critical, null, $"media-delete:{siteId:N}:{string.Join('-', validIds)}"));
    }

    public ExecutionJob QueueMetadataUpdate(Guid siteId, string siteName, IReadOnlyCollection<int> mediaIds)
    {
        RequireEditPermission();

        var count = mediaIds.Count(id => id > 0);
        if (count == 0) throw new InvalidOperationException("Select at least one media item.");
        return execution.Enqueue(currentUser.UserId, siteId, "Bulk media metadata update", "Bulk Media Metadata", siteName, count);
    }

    public static bool HasRemoteChanged(DateTimeOffset? expectedModifiedGmt, DateTimeOffset? remoteModifiedGmt)
    {
        if (!expectedModifiedGmt.HasValue || !remoteModifiedGmt.HasValue) return false;
        return expectedModifiedGmt.Value.ToUniversalTime() != remoteModifiedGmt.Value.ToUniversalTime();
    }

    private void RequireEditPermission() =>
        currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);

    private async Task<string> ReconcileDeletedMediaCacheAsync(Guid siteId, int mediaId, CancellationToken cancellationToken)
    {
        try
        {
            var record = await dbContext.WordPressMediaRecords
                .SingleOrDefaultAsync(x => x.SiteId == siteId && x.WordPressId == mediaId, cancellationToken);
            if (record is null) return "Media deleted from WordPress successfully.";

            // Keep the existing synchronization watermark. Local reconciliation must not move
            // the delta cursor past unrelated remote changes that have not been synchronized yet.
            var cursor = record.LastSynchronizedAtUtc == default ? DateTime.UtcNow : record.LastSynchronizedAtUtc;
            record.MarkUnavailable(cursor);
            await dbContext.SaveChangesAsync(cancellationToken);
            return "Media deleted from WordPress successfully.";
        }
        catch (Exception ex)
        {
            // The remote delete already succeeded. Report success accurately and make the local
            // cache warning explicit rather than inviting a dangerous retry of a permanent delete.
            logger.LogWarning(ex, "Media {MediaId} was deleted remotely but local cache reconciliation failed for site {SiteId}", mediaId, siteId);
            return "Media was deleted from WordPress, but the local cache could not be reconciled. Refresh synchronization before relying on the cached media list.";
        }
    }

    private void Notify(
        Guid ownerUserId,
        string title,
        string message,
        NotificationSeverity severity,
        Guid siteId,
        Guid executionJobId)
    {
        try
        {
            notifications.Create(
                ownerUserId,
                title,
                string.IsNullOrWhiteSpace(message) ? title : message,
                severity,
                siteId: siteId,
                executionJobId: executionJobId,
                source: "WordPressMedia");
        }
        catch (Exception ex)
        {
            logger.LogWarning(ex, "Failed to persist notification for media execution job {ExecutionJobId}.", executionJobId);
        }
    }

    private static void AddText(MultipartFormDataContent content, string name, string? value)
    {
        if (!string.IsNullOrWhiteSpace(value)) content.Add(new StringContent(value.Trim()), name);
    }

    private static int GetInt(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.TryGetInt32(out var result) ? result : 0;

    private static string GetString(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() ?? string.Empty : string.Empty;

    private static string GetRawOrRendered(JsonElement item, string name)
    {
        if (!item.TryGetProperty(name, out var value) || value.ValueKind != JsonValueKind.Object) return string.Empty;
        if (value.TryGetProperty("raw", out var raw) && raw.ValueKind == JsonValueKind.String) return raw.GetString() ?? string.Empty;
        if (value.TryGetProperty("rendered", out var rendered) && rendered.ValueKind == JsonValueKind.String) return rendered.GetString() ?? string.Empty;
        return string.Empty;
    }

    private static DateTimeOffset? GetDate(JsonElement item, string name) =>
        DateTimeOffset.TryParse(GetString(item, name), out var value) ? value : null;

    private static int? GetNestedInt(JsonElement item, string parent, string name) =>
        item.TryGetProperty(parent, out var nested) && nested.ValueKind == JsonValueKind.Object &&
        nested.TryGetProperty(name, out var value) && value.TryGetInt32(out var result)
            ? result
            : null;

    private static long? GetNestedLong(JsonElement item, string parent, string name) =>
        item.TryGetProperty(parent, out var nested) && nested.ValueKind == JsonValueKind.Object &&
        nested.TryGetProperty(name, out var value) && value.TryGetInt64(out var result)
            ? result
            : null;
}

public sealed record MediaMetadataUpdate(
    string? Title,
    string? AltText,
    string? Caption,
    string? Description,
    string? Slug,
    DateTimeOffset? ExpectedModifiedGmt = null,
    bool ForceOverwrite = false);

public sealed record MediaEditableDetails(
    int MediaId,
    string Title,
    string Slug,
    string AltText,
    string Caption,
    string Description,
    string MediaType,
    string MimeType,
    string SourceUrl,
    DateTimeOffset? ModifiedGmt,
    int? Width,
    int? Height,
    long? FileSize);

public sealed record MediaDetailsResult(bool IsSuccess, string Message, MediaEditableDetails? Details)
{
    public static MediaDetailsResult Ok(MediaEditableDetails details) => new(true, string.Empty, details);
    public static MediaDetailsResult Fail(string message) => new(false, message, null);
}

public sealed record MediaBulkRequest(string SiteName, int[] MediaIds, string? RequestedBy);
public sealed record MediaAltTextRequest(string Context, string? Culture, string? UserId);

public sealed record MediaActionResult(bool IsSuccess, string Message, int MediaId, string SourceUrl)
{
    public static MediaActionResult Ok(int id, string url, string message) => new(true, message, id, url);
    public static MediaActionResult Fail(string message) => new(false, message, 0, string.Empty);
}
