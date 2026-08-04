using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class WordPressTagRecord : Entity
{
    private WordPressTagRecord() { }
    public WordPressTagRecord(Guid siteId, int wordPressId, DateTime utcNow) { SiteId = siteId; WordPressId = wordPressId; IsAvailable = true; MarkUpdated(utcNow); }
    public Guid SiteId { get; private set; }
    public int WordPressId { get; private set; }
    public string Name { get; private set; } = string.Empty;
    public string Slug { get; private set; } = string.Empty;
    public int PostCount { get; private set; }
    public bool IsAvailable { get; private set; } = true;
    public DateTime LastSynchronizedAtUtc { get; private set; }
    public Site Site { get; private set; } = null!;
    public void Update(string name, string slug, int postCount, DateTime utcNow) { Name=name; Slug=slug; PostCount=postCount; IsAvailable=true; LastSynchronizedAtUtc=utcNow; MarkUpdated(utcNow); }
    public void MarkUnavailable(DateTime utcNow) { IsAvailable=false; LastSynchronizedAtUtc=utcNow; MarkUpdated(utcNow); }
}
