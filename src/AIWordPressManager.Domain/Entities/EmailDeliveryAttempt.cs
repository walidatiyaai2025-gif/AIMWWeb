using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class EmailDeliveryAttempt : Entity
{
    private EmailDeliveryAttempt() { }

    public EmailDeliveryAttempt(
        Guid outboxMessageId,
        int attemptNumber,
        string status,
        DateTime startedAtUtc,
        DateTime? finishedAtUtc,
        string? providerSummary,
        string? errorCategory,
        string? sanitizedError,
        DateTime utcNow)
    {
        if (outboxMessageId == Guid.Empty) throw new ArgumentException("Outbox message ID is required.", nameof(outboxMessageId));
        if (attemptNumber < 1) throw new ArgumentOutOfRangeException(nameof(attemptNumber));
        ArgumentException.ThrowIfNullOrWhiteSpace(status);

        OutboxMessageId = outboxMessageId;
        AttemptNumber = attemptNumber;
        Status = status.Trim();
        StartedAtUtc = startedAtUtc;
        FinishedAtUtc = finishedAtUtc;
        ProviderSummary = Trim(providerSummary, 500);
        ErrorCategory = Trim(errorCategory, 100);
        SanitizedError = Trim(sanitizedError, 1000);
        MarkUpdated(utcNow);
    }

    public Guid OutboxMessageId { get; private set; }
    public int AttemptNumber { get; private set; }
    public string Status { get; private set; } = string.Empty;
    public DateTime StartedAtUtc { get; private set; }
    public DateTime? FinishedAtUtc { get; private set; }
    public string? ProviderSummary { get; private set; }
    public string? ErrorCategory { get; private set; }
    public string? SanitizedError { get; private set; }

    private static string? Trim(string? value, int maxLength)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        var clean = value.Trim();
        return clean.Length <= maxLength ? clean : clean[..maxLength];
    }
}
