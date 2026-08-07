using System.Net.Mail;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence;

public sealed class EmailOutboxService(AppDbContext dbContext) : IEmailOutbox
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    public async Task<EmailOutboxItem> EnqueueAsync(
        EmailOutboxEnqueueRequest request,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        if (request.OwnerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(request));
        ArgumentException.ThrowIfNullOrWhiteSpace(request.IdempotencyKey);
        ArgumentException.ThrowIfNullOrWhiteSpace(request.CorrelationId);

        var idempotencyKey = NormalizeBounded(request.IdempotencyKey, 200, "Idempotency key");
        var correlationId = NormalizeBounded(request.CorrelationId, 100, "Correlation ID");
        var existing = await dbContext.EmailOutboxMessages.AsNoTracking()
            .FirstOrDefaultAsync(x => x.OwnerUserId == request.OwnerUserId && x.IdempotencyKey == idempotencyKey, cancellationToken);
        if (existing is not null) return ToItem(existing);

        var recipients = NormalizeRecipients(request.Recipients);
        if (recipients.Count == 0) throw new InvalidOperationException("At least one email recipient is required.");

        if (request.SiteId.HasValue)
        {
            var owned = await dbContext.Sites.AsNoTracking()
                .AnyAsync(x => x.Id == request.SiteId.Value && x.OwnerUserId == request.OwnerUserId, cancellationToken);
            if (!owned) throw new UnauthorizedAccessException("The outbox site does not belong to the specified owner.");
        }

        var entity = new EmailOutboxMessage(
            request.OwnerUserId,
            request.SiteId,
            request.ScheduleId,
            NormalizeBounded(request.TemplateKey, 160, "Template key"),
            NormalizeBounded(request.Subject, 500, "Subject"),
            request.HtmlBody ?? string.Empty,
            request.TextBody ?? string.Empty,
            JsonSerializer.Serialize(recipients, JsonOptions),
            idempotencyKey,
            correlationId,
            request.MaxAttempts,
            DateTime.UtcNow);

        dbContext.EmailOutboxMessages.Add(entity);
        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
            return ToItem(entity);
        }
        catch (DbUpdateException)
        {
            dbContext.Entry(entity).State = EntityState.Detached;
            existing = await dbContext.EmailOutboxMessages.AsNoTracking()
                .FirstOrDefaultAsync(x => x.OwnerUserId == request.OwnerUserId && x.IdempotencyKey == idempotencyKey, cancellationToken);
            if (existing is not null) return ToItem(existing);
            throw;
        }
    }

    public async Task<EmailOutboxClaim?> ClaimDueAsync(
        DateTime utcNow,
        CancellationToken cancellationToken = default)
    {
        for (var attempt = 0; attempt < 5; attempt++)
        {
            var candidateId = await dbContext.EmailOutboxMessages.AsNoTracking()
                .Where(x =>
                    (x.Status == EmailOutboxMessage.QueuedStatus || x.Status == EmailOutboxMessage.RetryWaitingStatus) &&
                    x.AttemptCount < x.MaxAttempts &&
                    x.NextAttemptAtUtc <= utcNow)
                .OrderBy(x => x.NextAttemptAtUtc)
                .ThenBy(x => x.CreatedAtUtc)
                .Select(x => (Guid?)x.Id)
                .FirstOrDefaultAsync(cancellationToken);

            if (!candidateId.HasValue) return null;

            var claimToken = Guid.NewGuid().ToString("N");
            var affected = await dbContext.EmailOutboxMessages
                .Where(x =>
                    x.Id == candidateId.Value &&
                    (x.Status == EmailOutboxMessage.QueuedStatus || x.Status == EmailOutboxMessage.RetryWaitingStatus) &&
                    x.AttemptCount < x.MaxAttempts &&
                    x.NextAttemptAtUtc <= utcNow)
                .ExecuteUpdateAsync(setters => setters
                    .SetProperty(x => x.Status, EmailOutboxMessage.SendingStatus)
                    .SetProperty(x => x.ClaimToken, claimToken)
                    .SetProperty(x => x.ClaimedAtUtc, utcNow)
                    .SetProperty(x => x.AttemptCount, x => x.AttemptCount + 1)
                    .SetProperty(x => x.LastError, (string?)null)
                    .SetProperty(x => x.UpdatedAtUtc, utcNow), cancellationToken);

            if (affected != 1) continue;

            var claimed = await dbContext.EmailOutboxMessages.AsNoTracking()
                .SingleAsync(x => x.Id == candidateId.Value && x.ClaimToken == claimToken, cancellationToken);
            return ToClaim(claimed, DeserializeRecipients(claimed.RecipientsJson));
        }

        return null;
    }

    public async Task MarkSentAsync(
        Guid messageId,
        string claimToken,
        string? providerSummary,
        DateTime utcNow,
        CancellationToken cancellationToken = default)
    {
        var message = await RequireClaimedAsync(messageId, claimToken, cancellationToken);
        var startedAt = message.ClaimedAtUtc ?? utcNow;
        message.MarkSent(utcNow);
        dbContext.EmailDeliveryAttempts.Add(new EmailDeliveryAttempt(
            message.Id,
            message.AttemptCount,
            EmailOutboxMessage.SentStatus,
            startedAt,
            utcNow,
            Sanitize(providerSummary, 500),
            null,
            null,
            utcNow));
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task MarkFailedAsync(
        Guid messageId,
        string claimToken,
        string errorCategory,
        string sanitizedError,
        DateTime utcNow,
        CancellationToken cancellationToken = default)
    {
        var message = await RequireClaimedAsync(messageId, claimToken, cancellationToken);
        var startedAt = message.ClaimedAtUtc ?? utcNow;
        var category = Sanitize(errorCategory, 100) ?? "Delivery";
        var error = Sanitize(sanitizedError, 1000) ?? "Email delivery failed.";
        var delay = RetryDelay(message.AttemptCount);
        message.MarkDeliveryFailure(error, utcNow.Add(delay), utcNow);
        dbContext.EmailDeliveryAttempts.Add(new EmailDeliveryAttempt(
            message.Id,
            message.AttemptCount,
            message.Status,
            startedAt,
            utcNow,
            null,
            category,
            error,
            utcNow));
        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public async Task<int> RecoverStaleClaimsAsync(
        DateTime staleBeforeUtc,
        DateTime utcNow,
        CancellationToken cancellationToken = default)
    {
        var stale = await dbContext.EmailOutboxMessages
            .Where(x => x.Status == EmailOutboxMessage.SendingStatus && x.ClaimedAtUtc != null && x.ClaimedAtUtc <= staleBeforeUtc)
            .ToListAsync(cancellationToken);

        foreach (var message in stale)
        {
            var startedAt = message.ClaimedAtUtc ?? utcNow;
            message.RecoverStaleClaim(utcNow.Add(RetryDelay(message.AttemptCount)), utcNow);
            dbContext.EmailDeliveryAttempts.Add(new EmailDeliveryAttempt(
                message.Id,
                Math.Max(1, message.AttemptCount),
                message.Status,
                startedAt,
                utcNow,
                null,
                "WorkerRestart",
                message.LastError,
                utcNow));
        }

        if (stale.Count > 0) await dbContext.SaveChangesAsync(cancellationToken);
        return stale.Count;
    }

    private async Task<EmailOutboxMessage> RequireClaimedAsync(
        Guid messageId,
        string claimToken,
        CancellationToken cancellationToken)
    {
        if (messageId == Guid.Empty) throw new ArgumentException("Message ID is required.", nameof(messageId));
        ArgumentException.ThrowIfNullOrWhiteSpace(claimToken);
        return await dbContext.EmailOutboxMessages.FirstOrDefaultAsync(
                   x => x.Id == messageId && x.Status == EmailOutboxMessage.SendingStatus && x.ClaimToken == claimToken,
                   cancellationToken)
               ?? throw new InvalidOperationException("Email outbox claim is no longer valid.");
    }

    private static List<string> NormalizeRecipients(IReadOnlyList<string>? recipients)
    {
        if (recipients is null) return [];
        var output = new List<string>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var value in recipients)
        {
            if (string.IsNullOrWhiteSpace(value)) continue;
            var clean = value.Trim();
            MailAddress parsed;
            try { parsed = new MailAddress(clean); }
            catch (FormatException) { throw new InvalidOperationException($"Invalid email recipient: {clean}"); }
            if (!string.Equals(parsed.Address, clean, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException($"Invalid email recipient: {clean}");
            if (seen.Add(parsed.Address)) output.Add(parsed.Address);
        }
        return output;
    }

    private static IReadOnlyList<string> DeserializeRecipients(string json)
    {
        try { return JsonSerializer.Deserialize<List<string>>(json, JsonOptions) ?? []; }
        catch (JsonException) { throw new InvalidOperationException("Stored email recipient snapshot is invalid."); }
    }

    private static TimeSpan RetryDelay(int attemptNumber)
    {
        var exponent = Math.Clamp(attemptNumber - 1, 0, 6);
        return TimeSpan.FromMinutes(Math.Min(60, Math.Pow(2, exponent)));
    }

    private static string NormalizeBounded(string? value, int maxLength, string label)
    {
        if (string.IsNullOrWhiteSpace(value)) throw new InvalidOperationException($"{label} is required.");
        var clean = value.Trim();
        if (clean.Length > maxLength) throw new InvalidOperationException($"{label} exceeds {maxLength} characters.");
        return clean;
    }

    private static string? Sanitize(string? value, int maxLength)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        var clean = value.Replace('\r', ' ').Replace('\n', ' ').Trim();
        return clean.Length <= maxLength ? clean : clean[..maxLength];
    }

    private static EmailOutboxItem ToItem(EmailOutboxMessage message) => new(
        message.Id,
        message.OwnerUserId,
        message.SiteId,
        message.Scope,
        message.Status,
        message.AttemptCount,
        message.MaxAttempts,
        message.NextAttemptAtUtc,
        message.IdempotencyKey,
        message.CorrelationId);

    private static EmailOutboxClaim ToClaim(EmailOutboxMessage message, IReadOnlyList<string> recipients) => new(
        message.Id,
        message.OwnerUserId,
        message.SiteId,
        message.Scope,
        message.TemplateKey,
        message.Subject,
        message.HtmlBody,
        message.TextBody,
        recipients,
        message.ClaimToken ?? throw new InvalidOperationException("Claim token is missing."),
        message.AttemptCount,
        message.MaxAttempts,
        message.CorrelationId);
}
