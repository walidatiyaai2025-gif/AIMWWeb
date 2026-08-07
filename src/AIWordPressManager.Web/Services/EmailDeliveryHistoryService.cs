using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class EmailDeliveryHistoryService(AppDbContext dbContext, CurrentUserContext currentUser)
{
    public async Task<IReadOnlyList<EmailDeliveryHistoryItem>> GetAsync(
        Guid? siteId = null,
        string? status = null,
        string? correlationId = null,
        int take = 100,
        CancellationToken cancellationToken = default)
    {
        var ownerId = currentUser.UserId;
        take = Math.Clamp(take, 1, 500);

        if (siteId.HasValue)
        {
            var owned = await dbContext.Sites.AsNoTracking()
                .AnyAsync(x => x.Id == siteId.Value && x.OwnerUserId == ownerId, cancellationToken);
            if (!owned) throw new UnauthorizedAccessException("The requested WordPress site does not belong to the signed-in user.");
        }

        var query = dbContext.EmailOutboxMessages.AsNoTracking()
            .Where(x => x.OwnerUserId == ownerId);

        if (siteId.HasValue) query = query.Where(x => x.SiteId == siteId.Value);
        if (!string.IsNullOrWhiteSpace(status))
        {
            var normalizedStatus = status.Trim();
            query = query.Where(x => x.Status == normalizedStatus);
        }
        if (!string.IsNullOrWhiteSpace(correlationId))
        {
            var normalizedCorrelation = correlationId.Trim();
            query = query.Where(x => x.CorrelationId == normalizedCorrelation);
        }

        return await query
            .OrderByDescending(x => x.CreatedAtUtc)
            .Take(take)
            .Select(x => new EmailDeliveryHistoryItem(
                x.Id,
                x.SiteId,
                x.Scope,
                x.TemplateKey,
                x.Subject,
                x.Status,
                x.AttemptCount,
                x.MaxAttempts,
                x.NextAttemptAtUtc,
                x.SentAtUtc,
                x.LastError,
                x.CorrelationId,
                x.IdempotencyKey,
                x.CreatedAtUtc,
                x.UpdatedAtUtc))
            .ToListAsync(cancellationToken);
    }

    public async Task<EmailDeliveryHistoryDetails?> GetDetailsAsync(Guid messageId, CancellationToken cancellationToken = default)
    {
        var ownerId = currentUser.UserId;
        var message = await dbContext.EmailOutboxMessages.AsNoTracking()
            .Where(x => x.Id == messageId && x.OwnerUserId == ownerId)
            .Select(x => new EmailDeliveryHistoryItem(
                x.Id,
                x.SiteId,
                x.Scope,
                x.TemplateKey,
                x.Subject,
                x.Status,
                x.AttemptCount,
                x.MaxAttempts,
                x.NextAttemptAtUtc,
                x.SentAtUtc,
                x.LastError,
                x.CorrelationId,
                x.IdempotencyKey,
                x.CreatedAtUtc,
                x.UpdatedAtUtc))
            .FirstOrDefaultAsync(cancellationToken);
        if (message is null) return null;

        var attempts = await dbContext.EmailDeliveryAttempts.AsNoTracking()
            .Where(x => x.OutboxMessageId == messageId)
            .OrderByDescending(x => x.AttemptNumber)
            .Select(x => new EmailDeliveryAttemptView(
                x.AttemptNumber,
                x.Status,
                x.StartedAtUtc,
                x.FinishedAtUtc,
                x.ProviderSummary,
                x.ErrorCategory,
                x.SanitizedError))
            .ToListAsync(cancellationToken);

        return new EmailDeliveryHistoryDetails(message, attempts);
    }
}

public sealed record EmailDeliveryHistoryItem(
    Guid Id,
    Guid? SiteId,
    string Scope,
    string TemplateKey,
    string Subject,
    string Status,
    int AttemptCount,
    int MaxAttempts,
    DateTime NextAttemptAtUtc,
    DateTime? SentAtUtc,
    string? LastError,
    string CorrelationId,
    string IdempotencyKey,
    DateTime CreatedAtUtc,
    DateTime UpdatedAtUtc);

public sealed record EmailDeliveryAttemptView(
    int AttemptNumber,
    string Status,
    DateTime StartedAtUtc,
    DateTime? FinishedAtUtc,
    string? ProviderSummary,
    string? ErrorCategory,
    string? SanitizedError);

public sealed record EmailDeliveryHistoryDetails(
    EmailDeliveryHistoryItem Message,
    IReadOnlyList<EmailDeliveryAttemptView> Attempts);
