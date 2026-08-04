using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Settings;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Jobs;

public sealed class JobFailureGate(AppDbContext dbContext, IApplicationSettingsService settingsService) : IJobFailureGate
{
    public async Task<JobGateDecision> CanStartAsync(Guid siteId, string jobType, CancellationToken cancellationToken = default)
    {
        var settings = await settingsService.GetJobReliabilitySettingsAsync(cancellationToken);
        if (!settings.PauseAfterFailures) return JobGateDecision.Allowed();

        var recent = await dbContext.ExecutionJobs.AsNoTracking()
            .Where(x => x.SiteId == siteId && x.JobType == jobType)
            .OrderByDescending(x => x.StartedAtUtc)
            .Take(settings.ConsecutiveFailuresBeforePause)
            .Select(x => new { x.Status, x.CompletedAtUtc, x.UpdatedAtUtc })
            .ToListAsync(cancellationToken);

        if (recent.Count < settings.ConsecutiveFailuresBeforePause || recent.Any(x => x.Status != "Failed"))
            return JobGateDecision.Allowed();

        var lastFailureUtc = recent.Max(x => x.CompletedAtUtc ?? x.UpdatedAtUtc);
        var resumeAtUtc = lastFailureUtc.AddMinutes(settings.FailurePauseMinutes);
        if (DateTime.UtcNow >= resumeAtUtc && settings.AutoResumeAfterPause)
            return JobGateDecision.Allowed();

        var remaining = resumeAtUtc - DateTime.UtcNow;
        if (remaining < TimeSpan.Zero) remaining = TimeSpan.Zero;
        return new(false, resumeAtUtc,
            $"{jobType} is paused after {settings.ConsecutiveFailuresBeforePause} consecutive failures. " +
            $"Try again in {Math.Ceiling(remaining.TotalMinutes)} minute(s), at {resumeAtUtc.ToLocalTime():g}.");
    }
}
