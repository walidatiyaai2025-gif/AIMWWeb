namespace AIWordPressManager.Application.Abstractions.Billing;

public interface IPayPalWebhookInbox
{
    Task<PayPalWebhookInboxAcceptResult> AcceptVerifiedAsync(
        GatewayVerifiedEvent gatewayEvent,
        DateTime receivedAtUtc,
        CancellationToken cancellationToken = default);

    Task<PayPalWebhookInboxItem?> GetByProviderEventIdAsync(
        string providerEventId,
        CancellationToken cancellationToken = default);
}

public sealed record PayPalWebhookInboxAcceptResult(
    bool Inserted,
    PayPalWebhookInboxItem Event);

public sealed record PayPalWebhookInboxItem(
    Guid Id,
    string ProviderEventId,
    string EventType,
    string ProviderSubscriptionReference,
    GatewaySubscriptionState State,
    DateTime OccurredAtUtc,
    DateTime ReceivedAtUtc,
    DateTime CreatedAtUtc);
