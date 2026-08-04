using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Jobs;

public sealed class ExecutionJobStore(AppDbContext dbContext) : IExecutionJobStore
{
    public async Task<Guid> StartAsync(Guid siteId, string jobType, CancellationToken cancellationToken = default)
    {
        var job = new ExecutionJob(siteId, jobType, DateTime.UtcNow);
        dbContext.ExecutionJobs.Add(job);
        await dbContext.SaveChangesAsync(cancellationToken);
        return job.Id;
    }

    public async Task ReportAsync(Guid jobId, int progressPercent, string currentStep, CancellationToken cancellationToken = default)
    {
        var job = await Find(jobId, cancellationToken);
        job.ReportProgress(progressPercent, currentStep, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task CompleteAsync(Guid jobId, CancellationToken cancellationToken = default)
    {
        var job = await Find(jobId, cancellationToken);
        job.Complete(DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task FailAsync(Guid jobId, string error, CancellationToken cancellationToken = default)
    {
        var job = await Find(jobId, cancellationToken);
        job.Fail(error, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task CancelAsync(Guid jobId, CancellationToken cancellationToken = default)
    {
        var job = await Find(jobId, cancellationToken);
        job.Cancel(DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<IReadOnlyList<ExecutionJobListItem>> GetRecentAsync(Guid? siteId = null, int take = 200, CancellationToken cancellationToken = default)
    {
        var query = dbContext.ExecutionJobs.AsNoTracking().Include(x => x.Site).AsQueryable();
        if (siteId.HasValue) query = query.Where(x => x.SiteId == siteId.Value);

        return await query
            .OrderByDescending(x => x.UpdatedAtUtc)
            .Take(Math.Clamp(take, 1, 1000))
            .Select(x => new ExecutionJobListItem(
                x.Id,
                x.SiteId,
                x.Site.Name,
                x.JobType,
                x.Status,
                x.ProgressPercent,
                x.CurrentStep,
                x.ErrorDetails,
                x.StartedAtUtc,
                x.CompletedAtUtc,
                x.UpdatedAtUtc))
            .ToListAsync(cancellationToken);
    }

    public async Task<ExecutionJobListItem?> GetAsync(Guid jobId, CancellationToken cancellationToken = default) =>
        await dbContext.ExecutionJobs.AsNoTracking()
            .Where(x => x.Id == jobId)
            .Select(x => new ExecutionJobListItem(
                x.Id,
                x.SiteId,
                x.Site.Name,
                x.JobType,
                x.Status,
                x.ProgressPercent,
                x.CurrentStep,
                x.ErrorDetails,
                x.StartedAtUtc,
                x.CompletedAtUtc,
                x.UpdatedAtUtc))
            .SingleOrDefaultAsync(cancellationToken);

    private async Task<ExecutionJob> Find(Guid id, CancellationToken token) =>
        await dbContext.ExecutionJobs.SingleAsync(x => x.Id == id, token);
}
