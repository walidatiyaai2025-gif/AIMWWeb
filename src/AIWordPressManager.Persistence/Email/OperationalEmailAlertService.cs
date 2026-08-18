using System.Globalization;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.Abstractions.Email;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Email;

public sealed class OperationalEmailAlertService(
    AppDbContext dbContext,
    IEmailTemplateRenderer templateRenderer,
    IEmailOutbox emailOutbox)
{
    private const int FailureReasonMaxLength = 1000;
    private const int EventLabelMaxLength = 160;

    private static readonly Regex SecretAssignmentRegex = new(
        @"(?i)\b(password|passwd|pwd|token|api[-_]?key|secret|authorization)\s*[:=]\s*([^\s,;&]+)",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    private static readonly Regex BearerRegex = new(
        @"(?i)\bbearer\s+[A-Za-z0-9\-._~+/]+=*",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    private static readonly Regex UriUserInfoRegex = new(
        @"(?i)(https?://)[^/@\s]+@",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    private static readonly Regex WhitespaceRegex = new(
        @"\s+",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    public async Task<OperationalEmailAlertResult> EnqueueSiteSyncFailureAsync(
        Guid ownerUserId,
        Guid siteId,
        Guid syncRunId,
        string? failureReason,
        DateTime occurredAtUtc,
        string culture = "en",
        CancellationToken cancellationToken = default)
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
        if (siteId == Guid.Empty) throw new ArgumentException("Site ID is required.", nameof(siteId));
        if (syncRunId == Guid.Empty) throw new ArgumentException("Sync run ID is required.", nameof(syncRunId));

        var context = await LoadSiteDeliveryContextAsync(ownerUserId, siteId, cancellationToken);
        var idempotencyKey = $"alert:site-sync-failure:{siteId:N}:{syncRunId:N}";
        var existing = await FindExistingMessageAsync(ownerUserId, idempotencyKey, cancellationToken);
        if (existing.HasValue)
            return OperationalEmailAlertResult.EnqueuedMessage(existing.Value, alreadyQueued: true);

        if (context.Recipients.Count == 0)
            return OperationalEmailAlertResult.Skipped("No enabled site notification recipients are configured.");

        var correlationId = $"sync:{syncRunId:N}";
        var rendered = templateRenderer.Render(new EmailTemplateRenderRequest(
            EmailTemplateKeys.SiteSyncFailure,
            culture,
            new Dictionary<string, string?>(StringComparer.OrdinalIgnoreCase)
            {
                ["SiteName"] = context.SiteName,
                ["FailureReason"] = SanitizeSensitiveFailure(failureReason, "WordPress synchronization failed."),
                ["OccurredAt"] = FormatUtc(occurredAtUtc),
                ["CorrelationId"] = correlationId,
                ["SiteUrl"] = context.SiteUrl
            }));

        return await EnqueueAsync(
            ownerUserId,
            siteId,
            rendered,
            context.Recipients,
            idempotencyKey,
            correlationId,
            cancellationToken);
    }

    public async Task<OperationalEmailAlertResult> EnqueueSiteJobFailureAsync(
        Guid ownerUserId,
        Guid siteId,
        Guid executionJobId,
        string? jobType,
        string? failureReason,
        DateTime occurredAtUtc,
        string culture = "en",
        CancellationToken cancellationToken = default)
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
        if (siteId == Guid.Empty) throw new ArgumentException("Site ID is required.", nameof(siteId));
        if (executionJobId == Guid.Empty) throw new ArgumentException("Execution job ID is required.", nameof(executionJobId));

        var context = await LoadSiteDeliveryContextAsync(ownerUserId, siteId, cancellationToken);
        var idempotencyKey = $"alert:site-job-failure:{siteId:N}:{executionJobId:N}";
        var existing = await FindExistingMessageAsync(ownerUserId, idempotencyKey, cancellationToken);
        if (existing.HasValue)
            return OperationalEmailAlertResult.EnqueuedMessage(existing.Value, alreadyQueued: true);

        if (context.Recipients.Count == 0)
            return OperationalEmailAlertResult.Skipped("No enabled site notification recipients are configured.");

        var correlationId = $"job:{executionJobId:N}";
        var rendered = templateRenderer.Render(new EmailTemplateRenderRequest(
            EmailTemplateKeys.SiteJobFailure,
            culture,
            new Dictionary<string, string?>(StringComparer.OrdinalIgnoreCase)
            {
                ["SiteName"] = context.SiteName,
                ["JobType"] = SanitizeEventLabel(jobType, "Background job"),
                ["FailureReason"] = SanitizeSensitiveFailure(failureReason, "Background job failed."),
                ["OccurredAt"] = FormatUtc(occurredAtUtc),
                ["CorrelationId"] = correlationId,
                ["SiteUrl"] = context.SiteUrl
            }));

        return await EnqueueAsync(
            ownerUserId,
            siteId,
            rendered,
            context.Recipients,
            idempotencyKey,
            correlationId,
            cancellationToken);
    }

    public static string SanitizeFailureReason(string? value) =>
        SanitizeSensitiveFailure(value, "WordPress synchronization failed.");

    private async Task<SiteDeliveryContext> LoadSiteDeliveryContextAsync(
        Guid ownerUserId,
        Guid siteId,
        CancellationToken cancellationToken)
    {
        var site = await dbContext.Sites.AsNoTracking()
            .Where(x => x.Id == siteId && x.OwnerUserId == ownerUserId)
            .Select(x => new { x.Id, x.Name, x.SiteUrl })
            .FirstOrDefaultAsync(cancellationToken)
            ?? throw new UnauthorizedAccessException("The alert site does not belong to the specified owner.");

        var recipients = await dbContext.SiteEmailRecipients.AsNoTracking()
            .Where(x => x.SiteId == siteId && x.OwnerUserId == ownerUserId && x.IsEnabled)
            .OrderBy(x => x.CreatedAtUtc)
            .Select(x => x.EmailAddress)
            .ToListAsync(cancellationToken);

        return new SiteDeliveryContext(site.Name, site.SiteUrl, recipients);
    }

    private async Task<Guid?> FindExistingMessageAsync(
        Guid ownerUserId,
        string idempotencyKey,
        CancellationToken cancellationToken) =>
        await dbContext.EmailOutboxMessages.AsNoTracking()
            .Where(x => x.OwnerUserId == ownerUserId && x.IdempotencyKey == idempotencyKey)
            .Select(x => (Guid?)x.Id)
            .FirstOrDefaultAsync(cancellationToken);

    private async Task<OperationalEmailAlertResult> EnqueueAsync(
        Guid ownerUserId,
        Guid siteId,
        EmailTemplateRenderResult rendered,
        IReadOnlyList<string> recipients,
        string idempotencyKey,
        string correlationId,
        CancellationToken cancellationToken)
    {
        var message = await emailOutbox.EnqueueAsync(new EmailOutboxEnqueueRequest(
            OwnerUserId: ownerUserId,
            SiteId: siteId,
            ScheduleId: null,
            TemplateKey: rendered.TemplateKey,
            Subject: rendered.Subject,
            HtmlBody: rendered.HtmlBody,
            TextBody: rendered.TextBody,
            Recipients: recipients,
            IdempotencyKey: idempotencyKey,
            CorrelationId: correlationId,
            MaxAttempts: 5), cancellationToken);

        return OperationalEmailAlertResult.EnqueuedMessage(message.Id, alreadyQueued: false);
    }

    private static string SanitizeSensitiveFailure(string? value, string fallback)
    {
        var clean = string.IsNullOrWhiteSpace(value) ? fallback : value.Trim();
        clean = UriUserInfoRegex.Replace(clean, "$1[redacted]@");
        clean = BearerRegex.Replace(clean, "Bearer [redacted]");
        clean = SecretAssignmentRegex.Replace(clean, "$1=[redacted]");
        clean = WhitespaceRegex.Replace(clean, " ").Trim();
        if (clean.Length == 0) clean = fallback;
        return clean.Length <= FailureReasonMaxLength ? clean : clean[..FailureReasonMaxLength];
    }

    private static string SanitizeEventLabel(string? value, string fallback)
    {
        var clean = string.IsNullOrWhiteSpace(value) ? fallback : WhitespaceRegex.Replace(value.Trim(), " ");
        if (clean.Length == 0) clean = fallback;
        return clean.Length <= EventLabelMaxLength ? clean : clean[..EventLabelMaxLength];
    }

    private static string FormatUtc(DateTime value) =>
        value.ToUniversalTime().ToString("yyyy-MM-dd HH:mm:ss 'UTC'", CultureInfo.InvariantCulture);

    private sealed record SiteDeliveryContext(
        string SiteName,
        string SiteUrl,
        IReadOnlyList<string> Recipients);
}

public sealed record OperationalEmailAlertResult(
    bool Enqueued,
    Guid? OutboxMessageId,
    bool AlreadyQueued,
    string? SkipReason)
{
    public static OperationalEmailAlertResult EnqueuedMessage(Guid messageId, bool alreadyQueued) =>
        new(true, messageId, alreadyQueued, null);

    public static OperationalEmailAlertResult Skipped(string reason) =>
        new(false, null, false, reason);
}
