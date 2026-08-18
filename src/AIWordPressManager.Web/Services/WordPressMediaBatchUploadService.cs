using System.Net.Http.Headers;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Components.Forms;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressMediaBatchUploadService(
    IWordPressApiClient apiClient,
    ExecutionOperationTracker executionTracker,
    NotificationInboxService notifications,
    CurrentUserContext currentUser,
    AppDbContext dbContext,
    ILogger<WordPressMediaBatchUploadService> logger)
{
    public const int MaxBatchFiles = 20;

    public async Task<MediaBatchUploadResult> UploadAsync(
        Guid siteId,
        IReadOnlyList<MediaBatchUploadItem> items,
        Func<MediaBatchUploadProgress, Task>? progress = null,
        CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
        if (siteId == Guid.Empty)
            throw new ArgumentException("Site ID is required.", nameof(siteId));
        if (items.Count == 0)
            return MediaBatchUploadResult.Empty("Select at least one file to upload.");
        if (items.Count > MaxBatchFiles)
            return MediaBatchUploadResult.Empty($"Select at most {MaxBatchFiles} files per upload batch.");

        var site = await dbContext.Sites
            .AsNoTracking()
            .Where(x => x.Id == siteId && x.OwnerUserId == ownerUserId && !x.IsDeleted)
            .Select(x => new { x.Id, x.Name })
            .SingleOrDefaultAsync(cancellationToken);

        if (site is null)
            throw new UnauthorizedAccessException("The selected site does not belong to the current user.");

        var planned = items
            .Select((item, index) => MediaBatchUploadPlan.Create(index, item))
            .ToArray();
        var valid = planned.Where(x => x.Validation.IsValid).ToArray();
        var results = planned
            .Where(x => !x.Validation.IsValid)
            .Select(x => MediaBatchUploadItemResult.Rejected(
                x.Index,
                x.Item.File.Name,
                x.Validation.SafeFileName,
                x.Validation.Message))
            .ToList();

        foreach (var rejected in results)
            await ReportProgressAsync(progress, rejected, completed: results.Count, total: items.Count);

        if (valid.Length == 0)
        {
            var rejectedOnly = BuildResult(null, results, "No files were uploaded because every selected file failed validation.");
            Notify(ownerUserId, siteId, null, rejectedOnly);
            return rejectedOnly;
        }

        var jobId = executionTracker.Start(
            ownerUserId,
            siteId,
            $"Upload {valid.Length} WordPress media file(s)",
            "Media Batch Upload",
            site.Name,
            valid.Length);

        try
        {
            var processedValid = 0;
            foreach (var plan in valid)
            {
                cancellationToken.ThrowIfCancellationRequested();
                executionTracker.Report(
                    jobId,
                    processedValid,
                    valid.Length,
                    $"Uploading {plan.Validation.SafeFileName} ({processedValid + 1}/{valid.Length}).");

                MediaBatchUploadItemResult itemResult;
                try
                {
                    itemResult = await UploadOneAsync(siteId, plan, cancellationToken);
                }
                catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
                {
                    throw;
                }
                catch (Exception ex)
                {
                    logger.LogError(
                        ex,
                        "Unexpected media batch upload failure for {FileName} on site {SiteId}",
                        plan.Validation.SafeFileName,
                        siteId);
                    itemResult = MediaBatchUploadItemResult.Failed(
                        plan.Index,
                        plan.Item.File.Name,
                        plan.Validation.SafeFileName,
                        ex.Message);
                }

                results.Add(itemResult);
                processedValid++;
                executionTracker.Report(
                    jobId,
                    processedValid,
                    valid.Length,
                    itemResult.State == MediaBatchUploadState.Succeeded
                        ? $"Uploaded {plan.Validation.SafeFileName}."
                        : $"Failed {plan.Validation.SafeFileName}: {itemResult.Message}");
                await ReportProgressAsync(progress, itemResult, completed: results.Count, total: items.Count);
            }

            var ordered = results.OrderBy(x => x.Index).ToArray();
            var succeeded = ordered.Count(x => x.State == MediaBatchUploadState.Succeeded);
            var failed = ordered.Count(x => x.State == MediaBatchUploadState.Failed);
            var rejected = ordered.Count(x => x.State == MediaBatchUploadState.Rejected);
            var message = BuildSummary(succeeded, failed, rejected);
            var final = new MediaBatchUploadResult(jobId, ordered, succeeded, failed, rejected, message);

            if (succeeded == 0)
                executionTracker.Fail(jobId, message);
            else
                executionTracker.Complete(jobId, valid.Length, valid.Length, message);

            Notify(ownerUserId, siteId, jobId, final);
            return final;
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            executionTracker.Fail(jobId, "Media batch upload was cancelled.");
            Notify(
                ownerUserId,
                siteId,
                jobId,
                new MediaBatchUploadResult(
                    jobId,
                    results.OrderBy(x => x.Index).ToArray(),
                    results.Count(x => x.State == MediaBatchUploadState.Succeeded),
                    results.Count(x => x.State == MediaBatchUploadState.Failed),
                    results.Count(x => x.State == MediaBatchUploadState.Rejected),
                    "Media batch upload was cancelled."));
            throw;
        }
    }

    private async Task<MediaBatchUploadItemResult> UploadOneAsync(
        Guid siteId,
        MediaBatchUploadPlan plan,
        CancellationToken cancellationToken)
    {
        await using var stream = plan.Item.File.OpenReadStream(MediaUploadPolicy.MaxUploadSize, cancellationToken);
        using var content = new MultipartFormDataContent();
        using var fileContent = new StreamContent(stream);
        fileContent.Headers.ContentType = new MediaTypeHeaderValue(plan.Validation.ContentType);
        content.Add(fileContent, "file", plan.Validation.SafeFileName);
        AddText(content, "title", plan.Item.Title);
        AddText(content, "alt_text", plan.Item.AltText);
        AddText(content, "caption", plan.Item.Caption);

        var response = await apiClient.SendContentAsync(
            siteId,
            HttpMethod.Post,
            "/wp-json/wp/v2/media",
            content,
            cancellationToken);

        if (!response.IsSuccess || response.Value is null)
        {
            response.Value?.Dispose();
            logger.LogWarning(
                "Media batch upload failed for {FileName} on site {SiteId}: {Error}",
                plan.Validation.SafeFileName,
                siteId,
                response.ErrorMessage);
            return MediaBatchUploadItemResult.Failed(
                plan.Index,
                plan.Item.File.Name,
                plan.Validation.SafeFileName,
                string.IsNullOrWhiteSpace(response.ErrorMessage) ? "WordPress rejected the upload." : response.ErrorMessage);
        }

        using var json = response.Value;
        var mediaId = GetInt(json.RootElement, "id");
        var sourceUrl = GetString(json.RootElement, "source_url");
        logger.LogInformation(
            "Media batch upload completed for {FileName} as WordPress media {MediaId}",
            plan.Validation.SafeFileName,
            mediaId);
        return MediaBatchUploadItemResult.Succeeded(
            plan.Index,
            plan.Item.File.Name,
            plan.Validation.SafeFileName,
            mediaId,
            sourceUrl);
    }

    private void Notify(Guid ownerUserId, Guid siteId, Guid? jobId, MediaBatchUploadResult result)
    {
        var severity = result.Succeeded > 0 && result.Failed == 0 && result.Rejected == 0
            ? NotificationSeverity.Success
            : result.Succeeded > 0
                ? NotificationSeverity.Warning
                : NotificationSeverity.Error;
        var title = severity switch
        {
            NotificationSeverity.Success => "Media batch uploaded",
            NotificationSeverity.Warning => "Media batch completed with warnings",
            _ => "Media batch upload failed"
        };

        try
        {
            notifications.Create(
                ownerUserId,
                title,
                result.Message,
                severity,
                siteId: siteId,
                executionJobId: jobId,
                source: "WordPressMediaBatch");
        }
        catch (Exception ex)
        {
            logger.LogWarning(ex, "Failed to persist notification for media batch job {ExecutionJobId}.", jobId);
        }
    }

    private static async Task ReportProgressAsync(
        Func<MediaBatchUploadProgress, Task>? progress,
        MediaBatchUploadItemResult result,
        int completed,
        int total)
    {
        if (progress is null) return;
        await progress(new MediaBatchUploadProgress(
            Math.Clamp(completed, 0, Math.Max(1, total)),
            Math.Max(1, total),
            result));
    }

    private static MediaBatchUploadResult BuildResult(
        Guid? jobId,
        IReadOnlyCollection<MediaBatchUploadItemResult> items,
        string message)
    {
        var ordered = items.OrderBy(x => x.Index).ToArray();
        return new MediaBatchUploadResult(
            jobId,
            ordered,
            ordered.Count(x => x.State == MediaBatchUploadState.Succeeded),
            ordered.Count(x => x.State == MediaBatchUploadState.Failed),
            ordered.Count(x => x.State == MediaBatchUploadState.Rejected),
            message);
    }

    private static string BuildSummary(int succeeded, int failed, int rejected) =>
        $"Media batch completed: {succeeded} succeeded, {failed} failed, {rejected} rejected by validation.";

    private static void AddText(MultipartFormDataContent content, string name, string? value)
    {
        if (!string.IsNullOrWhiteSpace(value))
            content.Add(new StringContent(value.Trim()), name);
    }

    private static int GetInt(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.TryGetInt32(out var result) ? result : 0;

    private static string GetString(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String
            ? value.GetString() ?? string.Empty
            : string.Empty;

    private sealed record MediaBatchUploadPlan(
        int Index,
        MediaBatchUploadItem Item,
        MediaUploadValidationResult Validation)
    {
        public static MediaBatchUploadPlan Create(int index, MediaBatchUploadItem item) =>
            new(index, item, MediaUploadPolicy.Validate(item.File.Name, item.File.Size, item.File.ContentType));
    }
}

public sealed record MediaBatchUploadItem(
    IBrowserFile File,
    string? Title,
    string? AltText,
    string? Caption);

public enum MediaBatchUploadState
{
    Rejected,
    Succeeded,
    Failed
}

public sealed record MediaBatchUploadItemResult(
    int Index,
    string OriginalFileName,
    string SafeFileName,
    MediaBatchUploadState State,
    int? MediaId,
    string SourceUrl,
    string Message)
{
    public static MediaBatchUploadItemResult Rejected(int index, string originalFileName, string safeFileName, string message) =>
        new(index, originalFileName, safeFileName, MediaBatchUploadState.Rejected, null, string.Empty, message);

    public static MediaBatchUploadItemResult Succeeded(int index, string originalFileName, string safeFileName, int mediaId, string sourceUrl) =>
        new(index, originalFileName, safeFileName, MediaBatchUploadState.Succeeded, mediaId, sourceUrl, "Uploaded successfully.");

    public static MediaBatchUploadItemResult Failed(int index, string originalFileName, string safeFileName, string message) =>
        new(index, originalFileName, safeFileName, MediaBatchUploadState.Failed, null, string.Empty, message);
}

public sealed record MediaBatchUploadProgress(
    int Completed,
    int Total,
    MediaBatchUploadItemResult Item)
{
    public int Percent => Total <= 0 ? 0 : (int)Math.Round(Completed * 100d / Total);
}

public sealed record MediaBatchUploadResult(
    Guid? ExecutionJobId,
    IReadOnlyList<MediaBatchUploadItemResult> Items,
    int Succeeded,
    int Failed,
    int Rejected,
    string Message)
{
    public static MediaBatchUploadResult Empty(string message) =>
        new(null, Array.Empty<MediaBatchUploadItemResult>(), 0, 0, 0, message);
}
