using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class PayPalWebhookInboxEvent : Entity
{
    private PayPalWebhookInboxEvent() { }

    public PayPalWebhookInboxEvent(
        string providerEventId,
        string eventType,
        string providerSubscriptionReference,
        string normalizedState,
        DateTime occurredAtUtc,
        DateTime receivedAtUtc)
    {
        ProviderEventId = RequiredBounded(providerEventId, 200, nameof(providerEventId));
        NormalizedProviderEventId = ProviderEventId.ToUpperInvariant();
        EventType = RequiredBounded(eventType, 160, nameof(eventType));
        ProviderSubscriptionReference = RequiredBounded(providerSubscriptionReference, 200, nameof(providerSubscriptionReference));
        NormalizedState = RequiredBounded(normalizedState, 32, nameof(normalizedState));
        RequireUtc(occurredAtUtc, nameof(occurredAtUtc));
        RequireUtc(receivedAtUtc, nameof(receivedAtUtc));
        if (receivedAtUtc < occurredAtUtc.AddDays(-1))
            throw new ArgumentException("Webhook receive time is implausibly earlier than the provider event time.", nameof(receivedAtUtc));
        OccurredAtUtc = occurredAtUtc;
        ReceivedAtUtc = receivedAtUtc;
        MarkUpdated(receivedAtUtc);
    }

    public string ProviderEventId { get; private set; } = string.Empty;
    public string NormalizedProviderEventId { get; private set; } = string.Empty;
    public string EventType { get; private set; } = string.Empty;
    public string ProviderSubscriptionReference { get; private set; } = string.Empty;
    public string NormalizedState { get; private set; } = string.Empty;
    public DateTime OccurredAtUtc { get; private set; }
    public DateTime ReceivedAtUtc { get; private set; }

    private static string RequiredBounded(string? value, int maxLength, string parameterName)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value, parameterName);
        var clean = value.Trim();
        if (clean.Length > maxLength)
            throw new ArgumentException($"Value must be at most {maxLength} characters.", parameterName);
        return clean;
    }

    private static void RequireUtc(DateTime value, string parameterName)
    {
        if (value.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Timestamp must be UTC.", parameterName);
    }
}
