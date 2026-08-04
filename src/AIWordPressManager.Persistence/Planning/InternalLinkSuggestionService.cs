using System.Net;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.Planning;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Planning;

public sealed class InternalLinkSuggestionService(AppDbContext dbContext) : IInternalLinkSuggestionService
{
    public async Task<IReadOnlyList<InternalLinkSuggestionItem>> GenerateAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var content = await dbContext.WordPressContentRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable && x.Status == "publish").ToListAsync(cancellationToken);
        var results = new List<InternalLinkSuggestionItem>();
        foreach (var source in content)
        {
            var text = Normalize(source.Title + " " + StripHtml(source.RenderedContent));
            var sourceWords = Keywords(text);
            foreach (var target in content.Where(x => x.Id != source.Id))
            {
                if (!string.IsNullOrWhiteSpace(target.Link) && source.RenderedContent.Contains(target.Link, StringComparison.OrdinalIgnoreCase)) continue;
                var targetWords = Keywords(Normalize(target.Title + " " + target.Slug));
                var overlap = sourceWords.Intersect(targetWords).Count();
                if (overlap < 2) continue;
                var confidence = Math.Min(0.95, 0.45 + overlap * 0.1);
                results.Add(new(source.WordPressId, source.Title, target.WordPressId, target.Title, target.Title, $"Shared topical terms: {overlap}.", confidence));
            }
        }
        return results.OrderByDescending(x => x.Confidence).ThenBy(x => x.SourceTitle).Take(200).ToArray();
    }
    private static string StripHtml(string value) => WebUtility.HtmlDecode(Regex.Replace(value ?? string.Empty, "<[^>]+>", " "));
    private static string Normalize(string value) => Regex.Replace(value.ToLowerInvariant(), "[^\\p{L}\\p{N}]+", " ");
    private static HashSet<string> Keywords(string value) => value.Split(' ', StringSplitOptions.RemoveEmptyEntries).Where(x => x.Length >= 4).ToHashSet(StringComparer.OrdinalIgnoreCase);
}
