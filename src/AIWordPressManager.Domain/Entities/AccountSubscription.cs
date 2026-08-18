using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public enum AccountSubscriptionStatus
{
    Trialing = 1,
    Active = 2,
    PastDue = 3,
    Grace = 4,
    Suspended = 5,
    Cancelled = 6,
    Expired = 7
}

public enum SubscriptionTransitionSource
{
    System = 1,
    Provider = 2,
    Administration = 3
}

public sealed class AccountSubscription : Entity
{
    private AccountSubscription() { }

    public AccountSubscription(
        Guid ownerUserId,
        Guid planId,
        AccountSubscriptionStatus initialStatus,
        DateTime? trialStartedAtUtc,
        DateTime? trialEndsAtUtc,
        DateTime? currentPeriodStartUtc,
        DateTime? currentPeriodEndsAtUtc,
        DateTime utcNow)
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
        if (planId == Guid.Empty) throw new ArgumentException("Plan ID is required.", nameof(planId));
        RequireUtc(utcNow, nameof(utcNow));
        ValidateRange(trialStartedAtUtc, trialEndsAtUtc, nameof(trialStartedAtUtc), nameof(trialEndsAtUtc));
        ValidateRange(currentPeriodStartUtc, currentPeriodEndsAtUtc, nameof(currentPeriodStartUtc), nameof(currentPeriodEndsAtUtc));
        if (initialStatus == AccountSubscriptionStatus.Expired)
            throw new ArgumentException("A new subscription cannot start in Expired state.", nameof(initialStatus));
        if (initialStatus == AccountSubscriptionStatus.Grace)
            throw new ArgumentException("A new subscription cannot start in Grace state without an explicit grace transition.", nameof(initialStatus));

        OwnerUserId = ownerUserId;
        PlanId = planId;
        Status = initialStatus;
        TrialStartedAtUtc = trialStartedAtUtc;
        TrialEndsAtUtc = trialEndsAtUtc;
        CurrentPeriodStartUtc = currentPeriodStartUtc;
        CurrentPeriodEndsAtUtc = currentPeriodEndsAtUtc;
        if (initialStatus == AccountSubscriptionStatus.Suspended) SuspendedAtUtc = utcNow;
        if (initialStatus == AccountSubscriptionStatus.Cancelled) CancelledAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public Guid OwnerUserId { get; private set; }
    public Guid PlanId { get; private set; }
    public AccountSubscriptionStatus Status { get; private set; }
    public DateTime? TrialStartedAtUtc { get; private set; }
    public DateTime? TrialEndsAtUtc { get; private set; }
    public DateTime? CurrentPeriodStartUtc { get; private set; }
    public DateTime? CurrentPeriodEndsAtUtc { get; private set; }
    public bool CancelAtPeriodEnd { get; private set; }
    public DateTime? GraceUntilUtc { get; private set; }
    public DateTime? CancelledAtUtc { get; private set; }
    public DateTime? SuspendedAtUtc { get; private set; }
    public DateTime? ExpiredAtUtc { get; private set; }
    public string? ProviderKey { get; private set; }
    public string? ProviderSubscriptionReference { get; private set; }
    public DateTime? LastProviderEventAtUtc { get; private set; }

    public bool TransitionTo(
        AccountSubscriptionStatus targetStatus,
        SubscriptionTransitionSource source,
        DateTime occurredAtUtc,
        DateTime? providerEventAtUtc = null,
        DateTime? graceUntilUtc = null)
    {
        RequireUtc(occurredAtUtc, nameof(occurredAtUtc));
        ValidateProviderEvent(source, providerEventAtUtc);

        if (targetStatus != Status)
        {
            if (!AccountSubscriptionStateMachine.CanTransition(Status, targetStatus))
                throw new InvalidOperationException($"Subscription transition {Status} -> {targetStatus} is not allowed.");
            ValidateGraceTransition(targetStatus, occurredAtUtc, graceUntilUtc);
        }
        else if (graceUntilUtc.HasValue)
        {
            throw new ArgumentException("Grace-until timestamp is only valid when entering Grace state.", nameof(graceUntilUtc));
        }

        if (source == SubscriptionTransitionSource.Provider && providerEventAtUtc.HasValue)
            LastProviderEventAtUtc = providerEventAtUtc;

        if (targetStatus == Status)
        {
            MarkUpdated(occurredAtUtc);
            return false;
        }

        GraceUntilUtc = targetStatus == AccountSubscriptionStatus.Grace ? graceUntilUtc : null;
        Status = targetStatus;
        if (targetStatus == AccountSubscriptionStatus.Suspended) SuspendedAtUtc = occurredAtUtc;
        if (targetStatus == AccountSubscriptionStatus.Cancelled) CancelledAtUtc = occurredAtUtc;
        if (targetStatus == AccountSubscriptionStatus.Expired) ExpiredAtUtc = occurredAtUtc;
        MarkUpdated(occurredAtUtc);
        return true;
    }

