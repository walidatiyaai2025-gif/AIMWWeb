using AIWordPressManager.Domain.Entities;

namespace AIWordPressManager.Application.Abstractions.Billing;

public interface ISubscriptionLifecyclePolicyService
{
    Task<SubscriptionLifecyclePolicyResult> EvaluateAsync(Guid subscriptionId, DateTime utcNow, CancellationToken cancellationToken = default);
    Task<SubscriptionLifecycleBatchResult> EvaluateBatchAsync(DateTime utcNow, int take = 200, CancellationToken cancellationToken = default);
}

public sealed record SubscriptionLifecyclePolicyResult(
    Guid SubscriptionId,
    AccountSubscriptionStatus PreviousStatus,
    AccountSubscriptionStatus CurrentStatus,
    bool StatusChanged,
    string Reason,
    DateTime? GraceUntilUtc);

public sealed record SubscriptionLifecycleBatchResult(
    int Scanned,
    int Changed,
    int Unchanged,
    int Failed);
