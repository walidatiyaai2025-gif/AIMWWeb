using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
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
}
