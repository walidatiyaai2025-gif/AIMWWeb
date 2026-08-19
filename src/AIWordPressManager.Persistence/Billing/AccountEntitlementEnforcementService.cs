using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Billing;

public sealed class AccountEntitlementEnforcementService(
    AppDbContext dbContext,
    IPlanEntitlementResolver entitlementResolver) : IAccountEntitlementEnforcementService
{
    public async Task RequireBooleanCapabilityAsync(
        Guid ownerUserId,
        string entitlementKey,
        CancellationToken cancellationToken = default)
    {
        var subscription = await RequireUsableSubscriptionAsync(ownerUserId, entitlementKey, cancellationToken);
        var check = await entitlementResolver.CheckBooleanCapabilityAsync(subscription.PlanId, entitlementKey, cancellationToken);

        if (!check.IsConfigured)
            throw Denied("subscription_entitlement_missing", check.Key, $"The current plan does not configure the '{check.Key}' capability.");
        if (!check.IsEnabled)
            throw Denied("subscription_feature_disabled", check.Key, $"The current plan does not include the '{check.Key}' capability.");
    }

    public async Task RequireAdditionalUsageAsync(
        Guid ownerUserId,
        string entitlementKey,
        long currentUsage,
        long requestedAdditional = 1,
        CancellationToken cancellationToken = default)
    {
        if (currentUsage < 0)
            throw new ArgumentOutOfRangeException(nameof(currentUsage), "Current usage cannot be negative.");
        if (requestedAdditional <= 0)
            throw new ArgumentOutOfRangeException(nameof(requestedAdditional), "Requested additional usage must be greater than zero.");

        var subscription = await RequireUsableSubscriptionAsync(ownerUserId, entitlementKey, cancellationToken);
        var check = await entitlementResolver.CheckIntegerLimitAsync(
            subscription.PlanId,
            entitlementKey,
            currentUsage,
            requestedAdditional,
            cancellationToken);

        if (!check.IsConfigured || !check.Limit.HasValue)
            throw Denied(
                "subscription_entitlement_missing",
                check.Key,
                $"The current plan does not configure the '{check.Key}' limit.",
                currentUsage: currentUsage,
                requestedAdditional: requestedAdditional);

        if (!check.IsAllowed)
            throw Denied(
                "subscription_usage_limit_reached",
                check.Key,
                $"The current plan limit for '{check.Key}' is {check.Limit.Value}.",
                check.Limit,
                currentUsage,
                requestedAdditional);
    }

    private async Task<SubscriptionSnapshot> RequireUsableSubscriptionAsync(
        Guid ownerUserId,
        string entitlementKey,
        CancellationToken cancellationToken)
    {
        if (ownerUserId == Guid.Empty)
            throw Denied("subscription_account_required", entitlementKey, "A signed-in account is required for this operation.");

        var subscription = await dbContext.AccountSubscriptions.AsNoTracking()
            .Where(x => x.OwnerUserId == ownerUserId)
            .Select(x => new SubscriptionSnapshot(
                x.PlanId,
                x.Status,
                x.TrialEndsAtUtc,
                x.CurrentPeriodEndsAtUtc,
                x.GraceUntilUtc))
            .SingleOrDefaultAsync(cancellationToken);

        if (subscription is null)
            throw Denied("subscription_required", entitlementKey, "An active subscription is required for this operation.");

        var now = DateTime.UtcNow;
        if (!IsUsable(subscription, now))
            throw Denied(
                "subscription_inactive",
                entitlementKey,
                $"The account subscription is not usable in status '{subscription.Status}'.");

        return subscription;
    }

    private static bool IsUsable(SubscriptionSnapshot subscription, DateTime utcNow) =>
        subscription.Status switch
        {
            AccountSubscriptionStatus.Active => !subscription.CurrentPeriodEndsAtUtc.HasValue || subscription.CurrentPeriodEndsAtUtc.Value > utcNow,
            AccountSubscriptionStatus.Trialing => subscription.TrialEndsAtUtc.HasValue && subscription.TrialEndsAtUtc.Value > utcNow,
            AccountSubscriptionStatus.Grace => subscription.GraceUntilUtc.HasValue && subscription.GraceUntilUtc.Value > utcNow,
            _ => false
        };

    private static AccountEntitlementDeniedException Denied(
        string code,
        string entitlementKey,
        string message,
        long? limit = null,
        long? currentUsage = null,
        long? requestedAdditional = null) =>
        new(code, entitlementKey, message, limit, currentUsage, requestedAdditional);

    private sealed record SubscriptionSnapshot(
        Guid PlanId,
        AccountSubscriptionStatus Status,
        DateTime? TrialEndsAtUtc,
        DateTime? CurrentPeriodEndsAtUtc,
        DateTime? GraceUntilUtc);
}
