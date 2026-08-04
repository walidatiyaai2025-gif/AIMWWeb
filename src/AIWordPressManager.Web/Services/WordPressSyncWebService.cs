using System.Net;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressSyncWebService(
    AppDbContext dbContext,
    IWordPressApiClient wordPressApiClient,
    IWordPressContentStore contentStore,
    ExecutionOperationTracker executionTracker,
    AppNotificationService notifications)
{
    private const int PageSize = 100;

    public async Task<WordPressSyncViewResult> SynchronizeAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var site = await dbContext.Sites.AsNoTracking().FirstOrDefaultAsync(x => x.Id == siteId, cancellationToken)
            ?? throw new InvalidOperationException("Site was not found.");

        notifications.Info($"Synchronization started for {site.Name}.", "Synchronization");
        var jobId = executionTracker.Start("Synchronize WordPress content", "Synchronization", site.Name, 7);
        try
        {
            executionTracker.Report(jobId, 1, 7, "Preparing the unified WordPress API client.");

            var lastSync = await GetLastSuccessfulSyncAsync(siteId, cancellationToken);
            if (lastSync.HasValue)
            {
                executionTracker.Report(jobId, 2, 7, $"Checking remote changes since {lastSync.Value:O}.");
                var delta = await ProbeChangesAsync(siteId, lastSync.Value, cancellationToken);
                if (!delta.HasChanges)
                {
                    const string unchangedMessage = "No new post, page, or media changes were found since the last synchronization.";
                    executionTracker.Complete(jobId, 7, 7, unchangedMessage);
                    notifications.Info(unchangedMessage, "Synchronization complete");
                    return new WordPressSyncViewResult(true, unchangedMessage, WordPressSyncSummary.Empty, DateTime.UtcNow, true, 0);
                }

                executionTracker.Report(jobId, 2, 7, $"Detected {delta.ChangedItems} changed remote items. Starting verified full refresh.");
            }
            else
            {
                executionTracker.Report(jobId, 2, 7, "No previous synchronization was found. Starting initial full synchronization.");
            }

            executionTracker.Report(jobId, 3, 7, "Connecting to WordPress REST API.");
            executionTracker.Report(jobId, 4, 7, "Downloading posts, pages, taxonomies and media.");

            var postsTask = LoadContentAsync(siteId, "/wp-json/wp/v2/posts", cancellationToken);
            var pagesTask = LoadContentAsync(siteId, "/wp-json/wp/v2/pages", cancellationToken);
            var categoriesTask = LoadTermsAsync(siteId, "/wp-json/wp/v2/categories", cancellationToken);
            var tagsTask = LoadTermsAsync(siteId, "/wp-json/wp/v2/tags", cancellationToken);
            var mediaTask = LoadMediaAsync(siteId, "/wp-json/wp/v2/media", cancellationToken);

            await Task.WhenAll(postsTask, pagesTask, categoriesTask, tagsTask, mediaTask);
            var posts = await postsTask;
            var pages = await pagesTask;
            var categories = await categoriesTask;
            var categoryShapedTags = await tagsTask;
            var tags = new PagedResult<WordPressTagItem>(
                categoryShapedTags.Items.Select(x => new WordPressTagItem(x.Id, x.Name, x.Slug, x.Count)).ToList(),
                categoryShapedTags.Total);
            var media = await mediaTask;

            var downloaded = posts.Total + pages.Total + categories.Total + tags.Total + media.Total;
            executionTracker.Report(jobId, 5, 7, $"Downloaded {downloaded} records.");
            var snapshot = new WordPressExplorerSnapshot(
                posts.Items, pages.Items, categories.Items, tags.Items, media.Items,
                posts.Total, pages.Total, categories.Total, tags.Total, media.Total,
                DateTimeOffset.UtcNow, WordPressSyncSummary.Empty);

            executionTracker.Report(jobId, 6, 7, "Saving synchronized data to the local database.");
            var summary = await contentStore.SaveSnapshotAsync(siteId, snapshot, cancellationToken);
            var message = $"Synchronization completed: {posts.Total} posts, {pages.Total} pages, {categories.Total} categories, {tags.Total} tags, and {media.Total} media items.";
            executionTracker.Complete(jobId, 7, 7, message);
            notifications.Success(message, "Synchronization complete");
            return new WordPressSyncViewResult(true, message, summary, DateTime.UtcNow, false, downloaded);
        }
        catch (Exception ex)
        {
            executionTracker.Fail(jobId, ex.Message);
            notifications.Error("WordPress synchronization failed.", "Synchronization failed", ex.ToString());
            throw;
        }
    }

    public async Task<ContentExplorerView> GetExplorerAsync(Guid siteId, string? query = null, string type = "all", CancellationToken cancellationToken = default)
    {
        query = query?.Trim();
        var contentQuery = dbContext.WordPressContentRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable);
        if (type is "post" or "page") contentQuery = contentQuery.Where(x => x.ContentType == type);
        if (!string.IsNullOrWhiteSpace(query)) contentQuery = contentQuery.Where(x => x.Title.Contains(query) || x.Slug.Contains(query));

        var content = await contentQuery.OrderByDescending(x => x.ModifiedAtUtc).ThenBy(x => x.Title).Take(500)
            .Select(x => new ContentExplorerItem(x.WordPressId, x.ContentType, x.Title, x.Slug, x.Status, x.Link, x.ModifiedAtUtc, x.LastSynchronizedAtUtc))
            .ToListAsync(cancellationToken);

        var categories = await dbContext.WordPressCategoryRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable)
            .OrderByDescending(x => x.PostCount).Select(x => new TaxonomyExplorerItem(x.WordPressId, x.Name, x.Slug, x.PostCount)).ToListAsync(cancellationToken);
        var tags = await dbContext.WordPressTagRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable)
            .OrderByDescending(x => x.PostCount).Take(300).Select(x => new TaxonomyExplorerItem(x.WordPressId, x.Name, x.Slug, x.PostCount)).ToListAsync(cancellationToken);
        var media = await dbContext.WordPressMediaRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable)
            .OrderByDescending(x => x.ModifiedAtUtc).Take(300).Select(x => new MediaExplorerItem(x.WordPressId, x.Title, x.MediaType, x.MimeType, x.SourceUrl, x.ModifiedAtUtc)).ToListAsync(cancellationToken);

        var totals = new ExplorerTotals(
            await dbContext.WordPressContentRecords.CountAsync(x => x.SiteId == siteId && x.IsAvailable && x.ContentType == "post", cancellationToken),
            await dbContext.WordPressContentRecords.CountAsync(x => x.SiteId == siteId && x.IsAvailable && x.ContentType == "page", cancellationToken),
            categories.Count, tags.Count,
            await dbContext.WordPressMediaRecords.CountAsync(x => x.SiteId == siteId && x.IsAvailable, cancellationToken));
        var lastSync = await dbContext.WordPressContentRecords.Where(x => x.SiteId == siteId).MaxAsync(x => (DateTime?)x.LastSynchronizedAtUtc, cancellationToken);
        return new ContentExplorerView(content, categories, tags, media, totals, lastSync);
    }

    private async Task<DateTime?> GetLastSuccessfulSyncAsync(Guid siteId, CancellationToken ct)
    {
        var contentSync = await dbContext.WordPressContentRecords.AsNoTracking()
            .Where(x => x.SiteId == siteId)
            .MaxAsync(x => (DateTime?)x.LastSynchronizedAtUtc, ct);
        var mediaSync = await dbContext.WordPressMediaRecords.AsNoTracking()
            .Where(x => x.SiteId == siteId)
            .MaxAsync(x => (DateTime?)x.LastSynchronizedAtUtc, ct);

        if (!contentSync.HasValue) return mediaSync;
        if (!mediaSync.HasValue) return contentSync;
        return contentSync.Value <= mediaSync.Value ? contentSync : mediaSync;
    }

    private async Task<DeltaProbeResult> ProbeChangesAsync(Guid siteId, DateTime lastSyncUtc, CancellationToken ct)
    {
        var safeCursor = lastSyncUtc.ToUniversalTime().AddMinutes(-2).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'");
        var paths = new[]
        {
            $"/wp-json/wp/v2/posts?context=edit&per_page=1&modified_after={Uri.EscapeDataString(safeCursor)}&_fields=id,modified_gmt",
            $"/wp-json/wp/v2/pages?context=edit&per_page=1&modified_after={Uri.EscapeDataString(safeCursor)}&_fields=id,modified_gmt",
            $"/wp-json/wp/v2/media?context=edit&per_page=1&modified_after={Uri.EscapeDataString(safeCursor)}&_fields=id,modified_gmt"
        };

        var changed = 0;
        foreach (var path in paths)
        {
            var response = await wordPressApiClient.GetAsync(siteId, path, ct);
            EnsureSuccess(response, path);
            changed += ReadTotal(response.Headers, 0);
            response.Value?.Dispose();
        }

        return new DeltaProbeResult(changed > 0, changed);
    }

    private async Task<PagedResult<WordPressContentItem>> LoadContentAsync(Guid siteId, string endpoint, CancellationToken ct)
    {
        var items = new List<WordPressContentItem>();
        var total = 0;
        for (var page = 1; ; page++)
        {
            var response = await wordPressApiClient.GetAsync(siteId, $"{endpoint}?context=edit&per_page={PageSize}&page={page}&orderby=modified&order=desc", ct);
            if (response.StatusCode == HttpStatusCode.BadRequest && page > 1) break;
            EnsureSuccess(response, endpoint);
            total = ReadTotal(response.Headers, total);
            using var json = response.Value!;
            var count = 0;
            foreach (var item in json.RootElement.EnumerateArray())
            {
                count++;
                items.Add(new WordPressContentItem(
                    GetInt(item, "id"), GetRendered(item, "title"), GetString(item, "slug"), GetString(item, "status"), GetString(item, "link"),
                    GetDate(item, "modified_gmt") ?? GetDate(item, "modified"), GetRendered(item, "content"), GetRendered(item, "excerpt")));
            }
            if (count < PageSize || items.Count >= total && total > 0) break;
        }
        return new(items, Math.Max(total, items.Count));
    }

    private async Task<PagedResult<WordPressCategoryItem>> LoadTermsAsync(Guid siteId, string endpoint, CancellationToken ct)
    {
        var items = new List<WordPressCategoryItem>();
        var total = 0;
        for (var page = 1; ; page++)
        {
            var response = await wordPressApiClient.GetAsync(siteId, $"{endpoint}?context=edit&per_page={PageSize}&page={page}&hide_empty=false", ct);
            if (response.StatusCode == HttpStatusCode.BadRequest && page > 1) break;
            EnsureSuccess(response, endpoint);
            total = ReadTotal(response.Headers, total);
            using var json = response.Value!;
            var count = 0;
            foreach (var item in json.RootElement.EnumerateArray())
            {
                count++;
                items.Add(new WordPressCategoryItem(GetInt(item, "id"), GetString(item, "name"), GetString(item, "slug"), GetInt(item, "count")));
            }
            if (count < PageSize || items.Count >= total && total > 0) break;
        }
        return new(items, Math.Max(total, items.Count));
    }

    private async Task<PagedResult<WordPressMediaItem>> LoadMediaAsync(Guid siteId, string endpoint, CancellationToken ct)
    {
        var items = new List<WordPressMediaItem>();
        var total = 0;
        for (var page = 1; ; page++)
        {
            var response = await wordPressApiClient.GetAsync(siteId, $"{endpoint}?context=edit&per_page={PageSize}&page={page}&orderby=modified&order=desc", ct);
            if (response.StatusCode == HttpStatusCode.BadRequest && page > 1) break;
            EnsureSuccess(response, endpoint);
            total = ReadTotal(response.Headers, total);
            using var json = response.Value!;
            var count = 0;
            foreach (var item in json.RootElement.EnumerateArray())
            {
                count++;
                items.Add(new WordPressMediaItem(
                    GetInt(item, "id"), GetRendered(item, "title"), GetString(item, "slug"), GetString(item, "media_type"),
                    GetString(item, "mime_type"), GetString(item, "source_url"), GetDate(item, "modified_gmt") ?? GetDate(item, "modified")));
            }
            if (count < PageSize || items.Count >= total && total > 0) break;
        }
        return new(items, Math.Max(total, items.Count));
    }

    private static void EnsureSuccess(WordPressApiResponse<JsonDocument> response, string endpoint)
    {
        if (response.IsSuccess && response.Value is not null) return;
        response.Value?.Dispose();
        throw new InvalidOperationException($"WordPress request to {endpoint} failed. {response.ErrorMessage}");
    }

    private static int ReadTotal(IReadOnlyDictionary<string, string> headers, int fallback) =>
        headers.TryGetValue("X-WP-Total", out var value) && int.TryParse(value, out var total) ? total : fallback;

    private static int GetInt(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.TryGetInt32(out var result) ? result : 0;
    private static string GetString(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() ?? string.Empty : string.Empty;
    private static string GetRendered(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.Object && value.TryGetProperty("rendered", out var rendered) ? rendered.GetString() ?? string.Empty : string.Empty;
    private static DateTimeOffset? GetDate(JsonElement item, string name) => DateTimeOffset.TryParse(GetString(item, name), out var value) ? value : null;
    private sealed record PagedResult<T>(IReadOnlyList<T> Items, int Total);
    private sealed record DeltaProbeResult(bool HasChanges, int ChangedItems);
}

public sealed record WordPressSyncViewResult(
    bool IsSuccess,
    string Message,
    WordPressSyncSummary Summary,
    DateTime CompletedAtUtc,
    bool WasSkipped = false,
    int DownloadedRecords = 0);
public sealed record ContentExplorerView(IReadOnlyList<ContentExplorerItem> Content, IReadOnlyList<TaxonomyExplorerItem> Categories, IReadOnlyList<TaxonomyExplorerItem> Tags, IReadOnlyList<MediaExplorerItem> Media, ExplorerTotals Totals, DateTime? LastSynchronizedAtUtc);
public sealed record ContentExplorerItem(int WordPressId, string ContentType, string Title, string Slug, string Status, string Link, DateTime? ModifiedAtUtc, DateTime LastSynchronizedAtUtc);
public sealed record TaxonomyExplorerItem(int WordPressId, string Name, string Slug, int Count);
public sealed record MediaExplorerItem(int WordPressId, string Title, string MediaType, string MimeType, string SourceUrl, DateTime? ModifiedAtUtc);
public sealed record ExplorerTotals(int Posts, int Pages, int Categories, int Tags, int Media);
