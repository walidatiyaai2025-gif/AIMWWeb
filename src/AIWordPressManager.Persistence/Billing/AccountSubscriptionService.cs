using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence.Email;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Billing;

public sealed class AccountSubscriptionService(AppDbContext dbContext) : IAccountSubscriptionService
{
    public Task<AccountSubscriptionItem?> GetCurrentAsync(Guid ownerUserId, CancellationToken cancellationToken = default)
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
        return dbContext.AccountSubscriptions.AsNoTracking()
            .Where(x => x.OwnerUserId == ownerUserId)
            .Select(x => new AccountSubscriptionItem(
                x.Id, x.OwnerUserId, x.PlanId, x.Status, x.TrialStartedAtUtc, x.TrialEndsAtUtc,
                x.CurrentPeriodStartUtc, x.CurrentPeriodEndsAtUtc, x.CancelAtPeriodEnd, x.GraceUntilUtc,
                x.CancelledAtUtc, x.SuspendedAtUtc, x.ExpiredAtUtc, x.ProviderKey,
                x.ProviderSubscriptionReference, x.LastProviderEventAtUtc, x.CreatedAtUtc, x.UpdatedAtUtc))
            .SingleOrDefaultAsync(cancellationToken);
    }

    public async Task<AccountSubscriptionItem> CreateAsync(AccountSubscriptionCreateRequest request, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        await RequireOwnerAsync(request.OwnerUserId, cancellationToken);
        await RequirePlanAsync(request.PlanId, cancellationToken);
        if (await dbContext.AccountSubscriptions.AsNoTracking().AnyAsync(x => x.OwnerUserId == request.OwnerUserId, cancellationToken))
            throw DuplicateOwner(request.OwnerUserId);

        var subscription = new AccountSubscription(
            request.OwnerUserId,
            request.PlanId,
            request.InitialStatus,
            request.TrialStartedAtUtc,
            request.TrialEndsAtUtc,
            request.CurrentPeriodStartUtc,
            request.CurrentPeriodEndsAtUtc,
            DateTime.UtcNow);
        dbContext.AccountSubscriptions.Add(subscription);
        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
        }
        catch (DbUpdateException ex)
        {
            dbContext.Entry(subscription).State = EntityState.Detached;
            if (await dbContext.AccountSubscriptions.AsNoTracking().AnyAsync(x => x.OwnerUserId == request.OwnerUserId, cancellationToken))
                throw DuplicateOwner(request.OwnerUserId, ex);
            throw;
        }
        return ToItem(subscription);
    }

    public async Task<AccountSubscriptionTransitionResult> TransitionAsync(Guid subscriptionId, AccountSubscriptionTransitionRequest request, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        var reason = ValidateReason(request.Reason, nameof(request.Reason));
        var subscription = await RequireSubscriptionAsync(subscriptionId, cancellationToken);
        var from = subscription.Status;
        var changed = subscription.TransitionTo(
            request.TargetStatus,
            request.Source,
            request.OccurredAtUtc,
            request.ProviderEventAtUtc,
            request.GraceUntilUtc);

        AccountSubscriptionTransition? transition = null;
        if (changed)
        {
            transition = new AccountSubscriptionTransition(
                subscription.Id,
                from,
                subscription.Status,
                request.Source,
                reason,
                request.OccurredAtUtc,
                request.ProviderEventAtUtc);
            dbContext.AccountSubscriptionTransitions.Add(transition);
        }

        await dbContext.SaveChangesAsync(cancellationToken);
        return new AccountSubscriptionTransitionResult(
            ToItem(subscription),
            changed,
            transition is null ? null : ToTransitionItem(transition));
    }

    public async Task<AccountSubscriptionItem> UpdatePeriodsAsync(Guid subscriptionId, SubscriptionPeriodUpdateRequest request, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        var subscription = await RequireSubscriptionAsync(subscriptionId, cancellationToken);
        subscription.UpdatePeriods(
            request.TrialStartedAtUtc,
            request.TrialEndsAtUtc,
            request.CurrentPeriodStartUtc,
            request.CurrentPeriodEndsAtUtc,
            DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return ToItem(subscription);
    }

    public async Task<AccountSubscriptionItem> SetCancelAtPeriodEndAsync(Guid subscriptionId, bool cancelAtPeriodEnd, CancellationToken cancellationToken = default)
    {
        var subscription = await RequireSubscriptionAsync(subscriptionId, cancellationToken);
        subscription.SetCancelAtPeriodEnd(cancelAtPeriodEnd, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return ToItem(subscription);
    }

    public async Task<AccountSubscriptionPlanChangeResult> ChangePlanAsync(
        Guid subscriptionId,
        AccountSubscriptionPlanChangeRequest request,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        await RequirePlanAsync(request.TargetPlanId, cancellationToken);
        ValidateSourceObservation(request.Source, request.ProviderObservedAtUtc);
        var reason = ValidateReason(request.Reason, nameof(request.Reason));
        var subscription = await RequireSubscriptionAsync(subscriptionId, cancellationToken);
        var fromPlanId = subscription.PlanId;
        var changed = subscription.ChangePlan(request.TargetPlanId, request.OccurredAtUtc);

        AccountSubscriptionPlanChange? audit = null;
        if (changed)
        {
            audit = new AccountSubscriptionPlanChange(
                subscription.Id,
                fromPlanId,
                subscription.PlanId,
                request.Source,
                reason,
                request.OccurredAtUtc,
                request.ProviderObservedAtUtc);
            dbContext.AccountSubscriptionPlanChanges.Add(audit);
        }

        await dbContext.SaveChangesAsync(cancellationToken);
        return new AccountSubscriptionPlanChangeResult(
            ToItem(subscription),
            changed,
            audit is null ? null : ToPlanChangeItem(audit));
    }

    public async Task<AccountSubscriptionItem> BindProviderReferenceAsync(Guid subscriptionId, string? providerKey, string? providerSubscriptionReference, CancellationToken cancellationToken = default)
    {
        var subscription = await RequireSubscriptionAsync(subscriptionId, cancellationToken);
        subscription.BindProviderReference(providerKey, providerSubscriptionReference, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return ToItem(subscription);
    }

    public async Task<IReadOnlyList<AccountSubscriptionTransitionItem>> ListTransitionsAsync(Guid subscriptionId, int take = 100, CancellationToken cancellationToken = default)
    {
        await RequireSubscriptionExistsAsync(subscriptionId, cancellationToken);
        take = Math.Clamp(take, 1, 500);
        return await dbContext.AccountSubscriptionTransitions.AsNoTracking()
            .Where(x => x.SubscriptionId == subscriptionId)
            .OrderByDescending(x => x.OccurredAtUtc)
            .ThenByDescending(x => x.CreatedAtUtc)
            .Select(x => new AccountSubscriptionTransitionItem(
                x.Id, x.SubscriptionId, x.FromStatus, x.ToStatus, x.Source, x.Reason,
                x.OccurredAtUtc, x.ProviderEventAtUtc, x.CreatedAtUtc))
            .Take(take)
            .ToListAsync(cancellationToken);
    }

    public async Task<IReadOnlyList<AccountSubscriptionPlanChangeItem>> ListPlanChangesAsync(Guid subscriptionId, int take = 100, CancellationToken cancellationToken = default)
    {
        await RequireSubscriptionExistsAsync(subscriptionId, cancellationToken);
        take = Math.Clamp(take, 1, 500);
        return await dbContext.AccountSubscriptionPlanChanges.AsNoTracking()
            .Where(x => x.SubscriptionId == subscriptionId)
            .OrderByDescending(x => x.OccurredAtUtc)
            .ThenByDescending(x => x.CreatedAtUtc)
            .Select(x => new AccountSubscriptionPlanChangeItem(
                x.Id, x.SubscriptionId, x.FromPlanId, x.ToPlanId, x.Source, x.Reason,
                x.OccurredAtUtc, x.ProviderObservedAtUtc, x.CreatedAtUtc))
            .Take(take)
            .ToListAsync(cancellationToken);
    }

    public async Task<IReadOnlyList<AccountBillingHistoryItem>> ListBillingHistoryAsync(
        Guid ownerUserId,
        Guid subscriptionId,
        int take = 100,
        CancellationToken cancellationToken = default)
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
        if (subscriptionId == Guid.Empty) throw new ArgumentException("Subscription ID is required.", nameof(subscriptionId));

        var owned = await dbContext.AccountSubscriptions.AsNoTracking()
            .AnyAsync(x => x.Id == subscriptionId && x.OwnerUserId == ownerUserId, cancellationToken);
        if (!owned)
            throw new KeyNotFoundException("Account subscription was not found for the current owner.");

        take = Math.Clamp(take, 1, 500);
        var transitions = await dbContext.AccountSubscriptionTransitions.AsNoTracking()
            .Where(x => x.SubscriptionId == subscriptionId)
            .OrderByDescending(x => x.OccurredAtUtc)
            .ThenByDescending(x => x.CreatedAtUtc)
            .Take(take)
            .Select(x => new AccountSubscriptionTransitionItem(
                x.Id, x.SubscriptionId, x.FromStatus, x.ToStatus, x.Source, x.Reason,
                x.OccurredAtUtc, x.ProviderEventAtUtc, x.CreatedAtUtc))
            .ToListAsync(cancellationToken);

        var planChanges = await dbContext.AccountSubscriptionPlanChanges.AsNoTracking()
            .Where(x => x.SubscriptionId == subscriptionId)
            .OrderByDescending(x => x.OccurredAtUtc)
            .ThenByDescending(x => x.CreatedAtUtc)
            .Take(take)
            .Select(x => new AccountSubscriptionPlanChangeItem(
                x.Id, x.SubscriptionId, x.FromPlanId, x.ToPlanId, x.Source, x.Reason,
                x.OccurredAtUtc, x.ProviderObservedAtUtc, x.CreatedAtUtc))
            .ToListAsync(cancellationToken);

        var planIds = planChanges
            .SelectMany(x => new[] { x.FromPlanId, x.ToPlanId })
            .Distinct()
            .ToArray();
        List<BillingPlanSnapshot> plans = planIds.Length == 0
            ? []
            : await dbContext.SubscriptionPlans.AsNoTracking()
                .Where(x => planIds.Contains(x.Id))
                .Select(x => new BillingPlanSnapshot(x.Id, x.NameEn, x.NameAr, x.Price, x.Currency))
                .ToListAsync(cancellationToken);
        var plansById = plans.ToDictionary(x => x.Id);

        var idempotencyKeys = transitions
            .Select(x => SubscriptionBillingEmailAlertRelay.BuildIdempotencyKeyForStatusTransition(x.Id))
            .Concat(planChanges.Select(x => SubscriptionBillingEmailAlertRelay.BuildIdempotencyKeyForPlanChange(x.Id)))
            .Distinct(StringComparer.Ordinal)
            .ToArray();
        List<BillingNotificationSnapshot> notifications = idempotencyKeys.Length == 0
            ? []
            : await dbContext.EmailOutboxMessages.AsNoTracking()
                .Where(x => x.OwnerUserId == ownerUserId && idempotencyKeys.Contains(x.IdempotencyKey))
                .Select(x => new BillingNotificationSnapshot(
                    x.IdempotencyKey,
                    x.Status,
                    x.AttemptCount,
                    x.MaxAttempts,
                    x.SentAtUtc,
                    x.NextAttemptAtUtc))
                .ToListAsync(cancellationToken);
        var notificationsByKey = notifications.ToDictionary(x => x.IdempotencyKey, StringComparer.Ordinal);
        var hasEnabledRecipient = await dbContext.AccountEmailRecipients.AsNoTracking()
            .AnyAsync(x => x.OwnerUserId == ownerUserId && x.IsEnabled, cancellationToken);

        var history = new List<AccountBillingHistoryItem>(transitions.Count + planChanges.Count);
        foreach (var transition in transitions)
        {
            var key = SubscriptionBillingEmailAlertRelay.BuildIdempotencyKeyForStatusTransition(transition.Id);
            notificationsByKey.TryGetValue(key, out var notification);
            history.Add(new AccountBillingHistoryItem(
                transition.Id,
                transition.SubscriptionId,
                AccountBillingHistoryKind.StatusTransition,
                transition.OccurredAtUtc,
                transition.CreatedAtUtc,
                transition.Source,
                BuildTransitionHistoryReason(transition.FromStatus, transition.ToStatus, transition.Source),
                transition.FromStatus,
                transition.ToStatus,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                transition.ProviderEventAtUtc,
                MapNotificationState(notification?.Status, hasEnabledRecipient),
                notification?.AttemptCount ?? 0,
                notification?.MaxAttempts ?? 0,
                notification?.SentAtUtc,
                notification?.NextAttemptAtUtc));
        }

        foreach (var change in planChanges)
        {
            var key = SubscriptionBillingEmailAlertRelay.BuildIdempotencyKeyForPlanChange(change.Id);
            notificationsByKey.TryGetValue(key, out var notification);
            plansById.TryGetValue(change.FromPlanId, out var fromPlan);
            plansById.TryGetValue(change.ToPlanId, out var toPlan);
            history.Add(new AccountBillingHistoryItem(
                change.Id,
                change.SubscriptionId,
                AccountBillingHistoryKind.PlanChange,
                change.OccurredAtUtc,
                change.CreatedAtUtc,
                change.Source,
                BuildPlanChangeHistoryReason(change.Source),
                null,
                null,
                change.FromPlanId,
                change.ToPlanId,
                fromPlan?.NameEn,
                fromPlan?.NameAr,
                toPlan?.NameEn,
                toPlan?.NameAr,
                toPlan?.Price,
                toPlan?.Currency,
                change.ProviderObservedAtUtc,
                MapNotificationState(notification?.Status, hasEnabledRecipient),
                notification?.AttemptCount ?? 0,
                notification?.MaxAttempts ?? 0,
                notification?.SentAtUtc,
                notification?.NextAttemptAtUtc));
        }

        return history
            .OrderByDescending(x => x.OccurredAtUtc)
            .ThenByDescending(x => x.AuditCreatedAtUtc)
            .ThenBy(x => x.Kind)
            .ThenBy(x => x.EventId)
            .Take(take)
            .ToArray();
    }

    private async Task RequireOwnerAsync(Guid ownerUserId, CancellationToken cancellationToken)
    {
        if (ownerUserId == Guid.Empty || !await dbContext.AuthUsers.AsNoTracking().AnyAsync(x => x.Id == ownerUserId, cancellationToken))
            throw new KeyNotFoundException("Subscription owner account was not found.");
    }

    private async Task RequirePlanAsync(Guid planId, CancellationToken cancellationToken)
    {
        if (planId == Guid.Empty || !await dbContext.SubscriptionPlans.AsNoTracking().AnyAsync(x => x.Id == planId, cancellationToken))
            throw new KeyNotFoundException("Subscription plan was not found.");
    }

    private async Task<AccountSubscription> RequireSubscriptionAsync(Guid subscriptionId, CancellationToken cancellationToken)
    {
        if (subscriptionId == Guid.Empty) throw new ArgumentException("Subscription ID is required.", nameof(subscriptionId));
        return await dbContext.AccountSubscriptions.SingleOrDefaultAsync(x => x.Id == subscriptionId, cancellationToken)
            ?? throw new KeyNotFoundException("Account subscription was not found.");
    }

    private async Task RequireSubscriptionExistsAsync(Guid subscriptionId, CancellationToken cancellationToken)
    {
        if (subscriptionId == Guid.Empty || !await dbContext.AccountSubscriptions.AsNoTracking().AnyAsync(x => x.Id == subscriptionId, cancellationToken))
            throw new KeyNotFoundException("Account subscription was not found.");
    }

    private static void ValidateSourceObservation(SubscriptionTransitionSource source, DateTime? providerObservedAtUtc)
    {
        if (source == SubscriptionTransitionSource.Provider)
        {
            if (!providerObservedAtUtc.HasValue)
                throw new ArgumentException("Provider plan change requires a provider observation timestamp.", nameof(providerObservedAtUtc));
            if (providerObservedAtUtc.Value.Kind != DateTimeKind.Utc)
                throw new ArgumentException("Provider observation timestamp must be UTC.", nameof(providerObservedAtUtc));
            return;
        }

        if (providerObservedAtUtc.HasValue)
            throw new ArgumentException("Only Provider plan changes can contain a provider observation timestamp.", nameof(providerObservedAtUtc));
    }

    private static string ValidateReason(string? reason, string parameterName)
    {
        var clean = (reason ?? string.Empty).Trim();
        if (clean.Length == 0 || clean.Length > 500)
            throw new ArgumentException("Reason is required and must be at most 500 characters.", parameterName);
        return clean;
    }

    private static string BuildTransitionHistoryReason(
        AccountSubscriptionStatus fromStatus,
        AccountSubscriptionStatus toStatus,
        SubscriptionTransitionSource source) =>
        source == SubscriptionTransitionSource.Provider
            ? $"Provider reconciliation confirmed the subscription status change from {fromStatus} to {toStatus}."
            : $"The subscription authority recorded a status change from {fromStatus} to {toStatus}.";

    private static string BuildPlanChangeHistoryReason(SubscriptionTransitionSource source) =>
        source == SubscriptionTransitionSource.Provider
            ? "Provider reconciliation confirmed a subscription plan change."
            : "The subscription authority recorded a subscription plan change.";

    private static AccountBillingNotificationState MapNotificationState(string? status, bool hasEnabledRecipient) => status switch
    {
        null => hasEnabledRecipient ? AccountBillingNotificationState.NotQueued : AccountBillingNotificationState.NotConfigured,
        EmailOutboxMessage.QueuedStatus => AccountBillingNotificationState.Queued,
        EmailOutboxMessage.SendingStatus => AccountBillingNotificationState.Sending,
        EmailOutboxMessage.SentStatus => AccountBillingNotificationState.Sent,
        EmailOutboxMessage.RetryWaitingStatus => AccountBillingNotificationState.Retrying,
        EmailOutboxMessage.FailedStatus => AccountBillingNotificationState.Failed,
        EmailOutboxMessage.CancelledStatus => AccountBillingNotificationState.Cancelled,
        _ => AccountBillingNotificationState.NotQueued
    };

    private static InvalidOperationException DuplicateOwner(Guid ownerUserId, Exception? inner = null) =>
        new($"Account '{ownerUserId:D}' already has a current subscription record.", inner);

    private static AccountSubscriptionItem ToItem(AccountSubscription x) => new(
        x.Id, x.OwnerUserId, x.PlanId, x.Status, x.TrialStartedAtUtc, x.TrialEndsAtUtc,
        x.CurrentPeriodStartUtc, x.CurrentPeriodEndsAtUtc, x.CancelAtPeriodEnd, x.GraceUntilUtc,
        x.CancelledAtUtc, x.SuspendedAtUtc, x.ExpiredAtUtc, x.ProviderKey,
        x.ProviderSubscriptionReference, x.LastProviderEventAtUtc, x.CreatedAtUtc, x.UpdatedAtUtc);

    private static AccountSubscriptionTransitionItem ToTransitionItem(AccountSubscriptionTransition x) => new(
        x.Id, x.SubscriptionId, x.FromStatus, x.ToStatus, x.Source, x.Reason,
        x.OccurredAtUtc, x.ProviderEventAtUtc, x.CreatedAtUtc);

    private static AccountSubscriptionPlanChangeItem ToPlanChangeItem(AccountSubscriptionPlanChange x) => new(
        x.Id, x.SubscriptionId, x.FromPlanId, x.ToPlanId, x.Source, x.Reason,
        x.OccurredAtUtc, x.ProviderObservedAtUtc, x.CreatedAtUtc);

    private sealed record BillingPlanSnapshot(
        Guid Id,
        string NameEn,
        string NameAr,
        decimal Price,
        string Currency);

    private sealed record BillingNotificationSnapshot(
        string IdempotencyKey,
        string Status,
        int AttemptCount,
        int MaxAttempts,
        DateTime? SentAtUtc,
        DateTime NextAttemptAtUtc);
}
