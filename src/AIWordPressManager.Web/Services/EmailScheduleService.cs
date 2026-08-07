using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class EmailScheduleService(AppDbContext dbContext, CurrentUserContext currentUser)
{
    public async Task<IReadOnlyList<EmailScheduleView>> GetForSiteAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var ownerId = currentUser.UserId;
        await EnsureOwnedSiteAsync(siteId, ownerId, cancellationToken);

        return await dbContext.EmailSchedules.AsNoTracking()
            .Where(x => x.OwnerUserId == ownerId && x.SiteId == siteId && x.Scope == EmailSchedule.SiteScope)
            .OrderBy(x => x.NextRunUtc)
            .Select(x => new EmailScheduleView(
                x.Id, x.SiteId, x.Scope, x.ReportType, x.TemplateKey, x.TimezoneId, x.Frequency,
                x.TimeOfDay, x.Weekday, x.MonthDay, x.IsEnabled, x.RetryCount, x.RetryDelayMinutes,
                x.NextRunUtc, x.LastRunUtc, x.LastStatus, x.LastError))
            .ToListAsync(cancellationToken);
    }

    public async Task<EmailScheduleView> CreateSiteScheduleAsync(
        Guid siteId,
        EmailScheduleInput input,
        CancellationToken cancellationToken = default)
    {
        var ownerId = currentUser.UserId;
        await EnsureOwnedSiteAsync(siteId, ownerId, cancellationToken);
        ValidateInput(input);

        var now = DateTime.UtcNow;
        var nextRun = CalculateNext(input, now);

        var schedule = new EmailSchedule(
            ownerId,
            siteId,
            EmailSchedule.SiteScope,
            input.ReportType,
            input.TemplateKey,
            input.TimezoneId,
            now);

        ApplyTiming(schedule, input, nextRun, now);

        dbContext.EmailSchedules.Add(schedule);
        await dbContext.SaveChangesAsync(cancellationToken);
        return ToView(schedule);
    }

    public async Task<EmailScheduleView> UpdateAsync(Guid scheduleId, EmailScheduleInput input, CancellationToken cancellationToken = default)
    {
        var ownerId = currentUser.UserId;
        ValidateInput(input);

        var schedule = await dbContext.EmailSchedules
            .FirstOrDefaultAsync(x => x.Id == scheduleId && x.OwnerUserId == ownerId, cancellationToken)
            ?? throw new KeyNotFoundException("Email schedule was not found.");

        if (!string.Equals(schedule.ReportType, input.ReportType.Trim(), StringComparison.Ordinal) ||
            !string.Equals(schedule.TemplateKey, input.TemplateKey.Trim(), StringComparison.Ordinal))
            throw new InvalidOperationException("Report type and template key cannot be changed after a schedule is created. Create a new schedule instead.");

        if (schedule.SiteId.HasValue)
            await EnsureOwnedSiteAsync(schedule.SiteId.Value, ownerId, cancellationToken);

        var now = DateTime.UtcNow;
        var nextRun = CalculateNext(input, now);
        ApplyTiming(schedule, input, nextRun, now);

        await dbContext.SaveChangesAsync(cancellationToken);
        return ToView(schedule);
    }

    public async Task DeleteAsync(Guid scheduleId, CancellationToken cancellationToken = default)
    {
        var ownerId = currentUser.UserId;
        var schedule = await dbContext.EmailSchedules
            .FirstOrDefaultAsync(x => x.Id == scheduleId && x.OwnerUserId == ownerId, cancellationToken)
            ?? throw new KeyNotFoundException("Email schedule was not found.");

        dbContext.EmailSchedules.Remove(schedule);
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private async Task EnsureOwnedSiteAsync(Guid siteId, Guid ownerId, CancellationToken cancellationToken)
    {
        var owned = await dbContext.Sites.AsNoTracking()
            .AnyAsync(x => x.Id == siteId && x.OwnerUserId == ownerId, cancellationToken);
        if (!owned) throw new UnauthorizedAccessException("The requested WordPress site does not belong to the signed-in user.");
    }

    private static void ValidateInput(EmailScheduleInput input)
    {
        ArgumentNullException.ThrowIfNull(input);
        _ = EmailScheduleCalculator.ResolveTimeZone(input.TimezoneId);
        if (string.IsNullOrWhiteSpace(input.ReportType)) throw new ArgumentException("Report type is required.");
        if (string.IsNullOrWhiteSpace(input.TemplateKey)) throw new ArgumentException("Template key is required.");
    }

    private static DateTime CalculateNext(EmailScheduleInput input, DateTime utcNow) =>
        EmailScheduleCalculator.CalculateNextRunUtc(
            input.TimezoneId,
            input.Frequency,
            input.TimeOfDay,
            input.Weekday,
            input.MonthDay,
            utcNow);

    private static void ApplyTiming(EmailSchedule schedule, EmailScheduleInput input, DateTime nextRun, DateTime utcNow) =>
        schedule.Configure(
            input.TimezoneId,
            input.Frequency,
            input.TimeOfDay,
            input.Weekday,
            input.MonthDay,
            input.RetryCount,
            input.RetryDelayMinutes,
            input.IsEnabled,
            nextRun,
            utcNow);

    private static EmailScheduleView ToView(EmailSchedule x) => new(
        x.Id,
        x.SiteId,
        x.Scope,
        x.ReportType,
        x.TemplateKey,
        x.TimezoneId,
        x.Frequency,
        x.TimeOfDay,
        x.Weekday,
        x.MonthDay,
        x.IsEnabled,
        x.RetryCount,
        x.RetryDelayMinutes,
        x.NextRunUtc,
        x.LastRunUtc,
        x.LastStatus,
        x.LastError);
}

public sealed record EmailScheduleInput(
    string ReportType,
    string TemplateKey,
    string TimezoneId,
    string Frequency,
    TimeSpan TimeOfDay,
    int? Weekday,
    int? MonthDay,
    int RetryCount,
    int RetryDelayMinutes,
    bool IsEnabled);

public sealed record EmailScheduleView(
    Guid Id,
    Guid? SiteId,
    string Scope,
    string ReportType,
    string TemplateKey,
    string TimezoneId,
    string Frequency,
    TimeSpan TimeOfDay,
    int? Weekday,
    int? MonthDay,
    bool IsEnabled,
    int RetryCount,
    int RetryDelayMinutes,
    DateTime NextRunUtc,
    DateTime? LastRunUtc,
    string LastStatus,
    string? LastError);
