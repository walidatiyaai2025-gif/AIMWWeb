namespace AIWordPressManager.Domain.Entities;

public sealed record SubscriptionLifecycleSnapshot(
    AccountSubscriptionStatus Status,
    DateTime? TrialEndsAtUtc,
    DateTime? CurrentPeriodEndsAtUtc,
    bool CancelAtPeriodEnd,
    DateTime? GraceUntilUtc,
    int GracePeriodDays);

public sealed record SubscriptionLifecycleDecision(
    bool RequiresTransition,
    AccountSubscriptionStatus? TargetStatus,
    DateTime? GraceUntilUtc,
    string Reason)
{
    public static SubscriptionLifecycleDecision NoChange(string reason) => new(false, null, null, reason);
    public static SubscriptionLifecycleDecision Transition(AccountSubscriptionStatus target, string reason, DateTime? graceUntilUtc = null) => new(true, target, graceUntilUtc, reason);
}

public static class SubscriptionLifecyclePolicy
{
    public static SubscriptionLifecycleDecision Evaluate(SubscriptionLifecycleSnapshot snapshot, DateTime utcNow)
    {
        if (utcNow.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Policy evaluation timestamp must be UTC.", nameof(utcNow));
        if (snapshot.GracePeriodDays < 0 || snapshot.GracePeriodDays > SubscriptionPlan.MaximumGracePeriodDays)
            throw new ArgumentOutOfRangeException(nameof(snapshot), "Grace-period policy is outside supported bounds.");

        return snapshot.Status switch
        {
            AccountSubscriptionStatus.Trialing => EvaluateTrial(snapshot, utcNow),
            AccountSubscriptionStatus.Active => EvaluateActive(snapshot, utcNow),
            AccountSubscriptionStatus.PastDue => EvaluatePastDue(snapshot, utcNow),
            AccountSubscriptionStatus.Grace => EvaluateGrace(snapshot, utcNow),
            AccountSubscriptionStatus.Cancelled => EvaluateCancelled(snapshot, utcNow),
            AccountSubscriptionStatus.Suspended => SubscriptionLifecycleDecision.NoChange("Suspended subscriptions require an explicit recovery or expiry decision."),
            AccountSubscriptionStatus.Expired => SubscriptionLifecycleDecision.NoChange("Expired is terminal for the current subscription record."),
            _ => SubscriptionLifecycleDecision.NoChange("No lifecycle policy applies to the current state.")
        };
    }

    private static SubscriptionLifecycleDecision EvaluateTrial(SubscriptionLifecycleSnapshot snapshot, DateTime utcNow)
    {
        if (!snapshot.TrialEndsAtUtc.HasValue || snapshot.TrialEndsAtUtc.Value > utcNow)
            return SubscriptionLifecycleDecision.NoChange("Trial is still valid or has no automatic expiry boundary.");
        return SubscriptionLifecycleDecision.Transition(AccountSubscriptionStatus.Expired, "Trial period elapsed without an explicit activation event.");
    }

    private static SubscriptionLifecycleDecision EvaluateActive(SubscriptionLifecycleSnapshot snapshot, DateTime utcNow)
    {
        if (!snapshot.CancelAtPeriodEnd || !snapshot.CurrentPeriodEndsAtUtc.HasValue || snapshot.CurrentPeriodEndsAtUtc.Value > utcNow)
            return SubscriptionLifecycleDecision.NoChange("Active subscription remains within its current policy window.");
        return SubscriptionLifecycleDecision.Transition(AccountSubscriptionStatus.Expired, "Cancel-at-period-end boundary elapsed.");
    }

    private static SubscriptionLifecycleDecision EvaluatePastDue(SubscriptionLifecycleSnapshot snapshot, DateTime utcNow)
    {
        if (snapshot.GracePeriodDays == 0)
            return SubscriptionLifecycleDecision.Transition(AccountSubscriptionStatus.Suspended, "Past-due subscription has no configured grace period.");

        var baseUtc = snapshot.CurrentPeriodEndsAtUtc is { } periodEnd && periodEnd <= utcNow ? periodEnd : utcNow;
        var graceUntil = baseUtc.AddDays(snapshot.GracePeriodDays);
        if (graceUntil <= utcNow)
            return SubscriptionLifecycleDecision.Transition(AccountSubscriptionStatus.Suspended, "Configured grace period already elapsed.");
        return SubscriptionLifecycleDecision.Transition(AccountSubscriptionStatus.Grace, "Past-due subscription entered its configured grace period.", graceUntil);
    }

    private static SubscriptionLifecycleDecision EvaluateGrace(SubscriptionLifecycleSnapshot snapshot, DateTime utcNow)
    {
        if (!snapshot.GraceUntilUtc.HasValue)
            return SubscriptionLifecycleDecision.NoChange("Grace state has no persisted deadline; automatic mutation is refused.");
        if (snapshot.GraceUntilUtc.Value > utcNow)
            return SubscriptionLifecycleDecision.NoChange("Grace period is still valid.");
        return SubscriptionLifecycleDecision.Transition(AccountSubscriptionStatus.Suspended, "Grace period elapsed.");
    }

    private static SubscriptionLifecycleDecision EvaluateCancelled(SubscriptionLifecycleSnapshot snapshot, DateTime utcNow)
    {
        if (!snapshot.CurrentPeriodEndsAtUtc.HasValue || snapshot.CurrentPeriodEndsAtUtc.Value > utcNow)
            return SubscriptionLifecycleDecision.NoChange("Cancelled subscription remains within its retained current period.");
        return SubscriptionLifecycleDecision.Transition(AccountSubscriptionStatus.Expired, "Cancelled subscription current period elapsed.");
    }
}
