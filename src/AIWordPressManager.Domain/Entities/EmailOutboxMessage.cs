using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class EmailOutboxMessage : Entity
{
    public const string QueuedStatus = "Queued";
    public const string SendingStatus = "Sending";
    public const string SentStatus = "Sent";
    public const string RetryWaitingStatus = "RetryWaiting";
    public const string FailedStatus = "Failed";
    public const string CancelledStatus = "Cancelled";

    private EmailOutboxMessage() { }

    public EmailOutboxMessage(
        Guid ownerUserId,
        Guid? siteId,
        Guid? scheduleId,
        string templateKey,
        string subject,
        string htmlBody,
        string textBody,
        string recipientsJson,
        string idempotencyKey,
        string correlationId,
        int maxAttempts,
        DateTime utcNow)
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
        ArgumentException.ThrowIfNullOrWhiteSpace(templateKey);
        ArgumentException.ThrowIfNullOrWhiteSpace(subject);
        ArgumentException.ThrowIfNullOrWhiteSpace(recipientsJson);
        ArgumentException.ThrowIfNullOrWhiteSpace(idempotencyKey);
        ArgumentException.ThrowIfNullOrWhiteSpace(correlationId);
        if (maxAttempts is < 1 or > 20) throw new ArgumentOutOfRangeException(nameof(maxAttempts));

        OwnerUserId = ownerUserId;
        SiteId = siteId;
        ScheduleId = scheduleId;
        TemplateKey = templateKey.Trim();
        Subject = subject.Trim();
        HtmlBody = htmlBody ?? string.Empty;
        TextBody = textBody ?? string.Empty;
        RecipientsJson = recipientsJson.Trim();
        IdempotencyKey = idempotencyKey.Trim();
        CorrelationId = correlationId.Trim();
        MaxAttempts = maxAttempts;
        Status = QueuedStatus;
        NextAttemptAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public Guid OwnerUserId { get; private set; }
    public Guid? SiteId { get; private set; }
    public Guid? ScheduleId { get; private set; }
    public string TemplateKey { get; private set; } = string.Empty;
    public string Subject { get; private set; } = string.Empty;
    public string HtmlBody { get; private set; } = string.Empty;
    public string TextBody { get; private set; } = string.Empty;
    public string RecipientsJson { get; private set; } = string.Empty;
    public string IdempotencyKey { get; private set; } = string.Empty;
    public string CorrelationId { get; private set; } = string.Empty;
    public string Status { get; private set; } = QueuedStatus;
    public int AttemptCount { get; private set; }
    public int MaxAttempts { get; private set; }
    public DateTime NextAttemptAtUtc { get; private set; }
    public DateTime? ClaimedAtUtc { get; private set; }
    public string? ClaimToken { get; private set; }
    public DateTime? SentAtUtc { get; private set; }
    public string? LastError { get; private set; }

    public bool CanBeClaimed(DateTime utcNow) =>
        (Status == QueuedStatus || Status == RetryWaitingStatus) &&
        AttemptCount < MaxAttempts &&
        NextAttemptAtUtc <= utcNow;

    public void Claim(string claimToken, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(claimToken);
        if (!CanBeClaimed(utcNow)) throw new InvalidOperationException("Email outbox message is not available for claim.");
        Status = SendingStatus;
        ClaimToken = claimToken.Trim();
        ClaimedAtUtc = utcNow;
        AttemptCount++;
        LastError = null;
        MarkUpdated(utcNow);
    }

    public void MarkSent(DateTime utcNow)
    {
        if (Status != SendingStatus) throw new InvalidOperationException("Only a sending email can be marked sent.");
        Status = SentStatus;
        SentAtUtc = utcNow;
        NextAttemptAtUtc = utcNow;
        ClaimToken = null;
        ClaimedAtUtc = null;
        LastError = null;
        MarkUpdated(utcNow);
    }

    public void MarkDeliveryFailure(string sanitizedError, DateTime nextAttemptAtUtc, DateTime utcNow)
    {
        if (Status != SendingStatus) throw new InvalidOperationException("Only a sending email can record a delivery failure.");
        LastError = NormalizeError(sanitizedError);
        ClaimToken = null;
        ClaimedAtUtc = null;
        if (AttemptCount >= MaxAttempts)
        {
            Status = FailedStatus;
            NextAttemptAtUtc = utcNow;
        }
        else
        {
            Status = RetryWaitingStatus;
            NextAttemptAtUtc = nextAttemptAtUtc < utcNow ? utcNow : nextAttemptAtUtc;
        }
        MarkUpdated(utcNow);
    }

    public void RecoverStaleClaim(DateTime nextAttemptAtUtc, DateTime utcNow)
    {
        if (Status != SendingStatus) return;
        Status = AttemptCount >= MaxAttempts ? FailedStatus : RetryWaitingStatus;
        NextAttemptAtUtc = AttemptCount >= MaxAttempts ? utcNow : (nextAttemptAtUtc < utcNow ? utcNow : nextAttemptAtUtc);
        ClaimToken = null;
        ClaimedAtUtc = null;
        LastError = "Previous delivery attempt did not complete before the worker stopped.";
        MarkUpdated(utcNow);
    }

    public void Cancel(DateTime utcNow)
    {
        if (Status == SentStatus) throw new InvalidOperationException("A sent email cannot be cancelled.");
        Status = CancelledStatus;
        ClaimToken = null;
        ClaimedAtUtc = null;
        NextAttemptAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    private static string NormalizeError(string value)
    {
        var clean = string.IsNullOrWhiteSpace(value) ? "Email delivery failed." : value.Trim();
        return clean.Length <= 1000 ? clean : clean[..1000];
    }
}