    public void UpdatePeriods(
        DateTime? trialStartedAtUtc,
        DateTime? trialEndsAtUtc,
        DateTime? currentPeriodStartUtc,
        DateTime? currentPeriodEndsAtUtc,
        DateTime utcNow)
    {
        RequireUtc(utcNow, nameof(utcNow));
        ValidateRange(trialStartedAtUtc, trialEndsAtUtc, nameof(trialStartedAtUtc), nameof(trialEndsAtUtc));
        ValidateRange(currentPeriodStartUtc, currentPeriodEndsAtUtc, nameof(currentPeriodStartUtc), nameof(currentPeriodEndsAtUtc));
        TrialStartedAtUtc = trialStartedAtUtc;
        TrialEndsAtUtc = trialEndsAtUtc;
        CurrentPeriodStartUtc = currentPeriodStartUtc;
        CurrentPeriodEndsAtUtc = currentPeriodEndsAtUtc;
        MarkUpdated(utcNow);
    }

    public void SetCancelAtPeriodEnd(bool cancelAtPeriodEnd, DateTime utcNow)
    {
        RequireUtc(utcNow, nameof(utcNow));
        if (CancelAtPeriodEnd == cancelAtPeriodEnd) return;
        CancelAtPeriodEnd = cancelAtPeriodEnd;
        MarkUpdated(utcNow);
    }

    public void BindProviderReference(string? providerKey, string? providerSubscriptionReference, DateTime utcNow)
    {
        RequireUtc(utcNow, nameof(utcNow));
        var cleanKey = OptionalBounded(providerKey, 64, nameof(providerKey));
        var cleanReference = OptionalBounded(providerSubscriptionReference, 200, nameof(providerSubscriptionReference));
        if ((cleanKey is null) != (cleanReference is null))
            throw new ArgumentException("Provider key and subscription reference must both be supplied or both be empty.");

        ProviderKey = cleanKey;
        ProviderSubscriptionReference = cleanReference;
        MarkUpdated(utcNow);
    }

    private static void ValidateGraceTransition(
        AccountSubscriptionStatus targetStatus,
        DateTime occurredAtUtc,
        DateTime? graceUntilUtc)
    {
        if (targetStatus != AccountSubscriptionStatus.Grace)
        {
            if (graceUntilUtc.HasValue)
                throw new ArgumentException("Grace-until timestamp is only valid when entering Grace state.", nameof(graceUntilUtc));
            return;
        }

        if (!graceUntilUtc.HasValue)
            throw new ArgumentException("Grace transition requires a grace-until timestamp.", nameof(graceUntilUtc));
        RequireUtc(graceUntilUtc.Value, nameof(graceUntilUtc));
        if (graceUntilUtc.Value <= occurredAtUtc)
            throw new ArgumentException("Grace-until timestamp must be later than the transition time.", nameof(graceUntilUtc));
    }

    private void ValidateProviderEvent(SubscriptionTransitionSource source, DateTime? providerEventAtUtc)
    {
        if (source != SubscriptionTransitionSource.Provider)
        {
            if (providerEventAtUtc.HasValue)
                throw new ArgumentException("Provider event timestamp is only valid for Provider transitions.", nameof(providerEventAtUtc));
            return;
        }

        if (!providerEventAtUtc.HasValue)
            throw new ArgumentException("Provider transition requires the provider event timestamp.", nameof(providerEventAtUtc));
        RequireUtc(providerEventAtUtc.Value, nameof(providerEventAtUtc));
        if (LastProviderEventAtUtc.HasValue && providerEventAtUtc.Value <= LastProviderEventAtUtc.Value)
            throw new InvalidOperationException("Provider transition is stale or duplicated relative to the last applied provider event.");
    }

    private static void ValidateRange(DateTime? startUtc, DateTime? endUtc, string startName, string endName)
    {
        if (startUtc.HasValue) RequireUtc(startUtc.Value, startName);
        if (endUtc.HasValue) RequireUtc(endUtc.Value, endName);
        if (startUtc.HasValue != endUtc.HasValue)
            throw new ArgumentException("Lifecycle time ranges require both start and end timestamps.");
        if (startUtc.HasValue && endUtc <= startUtc)
            throw new ArgumentException("Lifecycle range end must be later than start.");
    }

