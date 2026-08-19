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

    Task<PayPalSubscriptionReconciliationResult> ReconcileSubscriptionAsync(
        Guid subscriptionId,
        DateTime utcNow,
        CancellationToken cancellationToken = default) =>
        throw new NotSupportedException("Targeted subscription reconciliation is not supported by this implementation.");
}

public sealed record PayPalSubscriptionSyncBatchResult(
    int Scanned,
    int Changed,
    int Unchanged,
    int Ignored,
    int Failed);

public enum PayPalSubscriptionReconciliationOutcome
{
    Changed = 1,
    Unchanged = 2,
    Ignored = 3
}

public sealed record PayPalSubscriptionReconciliationResult(
    Guid SubscriptionId,
    PayPalSubscriptionReconciliationOutcome Outcome,
    DateTime? LastProviderEventAtUtc)
{
    public bool Changed => Outcome == PayPalSubscriptionReconciliationOutcome.Changed;
}
