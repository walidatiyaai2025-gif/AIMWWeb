using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressTaxonomyWebService(
    AppDbContext dbContext,
    IHttpClientFactory httpClientFactory,
    ISecretProtectionService secretProtectionService,
    WordPressSyncWebService syncService)
{
    public async Task<IReadOnlyList<TaxonomyTermView>> GetAsync(Guid siteId, string taxonomy, CancellationToken ct = default)
    {
        var type = NormalizeTaxonomy(taxonomy);
        if (type == "categories")
        {
            return await dbContext.WordPressCategoryRecords.AsNoTracking()
                .Where(x => x.SiteId == siteId && x.IsAvailable)
                .OrderBy(x => x.Name)
                .Select(x => new TaxonomyTermView(x.WordPressId, x.Name, x.Slug, x.PostCount, 0, string.Empty))
                .ToListAsync(ct);
        }

        return await dbContext.WordPressTagRecords.AsNoTracking()
            .Where(x => x.SiteId == siteId && x.IsAvailable)
            .OrderBy(x => x.Name)
            .Select(x => new TaxonomyTermView(x.WordPressId, x.Name, x.Slug, x.PostCount, 0, string.Empty))
            .ToListAsync(ct);
    }

    public async Task<TaxonomyOperationResult> CreateAsync(Guid siteId, string taxonomy, TaxonomyTermEditModel model, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(model.Name))
            return new(false, "اسم التصنيف أو الوسم مطلوب.", 0);

        var connection = await CreateConnectionAsync(siteId, ct);
        var endpoint = $"{connection.RootUrl}/wp-json/wp/v2/{NormalizeTaxonomy(taxonomy)}";
        var payload = BuildPayload(model, taxonomy);
        using var content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
        using var response = await connection.Client.PostAsync(endpoint, content, ct);
        if (!response.IsSuccessStatusCode)
            return new(false, await ReadErrorAsync(response, "تعذر إنشاء العنصر في WordPress.", ct), 0);

        using var json = JsonDocument.Parse(await response.Content.ReadAsStringAsync(ct));
        var id = json.RootElement.TryGetProperty("id", out var idNode) ? idNode.GetInt32() : 0;
        await syncService.SynchronizeAsync(siteId, ct);
        return new(true, "تم إنشاء العنصر في WordPress بنجاح.", id);
    }

    public async Task<TaxonomyOperationResult> UpdateAsync(Guid siteId, string taxonomy, int id, TaxonomyTermEditModel model, CancellationToken ct = default)
    {
        if (id <= 0) return new(false, "رقم العنصر غير صحيح.", 0);
        if (string.IsNullOrWhiteSpace(model.Name)) return new(false, "اسم التصنيف أو الوسم مطلوب.", id);

        var connection = await CreateConnectionAsync(siteId, ct);
        var endpoint = $"{connection.RootUrl}/wp-json/wp/v2/{NormalizeTaxonomy(taxonomy)}/{id}";
        using var content = new StringContent(JsonSerializer.Serialize(BuildPayload(model, taxonomy)), Encoding.UTF8, "application/json");
        using var response = await connection.Client.PostAsync(endpoint, content, ct);
        if (!response.IsSuccessStatusCode)
            return new(false, await ReadErrorAsync(response, "تعذر تعديل العنصر في WordPress.", ct), id);

        await syncService.SynchronizeAsync(siteId, ct);
        return new(true, "تم تعديل العنصر في WordPress بنجاح.", id);
    }

    public async Task<TaxonomyOperationResult> DeleteAsync(Guid siteId, string taxonomy, int id, CancellationToken ct = default)
    {
        if (id <= 0) return new(false, "رقم العنصر غير صحيح.", 0);
        var connection = await CreateConnectionAsync(siteId, ct);
        var endpoint = $"{connection.RootUrl}/wp-json/wp/v2/{NormalizeTaxonomy(taxonomy)}/{id}?force=true";
        using var response = await connection.Client.DeleteAsync(endpoint, ct);
        if (!response.IsSuccessStatusCode)
            return new(false, await ReadErrorAsync(response, "تعذر حذف العنصر من WordPress.", ct), id);

        await syncService.SynchronizeAsync(siteId, ct);
        return new(true, "تم حذف العنصر من WordPress بنجاح.", id);
    }

    private async Task<ConnectionContext> CreateConnectionAsync(Guid siteId, CancellationToken ct)
    {
        var site = await dbContext.Sites.AsNoTracking().FirstOrDefaultAsync(x => x.Id == siteId && !x.IsDeleted, ct)
            ?? throw new InvalidOperationException("الموقع غير موجود.");
        var credential = await dbContext.SiteCredentials.AsNoTracking().FirstOrDefaultAsync(x => x.SiteId == siteId, ct)
            ?? throw new InvalidOperationException("احفظ بيانات اتصال WordPress واختبرها أولًا.");
        var password = await secretProtectionService.UnprotectAsync(credential.ProtectedApplicationPassword, ct);
        var client = httpClientFactory.CreateClient(nameof(WordPressTaxonomyWebService));
        client.Timeout = TimeSpan.FromMinutes(2);
        client.DefaultRequestHeaders.UserAgent.ParseAdd("AIWordPressManager/1.0");
        var raw = $"{credential.UserName}:{password.Replace(" ", string.Empty, StringComparison.Ordinal)}";
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Basic", Convert.ToBase64String(Encoding.UTF8.GetBytes(raw)));
        return new(client, site.SiteUrl.TrimEnd('/'));
    }

    private static Dictionary<string, object?> BuildPayload(TaxonomyTermEditModel model, string taxonomy)
    {
        var payload = new Dictionary<string, object?>
        {
            ["name"] = model.Name.Trim(),
            ["slug"] = model.Slug?.Trim() ?? string.Empty,
            ["description"] = model.Description?.Trim() ?? string.Empty
        };
        if (NormalizeTaxonomy(taxonomy) == "categories" && model.ParentId > 0)
            payload["parent"] = model.ParentId;
        return payload;
    }

    private static string NormalizeTaxonomy(string? taxonomy) =>
        string.Equals(taxonomy, "tags", StringComparison.OrdinalIgnoreCase) ? "tags" : "categories";

    private static async Task<string> ReadErrorAsync(HttpResponseMessage response, string fallback, CancellationToken ct)
    {
        var body = await response.Content.ReadAsStringAsync(ct);
        try
        {
            using var json = JsonDocument.Parse(body);
            if (json.RootElement.TryGetProperty("message", out var message) && !string.IsNullOrWhiteSpace(message.GetString()))
                return message.GetString()!;
        }
        catch (JsonException) { }
        return $"{fallback} HTTP {(int)response.StatusCode}";
    }

    private sealed record ConnectionContext(HttpClient Client, string RootUrl);
}

public sealed record TaxonomyTermView(int Id, string Name, string Slug, int Count, int ParentId, string Description);
public sealed class TaxonomyTermEditModel
{
    public string Name { get; set; } = string.Empty;
    public string Slug { get; set; } = string.Empty;
    public string Description { get; set; } = string.Empty;
    public int ParentId { get; set; }
}
public sealed record TaxonomyOperationResult(bool Success, string Message, int Id);
