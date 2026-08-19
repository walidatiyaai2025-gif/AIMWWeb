using AIWordPressManager.Domain.Entities;

namespace AIWordPressManager.Application.Abstractions.Billing;

public interface IAccountSubscriptionService
{
    Task<AccountSubscriptionItem?> GetCurrentAsync(Guid ownerUserId, CancellationToken cancellationToken = default);
    Task<AccountSubscriptionItem> CreateAsync(AccountSubscriptionCreateRequest request, CancellationToken cancellationToken = default);
    Task<AccountSubscriptionTransitionResult> TransitionAsync(Guid subscriptionId, AccountSubscriptionTransitionRequest request, CancellationToken cancellationToken = default);
    Task<AccountSubscriptionItem> UpdatePeriodsAsync(Guid subscriptionId, SubscriptionPeriodUpdateRequest request, CancellationToken cancellationToken = default);
    Task<AccountSubscriptionItem> SetCancelAtPeriodEndAsync(Guid subscriptionId, bool cancelAtPeriodEnd, CancellationToken cancellationToken = default);
    Task<AccountSubscriptionPlanChangeResult> ChangePlanAsync(Guid subscriptionId, AccountSubscriptionPlanChangeRequest request, CancellationToken cancellationToken = default);
    Task<AccountSubscriptionItem> BindProviderReferenceAsync(Guid subscriptionId, string? providerKey, string? providerSubscriptionReference, CancellationToken cancellationToken = default);
    Task<IReadOnlyList<AccountSubscriptionTransitionItem>> ListTransitionsAsync(Guid subscriptionId, int take = 100, CancellationToken cancellationToken = default);
    Task<IReadOnlyList<AccountSubscriptionPlanChangeItem>> ListPlanChangesAsync(Guid subscriptionId, int take = 100, CancellationToken cancellationToken = default);
}

public sealed record AccountSubscriptionItem(
    Guid Id,
    Guid OwnerUserId,
    Guid PlanId,
    AccountSubscriptionStatus Status,
    DateTime? TrialStartedAtUtc,
    DateTime? TrialEndsAtUtc,
    DateTime? CurrentPeriodStartUtc,
    DateTime? CurrentPeriodEndsAtUtc,
    bool CancelAtPeriodEnd,
    DateTime? GraceUntilUtc,
    DateTime? CancelledAtUtc,
    DateTime? SuspendedAtUtc,
    DateTime? ExpiredAtUtc,
    string? ProviderKey,
    string? ProviderSubscriptionReference,
    DateTime? LastProviderEventAtUtc,
    DateTime CreatedAtUtc,
    DateTime UpdatedAtUtc);

public sealed record AccountSubscriptionCreateRequest(
    Guid OwnerUserId,
    Guid PlanId,
    AccountSubscriptionStatus InitialStatus,
    DateTime? TrialStartedAtUtc = null,
    DateTime? TrialEndsAtUtc = null,
    DateTime? CurrentPeriodStartUtc = null,
    DateTime? CurrentPeriodEndsAtUtc = null);

public sealed record AccountSubscriptionTransitionRequest(
    AccountSubscriptionStatus TargetStatus,
    SubscriptionTransitionSource Source,
    string Reason,
    DateTime OccurredAtUtc,
    DateTime? ProviderEventAtUtc = null,
    DateTime? GraceUntilUtc = null);

public sealed record SubscriptionPeriodUpdateRequest(
    DateTime? TrialStartedAtUtc,
    DateTime? TrialEndsAtUtc,
    DateTime? CurrentPeriodStartUtc,
    DateTime? CurrentPeriodEndsAtUtc);

public sealed record AccountSubscriptionPlanChangeRequest(
    Guid TargetPlanId,
    SubscriptionTransitionSource Source,
    string Reason,
    DateTime OccurredAtUtc,
    DateTime? ProviderObservedAtUtc = null);

public sealed record AccountSubscriptionTransitionResult(
    AccountSubscriptionItem Subscription,
    bool StatusChanged,
    AccountSubscriptionTransitionItem? Transition);

public sealed record AccountSubscriptionPlanChangeResult(
    AccountSubscriptionItem Subscription,
    bool PlanChanged,
    AccountSubscriptionPlanChangeItem? Change);

public sealed record AccountSubscriptionTransitionItem(
    Guid Id,
    Guid SubscriptionId,
    AccountSubscriptionStatus FromStatus,
    AccountSubscriptionStatus ToStatus,
    SubscriptionTransitionSource Source,
    string Reason,
    DateTime OccurredAtUtc,
    DateTime? ProviderEventAtUtc,
    DateTime CreatedAtUtc);

public sealed record AccountSubscriptionPlanChangeItem(
    Guid Id,
    Guid SubscriptionId,
    Guid FromPlanId,
    Guid ToPlanId,
    SubscriptionTransitionSource Source,
    string Reason,
    DateTime OccurredAtUtc,
    DateTime? ProviderObservedAtUtc,
    DateTime CreatedAtUtc);
