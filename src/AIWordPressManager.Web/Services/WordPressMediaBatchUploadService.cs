using System.Collections.Concurrent;
using System.Net.Http.Headers;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using Microsoft.AspNetCore.Components.Forms;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressMediaBatchUploadService(
    IWordPressApiClient apiClient,
    ExecutionOperationTracker executionTracker,
    NotificationInboxService notifications,
    CurrentUserContext currentUser,
    ILogger<WordPressMediaBatchUploadService> logger)
{
    public const int MaxBatchFiles = 20;

    private static readonly ConcurrentDictionary<string, byte> ActiveBatches = new(StringComparer.Ordinal);

    public static MediaBatchSelectionItem Inspect(int index, IBrowserFile file)
    {
        ArgumentNullException.ThrowIfNull(file);
        var validation = MediaUploadPolicy.Validate(file.Name, file.Size, file.ContentType);
        return new MediaBatchSelectionItem(
            index,
            file.Name,
            file.Size,
            file.ContentType,
            validation.IsValid,
            validation.Message,
            validation.SafeFileName);
    }

    public async Task<MediaBatchUploadResult> UploadAsync(
        Guid siteId,
        IReadOnlyList<MediaBatchUploadRequest> requests,
        Func<MediaBatchUploadProgress, Task>? progress = null,
        CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
        if (siteId == Guid.Empty) throw new ArgumentException("Site ID is required.", nameof(siteId));
        ArgumentNullException.ThrowIfNull(requests);
        if (requests.Count == 0) throw new ArgumentException("Select at least one file to upload.", nameof(requests));
        if (requests.Count > MaxBatchFiles)
            throw new ArgumentOutOfRangeException(nameof(requests), $"A media batch can contain at most {MaxBatchFiles} files.");

        var prepared = requests.Select((request, index) =>
        {
            ArgumentNullException.ThrowIfNull(request);
            ArgumentNullException.ThrowIfNull(request.File);
            var validation = MediaUploadPolicy.Validate(request.File.Name, request.File.Size, request.File.ContentType);
            return new PreparedUpload(index, request, validation);
        }).ToArray();

        var batchKey = BuildBatchKey(ownerUserId, siteId, prepared);
        if (!ActiveBatches.TryAdd(batchKey, 0))
            return MediaBatchUploadResult.Duplicate(requests.Count);

        Guid jobId = Guid.Empty;
        try
        {
            jobId = executionTracker.Start(
                ownerUserId,
                siteId,
                "Upload WordPress media batch",
                "Media Batch Upload",
                siteId.ToString(),
                prepared.Length);

            var results = new List<MediaBatchUploadItemResult>(prepared.Length);
            var succeeded = 0;
            var failed = 0;
            var rejected = 0;
            var processed = 0;
            var applyMetadata = prepared.Length == 1;

            foreach (var item in prepared)
            {
                cancellationToken.ThrowIfCancellationRequested();

                if (!item.Validation.IsValid)
                {
                    rejected++;
                    processed++;
                    results.Add(new MediaBatchUploadItemResult(
                        item.Index,
                        item.Request.File.Name,
                        MediaBatchItemState.Rejected,
                        item.Validation.Message));
                    executionTracker.Report(jobId, processed, prepared.Length, $"Rejected {item.Request.File.Name}: {item.Validation.Message}");
                    await ReportProgressAsync(progress, processed, prepared.Length, succeeded, failed, rejected, item.Request.File.Name, results);
                    continue;
                }

                executionTracker.Report(jobId, processed, prepared.Length, $"Uploading {item.Validation.SafeFileName}.");
                var upload = await UploadOneAsync(siteId, item, applyMetadata, cancellationToken);
                processed++;

                if (upload.IsSuccess)
                {
                    succeeded++;
                    results.Add(new MediaBatchUploadItemResult(
                        item.Index,
                        item.Request.File.Name,
                        MediaBatchItemState.Succeeded,
                        upload.Message,
                        upload.MediaId,
                        upload.SourceUrl));
                }
                else
                {
                    failed++;
                    results.Add(new MediaBatchUploadItemResult(
                        item.Index,
                        item.Request.File.Name,
                        MediaBatchItemState.Failed,
                        upload.Message));
                }

                executionTracker.Report(jobId, processed, prepared.Length,
                    upload.IsSuccess
                        ? $"Uploaded {item.Validation.SafeFileName}."
                        : $"Failed {item.Validation.SafeFileName}: {upload.Message}");
                await ReportProgressAsync(progress, processed, prepared.Length, succeeded, failed, rejected, item.Request.File.Name, results);
            }

            var message = BuildSummary(prepared.Length, succeeded, failed, rejected);
            if (failed > 0 && succeeded == 0)
                executionTracker.Fail(jobId, message);
            else
                executionTracker.Complete(jobId, processed, prepared.Length, message);

            var severity = succeeded == prepared.Length
                ? NotificationSeverity.Success
                : failed > 0 && succeeded == 0
                    ? NotificationSeverity.Error
                    : NotificationSeverity.Warning;
            Notify(ownerUserId, siteId, jobId, message, severity);

            logger.LogInformation(
                "WordPress media batch {ExecutionJobId} finished for site {SiteId}: {Succeeded} succeeded, {Failed} failed, {Rejected} rejected.",
                jobId, siteId, succeeded, failed, rejected);

            return new MediaBatchUploadResult(jobId, prepared.Length, succeeded, failed, rejected, results, message, false);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            if (jobId != Guid.Empty) executionTracker.Fail(jobId, "Media batch upload was cancelled.");
            throw;
        }
        catch (Exception ex)
        {
            if (jobId != Guid.Empty) executionTracker.Fail(jobId, ex.Message);
            logger.LogError(ex, "Unexpected WordPress media batch failure for site {SiteId}.", siteId);
            throw;
        }
        finally
        {
            ActiveBatches.TryRemove(batchKey, out _);
        }
    }

    private async Task<MediaActionResult> UploadOneAsync(
        Guid siteId,
        PreparedUpload item,
        bool applyMetadata,
        CancellationToken cancellationToken)
    {
        try
        {
            await using var stream = item.Request.File.OpenReadStream(MediaUploadPolicy.MaxUploadSize, cancellationToken);
            using var content = new MultipartFormDataContent();
            using var fileContent = new StreamContent(stream);
            fileContent.Headers.ContentType = new MediaTypeHeaderValue(item.Validation.ContentType);
            content.Add(fileContent, "file", item.Validation.SafeFileName);

            if (applyMetadata)
            {
                AddText(content, "title", item.Request.Title);
                AddText(content, "alt_text", item.Request.AltText);
                AddText(content, "caption", item.Request.Caption);
            }

            var response = await apiClient.SendContentAsync(
                siteId,
                HttpMethod.Post,
                "/wp-json/wp/v2/media",
                content,
                cancellationToken);

            if (!response.IsSuccess || response.Value is null)
                return MediaActionResult.Fail(string.IsNullOrWhiteSpace(response.ErrorMessage) ? "WordPress rejected the media upload." : response.ErrorMessage);

            using var json = response.Value;
            var root = json.RootElement;
            var mediaId = root.TryGetProperty("id", out var id) && id.TryGetInt32(out var parsedId) ? parsedId : 0;
            var sourceUrl = root.TryGetProperty("source_url", out var url) && url.ValueKind == JsonValueKind.String
                ? url.GetString() ?? string.Empty
                : string.Empty;
            return MediaActionResult.Ok(mediaId, sourceUrl, "Media uploaded to WordPress successfully.");
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            logger.LogWarning(ex, "Media batch item {FileName} failed for site {SiteId}.", item.Validation.SafeFileName, siteId);
            return MediaActionResult.Fail(ex.Message);
        }
    }

    private void Notify(Guid ownerUserId, Guid siteId, Guid jobId, string message, NotificationSeverity severity)
    {
        try
        {
            notifications.Create(
                ownerUserId,
                severity == NotificationSeverity.Success ? "Media batch uploaded" : "Media batch completed",
                message,
                severity,
                siteId: siteId,
                executionJobId: jobId,
                source: "WordPressMediaBatch");
        }
        catch (Exception ex)
        {
            logger.LogWarning(ex, "Failed to persist notification for media batch {ExecutionJobId}.", jobId);
        }
    }

    private static async Task ReportProgressAsync(
        Func<MediaBatchUploadProgress, Task>? callback,
        int processed,
        int total,
        int succeeded,
        int failed,
        int rejected,
        string? currentFile,
        IReadOnlyList<MediaBatchUploadItemResult> results)
    {
        if (callback is null) return;
        await callback(new MediaBatchUploadProgress(
            processed,
            total,
            succeeded,
            failed,
            rejected,
            currentFile,
            results.ToArray()));
    }

    private static string BuildBatchKey(Guid ownerUserId, Guid siteId, IReadOnlyList<PreparedUpload> prepared) =>
        $"{ownerUserId:N}:{siteId:N}:" + string.Join('|', prepared.Select(item =>
            $"{item.Index}:{item.Validation.SafeFileName}:{item.Request.File.Size}"));

    private static string BuildSummary(int total, int succeeded, int failed, int rejected) =>
        $"Media batch completed: {succeeded} succeeded, {failed} failed, {rejected} rejected out of {total}.";

    private static void AddText(MultipartFormDataContent content, string name, string? value)
    {
        if (!string.IsNullOrWhiteSpace(value)) content.Add(new StringContent(value.Trim()), name);
    }

    private sealed record PreparedUpload(int Index, MediaBatchUploadRequest Request, MediaUploadValidationResult Validation);
}

