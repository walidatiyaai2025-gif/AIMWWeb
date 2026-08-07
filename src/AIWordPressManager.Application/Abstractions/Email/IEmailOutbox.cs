namespace AIWordPressManager.Application.Abstractions.Email;

public interface IEmailOutbox
{
    Task<EmailOutboxItem> EnqueueAsync(EmailOutboxEnqueueRequest request, CancellationToken cancellationToken = default);
    Task<EmailOutboxClaim?> ClaimDueAsync(DateTime utcNow, CancellationToken cancellationToken = default);
    Task MarkSentAsync(Guid messageId, string claimToken, string? providerSummary, DateTime utcNow, CancellationToken cancellationToken = default);
    Task MarkFailedAsync(Guid messageId, string claimToken, string errorCategory, string sanitizedError, DateTime utcNow, CancellationToken cancellationToken = default);
    Task<int> RecoverStaleClaimsAsync(DateTime staleBeforeUtc, DateTime utcNow, CancellationToken cancellationToken = default);
}

public sealed record EmailOutboxEnqueueRequest(
    Guid OwnerUserId,
    Guid? SiteId,
    Guid? ScheduleId,
    string TemplateKey,
    string Subject,
    string HtmlBody,
    string TextBody,
    IReadOnlyList<string> Recipients,
    string IdempotencyKey,
    string CorrelationId,
    int MaxAttempts = 5);

public sealed record EmailOutboxItem(
    Guid Id,
    Guid OwnerUserId,
    Guid? SiteId,
    string Status,
    int AttemptCount,
    int MaxAttempts,
    DateTime NextAttemptAtUtc,
    string IdempotencyKey,
    string CorrelationId);

public sealed record EmailOutboxClaim(
    Guid Id,
    Guid OwnerUserId,
    Guid? SiteId,
    string TemplateKey,
    string Subject,
    string HtmlBody,
    string TextBody,
    IReadOnlyList<string> Recipients,
    string ClaimToken,
    int AttemptNumber,
    int MaxAttempts,
    string CorrelationId);
