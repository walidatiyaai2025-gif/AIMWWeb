using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressSyncWebService(
    AppDbContext dbContext,
    IHttpClientFactory httpClientFactory,
    ISecretProtectionService secretProtectionService,
    IWordPressContentStore contentStore)
{
    private const int PageSize = 100;

    public async Task<WordPressSyncViewResult> SynchronizeAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var site = await dbContext.Sites.AsNoTracking().FirstOrDefaultAsync(x => x.Id == siteId, cancellationToken)
            ?? throw new InvalidOperationException("الموقع غير موجود.");
        var credential = await dbContext.SiteCredentials.AsNoTracking().FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken)
            ?? throw new InvalidOperationException("احفظ بيانات اتصال WordPress واختبرها أولًا.");

        string password;
        try
        {
            password = await secretProtectionService.UnprotectAsync(credential.ProtectedApplicationPassword, cancellationToken);
        }
        catch (Exception ex)
        {
            throw new InvalidOperationException("تعذر قراءة كلمة المرور المشفرة. أعد حفظ بيانات الاتصال.", ex);
        }

        var client = httpClientFactory.CreateClient(nameof(WordPressSyncWebService));
        client.Timeout = TimeSpan.FromMinutes(3);
        client.DefaultRequestHeaders.UserAgent.ParseAdd("AIWordPressManager/1.0");
        var rawCredential = $"{credential.UserName}:{password.Replace(" ", string.Empty, StringComparison.Ordinal)}";
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Basic", Convert.ToBase64String(Encoding.UTF8.GetBytes(rawCredential)));
        var root = site.SiteUrl.TrimEnd('/');

        var postsTask = LoadContentAsync(client, $"{root}/wp-json/wp/v2/posts", cancellationToken);
        var pagesTask = LoadContentAsync(client, $"{root}/wp-json/wp/v2/pages", cancellationToken);
        var categoriesTask = LoadTermsAsync(client, $"{root}/wp-json/wp/v2/categories", cancellationToken);
        var tagsTask = LoadTermsAsync(client, $"{root}/wp-json/wp/v2/tags", cancellationToken);
        var mediaTask = LoadMediaAsync(client, $"{root}/wp-json/wp/v2/media", cancellationToken);

        await Task.WhenAll(postsTask, pagesTask, categoriesTask, tagsTask, mediaTask);
        var posts = await postsTask;
        var pages = await pagesTask;
        var categories = await categoriesTask;
        var categoryShapedTags = await tagsTask;
        var tags = new PagedResult<WordPressTagItem>(
            categoryShapedTags.Items
                .Select(x => new WordPressTagItem(x.Id, x.Name, x.Slug, x.Count))
                .ToList(),
            categoryShapedTags.Total);
        var media = await mediaTask;

        var snapshot = new WordPressExplorerSnapshot(
            posts.Items, pages.Items, categories.Items, tags.Items, media.Items,
            posts.Total, pages.Total, categories.Total, tags.Total, media.Total,
            DateTimeOffset.UtcNow, WordPressSyncSummary.Empty);

        var summary = await contentStore.SaveSnapshotAsync(siteId, snapshot, cancellationToken);
        return new WordPressSyncViewResult(true,
            $"اكتملت المزامنة: {posts.Total} مقال، {pages.Total} صفحة، {categories.Total} تصنيف، {tags.Total} وسم، {media.Total} ملف وسائط.",
            summary,
            DateTime.UtcNow);
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

    private static async Task<PagedResult<WordPressContentItem>> LoadContentAsync(HttpClient client, string endpoint, CancellationToken ct)
    {
        var items = new List<WordPressContentItem>();
        var total = 0;
        for (var page = 1; ; page++)
        {
            using var response = await client.GetAsync($"{endpoint}?context=edit&per_page={PageSize}&page={page}&orderby=modified&order=desc", ct);
            if ((int)response.StatusCode == 400 && page > 1) break;
            await EnsureSuccessAsync(response, endpoint, ct);
            total = ReadTotal(response, total);
            await using var stream = await response.Content.ReadAsStreamAsync(ct);
            using var json = await JsonDocument.ParseAsync(stream, cancellationToken: ct);
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

    private static async Task<PagedResult<WordPressCategoryItem>> LoadTermsAsync(HttpClient client, string endpoint, CancellationToken ct)
    {
        var items = new List<WordPressCategoryItem>(); var total = 0;
        for (var page = 1; ; page++)
        {
            using var response = await client.GetAsync($"{endpoint}?context=edit&per_page={PageSize}&page={page}&hide_empty=false", ct);
            if ((int)response.StatusCode == 400 && page > 1) break;
            await EnsureSuccessAsync(response, endpoint, ct); total = ReadTotal(response, total);
            await using var stream = await response.Content.ReadAsStreamAsync(ct); using var json = await JsonDocument.ParseAsync(stream, cancellationToken: ct);
            var count = 0; foreach (var item in json.RootElement.EnumerateArray()) { count++; items.Add(new(GetInt(item,"id"),GetString(item,"name"),GetString(item,"slug"),GetInt(item,"count"))); }
            if (count < PageSize || items.Count >= total && total > 0) break;
        }
        return new(items, Math.Max(total, items.Count));
    }

    private static async Task<PagedResult<WordPressMediaItem>> LoadMediaAsync(HttpClient client, string endpoint, CancellationToken ct)
    {
        var items = new List<WordPressMediaItem>(); var total = 0;
        for (var page = 1; ; page++)
        {
            using var response = await client.GetAsync($"{endpoint}?context=edit&per_page={PageSize}&page={page}&orderby=modified&order=desc", ct);
            if ((int)response.StatusCode == 400 && page > 1) break;
            await EnsureSuccessAsync(response, endpoint, ct); total = ReadTotal(response, total);
            await using var stream = await response.Content.ReadAsStreamAsync(ct); using var json = await JsonDocument.ParseAsync(stream, cancellationToken: ct);
            var count = 0; foreach (var item in json.RootElement.EnumerateArray()) { count++; items.Add(new(GetInt(item,"id"),GetRendered(item,"title"),GetString(item,"slug"),GetString(item,"media_type"),GetString(item,"mime_type"),GetString(item,"source_url"),GetDate(item,"modified_gmt") ?? GetDate(item,"modified"))); }
            if (count < PageSize || items.Count >= total && total > 0) break;
        }
        return new(items, Math.Max(total, items.Count));
    }

    private static async Task EnsureSuccessAsync(HttpResponseMessage response, string endpoint, CancellationToken ct)
    {
        if (response.IsSuccessStatusCode) return;
        var body = await response.Content.ReadAsStringAsync(ct);
        if (response.StatusCode is System.Net.HttpStatusCode.Unauthorized or System.Net.HttpStatusCode.Forbidden)
            throw new InvalidOperationException("فشل تسجيل الدخول أو لا يملك المستخدم صلاحية قراءة المحتوى.");
        throw new HttpRequestException($"فشل طلب WordPress ({(int)response.StatusCode}) إلى {endpoint}. {body[..Math.Min(body.Length, 300)]}");
    }

    private static int ReadTotal(HttpResponseMessage response, int fallback) =>
        response.Headers.TryGetValues("X-WP-Total", out var values) && int.TryParse(values.FirstOrDefault(), out var total) ? total : fallback;
    private static int GetInt(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.TryGetInt32(out var result) ? result : 0;
    private static string GetString(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() ?? string.Empty : string.Empty;
    private static string GetRendered(JsonElement item, string name) => item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.Object && value.TryGetProperty("rendered", out var rendered) ? rendered.GetString() ?? string.Empty : string.Empty;
    private static DateTimeOffset? GetDate(JsonElement item, string name) => DateTimeOffset.TryParse(GetString(item, name), out var value) ? value : null;
    private sealed record PagedResult<T>(IReadOnlyList<T> Items, int Total);
}

public sealed record WordPressSyncViewResult(bool IsSuccess, string Message, WordPressSyncSummary Summary, DateTime CompletedAtUtc);
public sealed record ContentExplorerView(IReadOnlyList<ContentExplorerItem> Content, IReadOnlyList<TaxonomyExplorerItem> Categories, IReadOnlyList<TaxonomyExplorerItem> Tags, IReadOnlyList<MediaExplorerItem> Media, ExplorerTotals Totals, DateTime? LastSynchronizedAtUtc);
public sealed record ContentExplorerItem(int WordPressId, string ContentType, string Title, string Slug, string Status, string Link, DateTime? ModifiedAtUtc, DateTime LastSynchronizedAtUtc);
public sealed record TaxonomyExplorerItem(int WordPressId, string Name, string Slug, int Count);
public sealed record MediaExplorerItem(int WordPressId, string Title, string MediaType, string MimeType, string SourceUrl, DateTime? ModifiedAtUtc);
public sealed record ExplorerTotals(int Posts, int Pages, int Categories, int Tags, int Media);