public sealed record MediaBatchUploadRequest(
    IBrowserFile File,
    string? Title = null,
    string? AltText = null,
    string? Caption = null);

public enum MediaBatchItemState
{
    Rejected,
    Succeeded,
    Failed
}

public sealed record MediaBatchSelectionItem(
    int Index,
    string FileName,
    long Size,
    string ContentType,
    bool IsValid,
    string Message,
    string SafeFileName);

public sealed record MediaBatchUploadItemResult(
    int Index,
    string FileName,
    MediaBatchItemState State,
    string Message,
    int MediaId = 0,
    string SourceUrl = "");

public sealed record MediaBatchUploadProgress(
    int Processed,
    int Total,
    int Succeeded,
    int Failed,
    int Rejected,
    string? CurrentFile,
    IReadOnlyList<MediaBatchUploadItemResult> Items)
{
    public int Percent => Total <= 0 ? 0 : (int)Math.Round(Processed * 100d / Total);
}

public sealed record MediaBatchUploadResult(
    Guid ExecutionJobId,
    int Total,
    int Succeeded,
    int Failed,
    int Rejected,
    IReadOnlyList<MediaBatchUploadItemResult> Items,
    string Message,
    bool IsDuplicate)
{
    public bool IsSuccess => !IsDuplicate && Total > 0 && Succeeded == Total;
    public bool IsPartialSuccess => !IsDuplicate && Succeeded > 0 && Succeeded < Total;

    public static MediaBatchUploadResult Duplicate(int total) =>
        new(Guid.Empty, total, 0, 0, 0, Array.Empty<MediaBatchUploadItemResult>(),
            "This media batch is already running.", true);
}