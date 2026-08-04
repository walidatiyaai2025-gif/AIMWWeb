using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class WordPressMediaRecord : Entity
{
    private WordPressMediaRecord() { }
    public WordPressMediaRecord(Guid siteId, int wordPressId, DateTime utcNow) { SiteId=siteId; WordPressId=wordPressId; IsAvailable=true; MarkUpdated(utcNow); }
    public Guid SiteId { get; private set; }
    public int WordPressId { get; private set; }
    public string Title { get; private set; } = string.Empty;
    public string Slug { get; private set; } = string.Empty;
    public string MediaType { get; private set; } = string.Empty;
    public string MimeType { get; private set; } = string.Empty;
    public string SourceUrl { get; private set; } = string.Empty;
    public DateTime? ModifiedAtUtc { get; private set; }
    public bool IsAvailable { get; private set; } = true;
    public DateTime LastSynchronizedAtUtc { get; private set; }
    public Site Site { get; private set; } = null!;
    public void Update(string title,string slug,string mediaType,string mimeType,string sourceUrl,DateTime? modifiedAtUtc,DateTime utcNow) { Title=title; Slug=slug; MediaType=mediaType; MimeType=mimeType; SourceUrl=sourceUrl; ModifiedAtUtc=modifiedAtUtc; IsAvailable=true; LastSynchronizedAtUtc=utcNow; MarkUpdated(utcNow); }
    public void MarkUnavailable(DateTime utcNow) { IsAvailable=false; LastSynchronizedAtUtc=utcNow; MarkUpdated(utcNow); }
}
