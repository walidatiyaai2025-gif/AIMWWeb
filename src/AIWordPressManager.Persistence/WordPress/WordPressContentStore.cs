using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.WordPress;

public sealed class WordPressContentStore(AppDbContext dbContext, ILogger<WordPressContentStore> logger) : IWordPressContentStore
{
    public async Task<WordPressSyncSummary> SaveSnapshotAsync(Guid siteId, WordPressExplorerSnapshot snapshot, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(snapshot); var now=DateTime.UtcNow;
        var posts=Deduplicate(snapshot.Posts); var pages=Deduplicate(snapshot.Pages); var categories=snapshot.Categories.GroupBy(x=>x.Id).Select(x=>x.Last()).ToArray(); var tags=snapshot.Tags.GroupBy(x=>x.Id).Select(x=>x.Last()).ToArray(); var media=snapshot.Media.GroupBy(x=>x.Id).Select(x=>x.OrderByDescending(y=>y.ModifiedAt).First()).ToArray();
        await using var transaction=await dbContext.Database.BeginTransactionAsync(cancellationToken);
        try
        {
            var content=await UpsertContentAsync(siteId,posts,pages,snapshot.TotalPosts,snapshot.TotalPages,now,cancellationToken); var category=await UpsertCategoriesAsync(siteId,categories,snapshot.TotalCategories,now,cancellationToken); var tag=await UpsertTagsAsync(siteId,tags,snapshot.TotalTags,now,cancellationToken); var mediaResult=await UpsertMediaAsync(siteId,media,snapshot.TotalMedia,now,cancellationToken);
            await dbContext.SaveChangesAsync(cancellationToken); await transaction.CommitAsync(cancellationToken);
            var summary=new WordPressSyncSummary(content.Inserted,content.Updated,content.Unavailable,category.Inserted,category.Updated,category.Unavailable,tag.Inserted,tag.Updated,tag.Unavailable,mediaResult.Inserted,mediaResult.Updated,mediaResult.Unavailable);
            logger.LogInformation("WordPress sync persisted for {SiteId}. Inserted/updated/unavailable: content {CI}/{CU}/{CX}, categories {KI}/{KU}/{KX}, tags {TI}/{TU}/{TX}, media {MI}/{MU}/{MX}.",siteId,content.Inserted,content.Updated,content.Unavailable,category.Inserted,category.Updated,category.Unavailable,tag.Inserted,tag.Updated,tag.Unavailable,mediaResult.Inserted,mediaResult.Updated,mediaResult.Unavailable);
            return summary;
        }
        catch(Exception ex) { await transaction.RollbackAsync(CancellationToken.None); dbContext.ChangeTracker.Clear(); logger.LogError(ex,"Failed to persist WordPress snapshot for site {SiteId}.",siteId); throw; }
    }

    private async Task<BatchResult> UpsertContentAsync(Guid siteId,IReadOnlyCollection<WordPressContentItem> posts,IReadOnlyCollection<WordPressContentItem> pages,int totalPosts,int totalPages,DateTime now,CancellationToken ct)
    {
        var existing=await dbContext.WordPressContentRecords.Where(x=>x.SiteId==siteId).ToListAsync(ct); var map=existing.ToDictionary(x=>$"{x.ContentType}:{x.WordPressId}"); var seen=new HashSet<string>(); int ins=0,upd=0;
        void Apply(string type,IEnumerable<WordPressContentItem> items) { foreach(var item in items) { var key=$"{type}:{item.Id}"; seen.Add(key); if(!map.TryGetValue(key,out var e)) { e=new WordPressContentRecord(siteId,item.Id,type,now); dbContext.Add(e); map[key]=e; ins++; } else upd++; e.Update(item.Title,item.Slug,item.Status,item.Link,item.RenderedContent,item.RenderedExcerpt,item.ModifiedAt?.UtcDateTime,now); } }
        Apply("post",posts); Apply("page",pages); var unavailable=0; var postsComplete=posts.Count>=totalPosts; var pagesComplete=pages.Count>=totalPages; foreach(var e in existing.Where(x=>x.IsAvailable && ((x.ContentType=="post" && postsComplete) || (x.ContentType=="page" && pagesComplete)) && !seen.Contains($"{x.ContentType}:{x.WordPressId}"))) { e.MarkUnavailable(now); unavailable++; }
        return new(ins,upd,unavailable);
    }
    private async Task<BatchResult> UpsertCategoriesAsync(Guid siteId,IReadOnlyCollection<WordPressCategoryItem> items,int total,DateTime now,CancellationToken ct) { var existing=await dbContext.WordPressCategoryRecords.Where(x=>x.SiteId==siteId).ToListAsync(ct); var map=existing.ToDictionary(x=>x.WordPressId); var seen=new HashSet<int>(); int ins=0,upd=0; foreach(var item in items){seen.Add(item.Id); if(!map.TryGetValue(item.Id,out var e)){e=new WordPressCategoryRecord(siteId,item.Id,now);dbContext.Add(e);map[item.Id]=e;ins++;}else upd++;e.Update(item.Name,item.Slug,item.Count,now);} var unavailable=0;if(items.Count>=total){foreach(var e in existing.Where(x=>!seen.Contains(x.WordPressId)&&x.IsAvailable)){e.MarkUnavailable(now);unavailable++;}}return new(ins,upd,unavailable);}
    private async Task<BatchResult> UpsertTagsAsync(Guid siteId,IReadOnlyCollection<WordPressTagItem> items,int total,DateTime now,CancellationToken ct) { var existing=await dbContext.WordPressTagRecords.Where(x=>x.SiteId==siteId).ToListAsync(ct); var map=existing.ToDictionary(x=>x.WordPressId); var seen=new HashSet<int>(); int ins=0,upd=0; foreach(var item in items){seen.Add(item.Id);if(!map.TryGetValue(item.Id,out var e)){e=new WordPressTagRecord(siteId,item.Id,now);dbContext.Add(e);map[item.Id]=e;ins++;}else upd++;e.Update(item.Name,item.Slug,item.Count,now);}var unavailable=0;if(items.Count>=total){foreach(var e in existing.Where(x=>!seen.Contains(x.WordPressId)&&x.IsAvailable)){e.MarkUnavailable(now);unavailable++;}}return new(ins,upd,unavailable);}
    private async Task<BatchResult> UpsertMediaAsync(Guid siteId,IReadOnlyCollection<WordPressMediaItem> items,int total,DateTime now,CancellationToken ct) { var existing=await dbContext.WordPressMediaRecords.Where(x=>x.SiteId==siteId).ToListAsync(ct); var map=existing.ToDictionary(x=>x.WordPressId); var seen=new HashSet<int>(); int ins=0,upd=0; foreach(var item in items){seen.Add(item.Id);if(!map.TryGetValue(item.Id,out var e)){e=new WordPressMediaRecord(siteId,item.Id,now);dbContext.Add(e);map[item.Id]=e;ins++;}else upd++;e.Update(item.Title,item.Slug,item.MediaType,item.MimeType,item.SourceUrl,item.ModifiedAt?.UtcDateTime,now);}var unavailable=0;if(items.Count>=total){foreach(var e in existing.Where(x=>!seen.Contains(x.WordPressId)&&x.IsAvailable)){e.MarkUnavailable(now);unavailable++;}}return new(ins,upd,unavailable);}
    private static WordPressContentItem[] Deduplicate(IEnumerable<WordPressContentItem> items)=>items.GroupBy(x=>x.Id).Select(g=>g.OrderByDescending(x=>x.ModifiedAt).First()).ToArray();
    private readonly record struct BatchResult(int Inserted,int Updated,int Unavailable);
}
