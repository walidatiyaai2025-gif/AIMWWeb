using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class EmailScheduleWorker(
    IServiceScopeFactory scopeFactory,
    IConfiguration configuration,
    ILogger<EmailScheduleWorker> logger) : BackgroundService
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(30);
    private static readonly TimeSpan StaleClaimAge = TimeSpan.FromMinutes(10);

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        using var timer = new PeriodicTimer(PollInterval);
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                if (configuration.GetValue<bool>("Database:SetupComplete"))
                    await ProcessDueAsync(stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                logger.LogError(ex, "Email schedule worker cycle failed.");
            }

            try
            {
                if (!await timer.WaitForNextTickAsync(stoppingToken)) break;
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
        }
    }

    private async Task ProcessDueAsync(CancellationToken cancellationToken)
    {
        for (var i = 0; i < 20; i++)
        {
            var claimed = await ClaimOneAsync(cancellationToken);
            if (claimed is null) return;
            await ProcessClaimAsync(claimed.Value.Id, claimed.Value.Token, cancellationToken);
        }
    }

    private async Task<(Guid Id, string Token)?> ClaimOneAsync(CancellationToken cancellationToken)
    {
        using var scope = scopeFactory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<AppDbContext>();
        var now = DateTime.UtcNow;
        var staleBefore = now.Subtract(StaleClaimAge);

        for (var attempt = 0; attempt < 5; attempt++)
        {
            var candidateId = await db.EmailSchedules.AsNoTracking()
                .Where(x => x.IsEnabled && x.NextRunUtc <= now && (x.ClaimToken == null || x.ClaimedAtUtc == null || x.ClaimedAtUtc <= staleBefore))
                .OrderBy(x => x.NextRunUtc)
                .ThenBy(x => x.CreatedAtUtc)
                .Select(x => (Guid?)x.Id)
                .FirstOrDefaultAsync(cancellationToken);
            if (!candidateId.HasValue) return null;

            var token = Guid.NewGuid().ToString("N");
            var affected = await db.EmailSchedules
                .Where(x => x.Id == candidateId.Value && x.IsEnabled && x.NextRunUtc <= now && (x.ClaimToken == null || x.ClaimedAtUtc == null || x.ClaimedAtUtc <= staleBefore))
                .ExecuteUpdateAsync(setters => setters
                    .SetProperty(x => x.ClaimToken, token)
                    .SetProperty(x => x.ClaimedAtUtc, now)
                    .SetProperty(x => x.LastStatus, "Processing")
                    .SetProperty(x => x.UpdatedAtUtc, now), cancellationToken);

            if (affected == 1) return (candidateId.Value, token);
        }

        return null;
    }

    private async Task ProcessClaimAsync(Guid scheduleId, string claimToken, CancellationToken cancellationToken)
    {
        using var scope = scopeFactory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<AppDbContext>();
        var renderer = scope.ServiceProvider.GetRequiredService<IEmailTemplateRenderer>();
        var outbox = scope.ServiceProvider.GetRequiredService<IEmailOutbox>();

        var schedule = await db.EmailSchedules.FirstOrDefaultAsync(x => x.Id == scheduleId && x.ClaimToken == claimToken && x.IsEnabled, cancellationToken);
        if (schedule is null) return;

        var occurrenceUtc = schedule.GetOccurrenceForAttempt();
        var now = DateTime.UtcNow;
        try
        {
            var prepared = schedule.Scope == EmailSchedule.SiteScope
                ? await PrepareSiteAsync(db, renderer, schedule, cancellationToken)
                : await PrepareAccountAsync(db, renderer, schedule, cancellationToken);

            var idempotencyKey = $"schedule:{schedule.Id:N}:{occurrenceUtc.Ticks}";
            var correlationId = $"email-schedule-{schedule.Id:N}-{occurrenceUtc:yyyyMMddHHmmss}";
            await outbox.EnqueueAsync(new EmailOutboxEnqueueRequest(
                schedule.OwnerUserId,
                schedule.SiteId,
                schedule.Id,
                schedule.TemplateKey,
                prepared.Message.Subject,
                prepared.Message.HtmlBody,
                prepared.Message.TextBody,
                prepared.Recipients,
                idempotencyKey,
                correlationId,
                Math.Max(1, schedule.RetryCount + 1)), cancellationToken);

            var nextRegular = EmailScheduleCalculator.CalculateNextRunUtc(
                schedule.TimezoneId, schedule.Frequency, schedule.TimeOfDay, schedule.Weekday, schedule.MonthDay, occurrenceUtc);
            schedule.RecordQueued(occurrenceUtc, nextRegular, now);
            await db.SaveChangesAsync(cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            var sanitized = Sanitize(ex.Message);
            var nextRegular = EmailScheduleCalculator.CalculateNextRunUtc(
                schedule.TimezoneId, schedule.Frequency, schedule.TimeOfDay, schedule.Weekday, schedule.MonthDay, occurrenceUtc);
            var retryAt = now.AddMinutes(schedule.RetryDelayMinutes);
            var retrying = schedule.RecordFailure(occurrenceUtc, sanitized, retryAt, nextRegular, now);
            await db.SaveChangesAsync(cancellationToken);
            logger.LogWarning("Email schedule {ScheduleId} failed. Retry={Retry}. Reason={Reason}", schedule.Id, retrying, sanitized);
        }
    }

    private static async Task<PreparedEmail> PrepareSiteAsync(
        AppDbContext db,
        IEmailTemplateRenderer renderer,
        EmailSchedule schedule,
        CancellationToken cancellationToken)
    {
        if (!schedule.SiteId.HasValue) throw new InvalidOperationException("Site schedule no longer references a site.");
        var site = await db.Sites.AsNoTracking().FirstOrDefaultAsync(x => x.Id == schedule.SiteId.Value && x.OwnerUserId == schedule.OwnerUserId, cancellationToken)
            ?? throw new InvalidOperationException("The scheduled site is no longer available to this account.");
        var recipients = await db.SiteEmailRecipients.AsNoTracking()
            .Where(x => x.SiteId == site.Id && x.OwnerUserId == schedule.OwnerUserId && x.IsEnabled)
            .OrderBy(x => x.CreatedAtUtc)
            .Select(x => x.EmailAddress)
            .ToListAsync(cancellationToken);
        if (recipients.Count == 0) throw new InvalidOperationException("The site schedule has no enabled email recipients.");

        var message = renderer.Render(new EmailTemplateRenderRequest(
            EmailTemplateKeys.SiteOperationalReport,
            schedule.Culture,
            new Dictionary<string, string?>
            {
                ["SiteName"] = site.Name,
                ["Status"] = site.ConnectionStatus.ToString(),
                ["GeneratedAt"] = DateTime.UtcNow.ToString("u"),
                ["Summary"] = schedule.Culture == "ar" ? "ملخص تشغيلي مجدول للموقع." : "Scheduled operational site summary.",
                ["SiteUrl"] = site.SiteUrl
            }));
        return new PreparedEmail(message, recipients);
    }

    private static async Task<PreparedEmail> PrepareAccountAsync(
        AppDbContext db,
        IEmailTemplateRenderer renderer,
        EmailSchedule schedule,
        CancellationToken cancellationToken)
    {
        var user = await db.AuthUsers.AsNoTracking().FirstOrDefaultAsync(x => x.Id == schedule.OwnerUserId, cancellationToken)
            ?? throw new InvalidOperationException("The scheduled account no longer exists.");
        var recipients = await db.AccountEmailRecipients.AsNoTracking()
            .Where(x => x.OwnerUserId == schedule.OwnerUserId && x.IsEnabled)
            .OrderBy(x => x.CreatedAtUtc)
            .Select(x => x.EmailAddress)
            .ToListAsync(cancellationToken);
        if (recipients.Count == 0) throw new InvalidOperationException("The dashboard schedule has no enabled email recipients.");

        var sites = await db.Sites.AsNoTracking().Where(x => x.OwnerUserId == schedule.OwnerUserId).Select(x => x.ConnectionStatus.ToString()).ToListAsync(cancellationToken);
        var healthy = sites.Count(x => x.Equals("Connected", StringComparison.OrdinalIgnoreCase) || x.Equals("Healthy", StringComparison.OrdinalIgnoreCase));
        var failed = sites.Count(x => x.Contains("Fail", StringComparison.OrdinalIgnoreCase) || x.Contains("Error", StringComparison.OrdinalIgnoreCase));
        var warnings = Math.Max(0, sites.Count - healthy - failed);

        var message = renderer.Render(new EmailTemplateRenderRequest(
            EmailTemplateKeys.DashboardDigest,
            schedule.Culture,
            new Dictionary<string, string?>
            {
                ["AccountName"] = user.UserName,
                ["SiteCount"] = sites.Count.ToString(),
                ["HealthySites"] = healthy.ToString(),
                ["WarningSites"] = warnings.ToString(),
                ["FailedSites"] = failed.ToString(),
                ["GeneratedAt"] = DateTime.UtcNow.ToString("u"),
                ["Summary"] = schedule.Culture == "ar" ? "ملخص مجدول لحالة جميع المواقع." : "Scheduled digest of all owned sites."
            }));
        return new PreparedEmail(message, recipients);
    }

    private static string Sanitize(string? value)
    {
        var clean = string.IsNullOrWhiteSpace(value) ? "Email schedule failed." : value.Replace('\r', ' ').Replace('\n', ' ').Trim();
        return clean.Length <= 1000 ? clean : clean[..1000];
    }

    private sealed record PreparedEmail(EmailTemplateRenderResult Message, IReadOnlyList<string> Recipients);
}
