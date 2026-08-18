namespace AIWordPressManager.Application.Abstractions.Billing;

public interface IPayPalSubscriptionSynchronizationService
{
    Task<PayPalSubscriptionSyncBatchResult> ProcessVerifiedEventsAsync(
        DateTime utcNow,
        int take = 100,
        CancellationToken cancellationToken = default);

    Task<PayPalSubscriptionSyncBatchResult> ReconcileBoundSubscriptionsAsync(
        DateTime utcNow,
        int take = 100,
        CancellationToken cancellationToken = default);
}

public sealed record PayPalSubscriptionSyncBatchResult(
    int Scanned,
    int Changed,
    int Unchanged,
    int Ignored,
    int Failed);
