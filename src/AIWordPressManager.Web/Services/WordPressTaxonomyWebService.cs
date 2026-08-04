using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressTaxonomyWebService(
    AppDbContext dbContext,
    IWordPressApiClient apiClient,
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

        var type = NormalizeTaxonomy(taxonomy);
        var response = await apiClient.SendAsync(siteId, HttpMethod.Post, $"/wp-json/wp/v2/{type}", BuildPayload(model, taxonomy), ct);
        if (!response.IsSuccess || response.Value is null)
            return new(false, response.ErrorMessage, 0);

        using var json = response.Value;
        var id = json.RootElement.TryGetProperty("id", out var idNode) && idNode.TryGetInt32(out var value) ? value : 0;
        await syncService.SynchronizeAsync(siteId, ct);
        return new(true, "تم إنشاء العنصر في WordPress بنجاح.", id);
    }

    public async Task<TaxonomyOperationResult> UpdateAsync(Guid siteId, string taxonomy, int id, TaxonomyTermEditModel model, CancellationToken ct = default)
    {
        if (id <= 0) return new(false, "رقم العنصر غير صحيح.", 0);
        if (string.IsNullOrWhiteSpace(model.Name)) return new(false, "اسم التصنيف أو الوسم مطلوب.", id);

        var type = NormalizeTaxonomy(taxonomy);
        var response = await apiClient.SendAsync(siteId, HttpMethod.Post, $"/wp-json/wp/v2/{type}/{id}", BuildPayload(model, taxonomy), ct);
        response.Value?.Dispose();
        if (!response.IsSuccess)
            return new(false, response.ErrorMessage, id);

        await syncService.SynchronizeAsync(siteId, ct);
        return new(true, "تم تعديل العنصر في WordPress بنجاح.", id);
    }

    public async Task<TaxonomyOperationResult> DeleteAsync(Guid siteId, string taxonomy, int id, CancellationToken ct = default)
    {
        if (id <= 0) return new(false, "رقم العنصر غير صحيح.", 0);

        var type = NormalizeTaxonomy(taxonomy);
        var response = await apiClient.SendAsync(siteId, HttpMethod.Delete, $"/wp-json/wp/v2/{type}/{id}?force=true", cancellationToken: ct);
        response.Value?.Dispose();
        if (!response.IsSuccess)
            return new(false, response.ErrorMessage, id);

        await syncService.SynchronizeAsync(siteId, ct);
        return new(true, "تم حذف العنصر من WordPress بنجاح.", id);
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
