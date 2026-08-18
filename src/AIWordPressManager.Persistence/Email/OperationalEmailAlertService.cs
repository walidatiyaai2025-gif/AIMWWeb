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

        var site = await dbContext.Sites.AsNoTracking()
            .Where(x => x.Id == siteId && x.OwnerUserId == ownerUserId)
            .Select(x => new { x.Id, x.Name, x.SiteUrl })
            .FirstOrDefaultAsync(cancellationToken)
            ?? throw new UnauthorizedAccessException("The alert site does not belong to the specified owner.");

        var idempotencyKey = $"alert:site-sync-failure:{siteId:N}:{syncRunId:N}";
        var existingMessageId = await dbContext.EmailOutboxMessages.AsNoTracking()
            .Where(x => x.OwnerUserId == ownerUserId && x.IdempotencyKey == idempotencyKey)
            .Select(x => (Guid?)x.Id)
            .FirstOrDefaultAsync(cancellationToken);
        if (existingMessageId.HasValue)
            return OperationalEmailAlertResult.EnqueuedMessage(existingMessageId.Value, alreadyQueued: true);

        var recipients = await dbContext.SiteEmailRecipients.AsNoTracking()
            .Where(x => x.SiteId == siteId && x.OwnerUserId == ownerUserId && x.IsEnabled)
            .OrderBy(x => x.CreatedAtUtc)
            .Select(x => x.EmailAddress)
            .ToListAsync(cancellationToken);

        if (recipients.Count == 0)
            return OperationalEmailAlertResult.Skipped("No enabled site notification recipients are configured.");

        var correlationId = $"sync:{syncRunId:N}";
        var rendered = templateRenderer.Render(new EmailTemplateRenderRequest(
            EmailTemplateKeys.SiteSyncFailure,
            culture,
            new Dictionary<string, string?>(StringComparer.OrdinalIgnoreCase)
            {
                ["SiteName"] = site.Name,
                ["FailureReason"] = SanitizeFailureReason(failureReason),
                ["OccurredAt"] = occurredAtUtc.ToUniversalTime().ToString("yyyy-MM-dd HH:mm:ss 'UTC'", CultureInfo.InvariantCulture),
                ["CorrelationId"] = correlationId,
                ["SiteUrl"] = site.SiteUrl
            }));

        var message = await emailOutbox.EnqueueAsync(new EmailOutboxEnqueueRequest(
            OwnerUserId: ownerUserId,
            SiteId: site.Id,
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

    public static string SanitizeFailureReason(string? value)
    {
        var clean = string.IsNullOrWhiteSpace(value) ? "WordPress synchronization failed." : value.Trim();
        clean = UriUserInfoRegex.Replace(clean, "$1[redacted]@");
        clean = BearerRegex.Replace(clean, "Bearer [redacted]");
        clean = SecretAssignmentRegex.Replace(clean, "$1=[redacted]");
        clean = WhitespaceRegex.Replace(clean, " ").Trim();
        if (clean.Length == 0) clean = "WordPress synchronization failed.";
        return clean.Length <= FailureReasonMaxLength ? clean : clean[..FailureReasonMaxLength];
    }
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
