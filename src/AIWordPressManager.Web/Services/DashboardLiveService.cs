using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class DashboardLiveService(
    AppDbContext dbContext,
    SiteWebService siteService,
    ExecutionCenterService executionCenter)
{
    public async Task<DashboardLiveView> GetAsync(CancellationToken cancellationToken = default)
    {
        var siteSummaryTask = siteService.GetSummaryAsync(cancellationToken);
        var postsTask = dbContext.WordPressContentRecords.AsNoTracking()
            .CountAsync(x => x.IsAvailable && x.ContentType == "post", cancellationToken);
        var pagesTask = dbContext.WordPressContentRecords.AsNoTracking()
            .CountAsync(x => x.IsAvailable && x.ContentType == "page", cancellationToken);
        var mediaTask = dbContext.WordPressMediaRecords.AsNoTracking()
            .CountAsync(x => x.IsAvailable, cancellationToken);

        await Task.WhenAll(siteSummaryTask, postsTask, pagesTask, mediaTask);

        var lastSync = await dbContext.WordPressContentRecords.AsNoTracking()
            .Select(x => (DateTime?)x.LastSynchronizedAtUtc)
            .MaxAsync(cancellationToken);

        var jobs = executionCenter.GetJobs();
        var running = jobs.Count(x => x.Status is "Running" or "Waiting" or "Paused");
        var failed = jobs.Count(x => x.Status == "Failed");
        var completed = jobs.Count(x => x.Status == "Completed");
        var recentJobs = jobs.Take(5).Select(x => new DashboardJobView(
            x.Id,
            x.Title,
            x.SiteName,
            x.Status,
            x.Progress,
            x.CreatedAtUtc,
            x.CompletedAtUtc,
            x.Error)).ToArray();

        var siteSummary = await siteSummaryTask;
        var healthScore = CalculateHealthScore(siteSummary.TotalSites, siteSummary.ConnectedSites, failed);

        return new DashboardLiveView(
            siteSummary,
            await postsTask,
            await pagesTask,
            await mediaTask,
            running,
            completed,
            failed,
            healthScore,
            lastSync,
            recentJobs,
            DateTime.UtcNow);
    }

    private static int CalculateHealthScore(int totalSites, int connectedSites, int failedJobs)
    {
        if (totalSites == 0) return failedJobs == 0 ? 100 : 80;
        var connectionScore = connectedSites * 100d / totalSites;
        var failurePenalty = Math.Min(30, failedJobs * 5);
        return Math.Clamp((int)Math.Round(connectionScore - failurePenalty), 0, 100);
    }
}

public sealed record DashboardLiveView(
    DashboardSummary Sites,
    int Posts,
    int Pages,
    int Media,
    int ActiveJobs,
    int CompletedJobs,
    int FailedJobs,
    int HealthScore,
    DateTime? LastSynchronizationAtUtc,
    IReadOnlyList<DashboardJobView> RecentJobs,
    DateTime GeneratedAtUtc);

public sealed record DashboardJobView(
    Guid Id,
    string Title,
    string SiteName,
    string Status,
    int Progress,
    DateTime CreatedAtUtc,
    DateTime? CompletedAtUtc,
    string? Error);
