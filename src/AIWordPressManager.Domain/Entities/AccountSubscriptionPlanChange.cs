using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class AccountSubscriptionPlanChange : Entity
{
    private AccountSubscriptionPlanChange() { }

    public AccountSubscriptionPlanChange(
        Guid subscriptionId,
        Guid fromPlanId,
        Guid toPlanId,
        SubscriptionTransitionSource source,
        string reason,
        DateTime occurredAtUtc,
        DateTime? providerObservedAtUtc = null)
    {
        if (subscriptionId == Guid.Empty) throw new ArgumentException("Subscription ID is required.", nameof(subscriptionId));
        if (fromPlanId == Guid.Empty) throw new ArgumentException("Source plan ID is required.", nameof(fromPlanId));
        if (toPlanId == Guid.Empty) throw new ArgumentException("Target plan ID is required.", nameof(toPlanId));
        if (fromPlanId == toPlanId) throw new ArgumentException("Plan-change audit requires a real plan change.");
        if (occurredAtUtc.Kind != DateTimeKind.Utc) throw new ArgumentException("Plan-change timestamp must be UTC.", nameof(occurredAtUtc));
        if (providerObservedAtUtc.HasValue && providerObservedAtUtc.Value.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Provider observation timestamp must be UTC.", nameof(providerObservedAtUtc));
        if (source == SubscriptionTransitionSource.Provider && !providerObservedAtUtc.HasValue)
            throw new ArgumentException("Provider plan-change audit requires a provider observation timestamp.", nameof(providerObservedAtUtc));
        if (source != SubscriptionTransitionSource.Provider && providerObservedAtUtc.HasValue)
            throw new ArgumentException("Only Provider plan-change audit can contain a provider observation timestamp.", nameof(providerObservedAtUtc));

        var cleanReason = (reason ?? string.Empty).Trim();
        if (cleanReason.Length == 0 || cleanReason.Length > 500)
            throw new ArgumentException("Plan-change reason is required and must be at most 500 characters.", nameof(reason));

        SubscriptionId = subscriptionId;
        FromPlanId = fromPlanId;
        ToPlanId = toPlanId;
        Source = source;
        Reason = cleanReason;
        OccurredAtUtc = occurredAtUtc;
        ProviderObservedAtUtc = providerObservedAtUtc;
        MarkUpdated(occurredAtUtc);
    }

    public Guid SubscriptionId { get; private set; }
    public Guid FromPlanId { get; private set; }
    public Guid ToPlanId { get; private set; }
    public SubscriptionTransitionSource Source { get; private set; }
    public string Reason { get; private set; } = string.Empty;
    public DateTime OccurredAtUtc { get; private set; }
    public DateTime? ProviderObservedAtUtc { get; private set; }
}