    private static string? OptionalBounded(string? value, int maxLength, string parameterName)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        var trimmed = value.Trim();
        if (trimmed.Length > maxLength)
            throw new ArgumentException($"Value must be at most {maxLength} characters.", parameterName);
        return trimmed;
    }

    private static void RequireUtc(DateTime value, string parameterName)
    {
        if (value.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Timestamp must be UTC.", parameterName);
    }
}

public static class AccountSubscriptionStateMachine
{
    public static bool CanTransition(AccountSubscriptionStatus from, AccountSubscriptionStatus to)
    {
        if (from == to) return true;
        return from switch
        {
            AccountSubscriptionStatus.Trialing => to is AccountSubscriptionStatus.Active or AccountSubscriptionStatus.PastDue or AccountSubscriptionStatus.Grace or AccountSubscriptionStatus.Suspended or AccountSubscriptionStatus.Cancelled or AccountSubscriptionStatus.Expired,
            AccountSubscriptionStatus.Active => to is AccountSubscriptionStatus.PastDue or AccountSubscriptionStatus.Grace or AccountSubscriptionStatus.Suspended or AccountSubscriptionStatus.Cancelled or AccountSubscriptionStatus.Expired,
            AccountSubscriptionStatus.PastDue => to is AccountSubscriptionStatus.Active or AccountSubscriptionStatus.Grace or AccountSubscriptionStatus.Suspended or AccountSubscriptionStatus.Cancelled or AccountSubscriptionStatus.Expired,
            AccountSubscriptionStatus.Grace => to is AccountSubscriptionStatus.Active or AccountSubscriptionStatus.PastDue or AccountSubscriptionStatus.Suspended or AccountSubscriptionStatus.Cancelled or AccountSubscriptionStatus.Expired,
            AccountSubscriptionStatus.Suspended => to is AccountSubscriptionStatus.Active or AccountSubscriptionStatus.PastDue or AccountSubscriptionStatus.Grace or AccountSubscriptionStatus.Cancelled or AccountSubscriptionStatus.Expired,
            AccountSubscriptionStatus.Cancelled => to is AccountSubscriptionStatus.Active or AccountSubscriptionStatus.Expired,
            AccountSubscriptionStatus.Expired => false,
            _ => false
        };
    }
}

public sealed class AccountSubscriptionTransition : Entity
{
    private AccountSubscriptionTransition() { }

    public AccountSubscriptionTransition(
        Guid subscriptionId,
        AccountSubscriptionStatus fromStatus,
        AccountSubscriptionStatus toStatus,
        SubscriptionTransitionSource source,
        string reason,
        DateTime occurredAtUtc,
        DateTime? providerEventAtUtc)
    {
        if (subscriptionId == Guid.Empty) throw new ArgumentException("Subscription ID is required.", nameof(subscriptionId));
        if (fromStatus == toStatus) throw new ArgumentException("Transition audit requires a real status change.");
        if (occurredAtUtc.Kind != DateTimeKind.Utc) throw new ArgumentException("Transition timestamp must be UTC.", nameof(occurredAtUtc));
        if (providerEventAtUtc.HasValue && providerEventAtUtc.Value.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Provider event timestamp must be UTC.", nameof(providerEventAtUtc));
        var cleanReason = (reason ?? string.Empty).Trim();
        if (cleanReason.Length == 0 || cleanReason.Length > 500)
            throw new ArgumentException("Transition reason is required and must be at most 500 characters.", nameof(reason));
        if (source == SubscriptionTransitionSource.Provider && !providerEventAtUtc.HasValue)
            throw new ArgumentException("Provider transition audit requires a provider event timestamp.", nameof(providerEventAtUtc));
        if (source != SubscriptionTransitionSource.Provider && providerEventAtUtc.HasValue)
            throw new ArgumentException("Only Provider transition audit can contain a provider event timestamp.", nameof(providerEventAtUtc));

        SubscriptionId = subscriptionId;
        FromStatus = fromStatus;
        ToStatus = toStatus;
        Source = source;
        Reason = cleanReason;
        OccurredAtUtc = occurredAtUtc;
        ProviderEventAtUtc = providerEventAtUtc;
        MarkUpdated(occurredAtUtc);
    }

    public Guid SubscriptionId { get; private set; }
    public AccountSubscriptionStatus FromStatus { get; private set; }
    public AccountSubscriptionStatus ToStatus { get; private set; }
    public SubscriptionTransitionSource Source { get; private set; }
    public string Reason { get; private set; } = string.Empty;
    public DateTime OccurredAtUtc { get; private set; }
    public DateTime? ProviderEventAtUtc { get; private set; }
}
