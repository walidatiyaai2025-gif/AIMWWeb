using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class WordPressContentRecord : Entity
{
    private WordPressContentRecord() { }

    public WordPressContentRecord(Guid siteId, int wordPressId, string contentType, DateTime utcNow)
    {
        SiteId = siteId;
        WordPressId = wordPressId;
        ContentType = contentType;
        MarkUpdated(utcNow);
    }

    public Guid SiteId { get; private set; }
    public int WordPressId { get; private set; }
    public string ContentType { get; private set; } = string.Empty;
    public string Title { get; private set; } = string.Empty;
    public string Slug { get; private set; } = string.Empty;
    public string Status { get; private set; } = string.Empty;
    public string Link { get; private set; } = string.Empty;
    public string RenderedContent { get; private set; } = string.Empty;
    public string RenderedExcerpt { get; private set; } = string.Empty;
    public DateTime? ModifiedAtUtc { get; private set; }
    public bool IsAvailable { get; private set; } = true;
    public DateTime LastSynchronizedAtUtc { get; private set; }
    public Site Site { get; private set; } = null!;

    public void Update(string title, string slug, string status, string link, string renderedContent, string renderedExcerpt, DateTime? modifiedAtUtc, DateTime utcNow)
    {
        Title = title;
        Slug = slug;
        Status = status;
        Link = link;
        RenderedContent = renderedContent;
        RenderedExcerpt = renderedExcerpt;
        ModifiedAtUtc = modifiedAtUtc;
        IsAvailable = true;
        LastSynchronizedAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public void MarkUnavailable(DateTime utcNow)
    {
        IsAvailable = false;
        LastSynchronizedAtUtc = utcNow;
        MarkUpdated(utcNow);
    }
}
