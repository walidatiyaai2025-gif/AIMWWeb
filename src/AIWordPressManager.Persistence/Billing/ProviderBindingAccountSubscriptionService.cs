using AIWordPressManager.Application.Abstractions.Billing;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Billing;

public sealed class ProviderBindingAccountSubscriptionService(
    AppDbContext dbContext,
    AccountSubscriptionService innerService) : IAccountSubscriptionService
{
    public Task<AccountSubscriptionItem?> GetCurrentAsync(Guid ownerUserId, CancellationToken cancellationToken = default) =>
        innerService.GetCurrentAsync(ownerUserId, cancellationToken);

    public Task<AccountSubscriptionItem> CreateAsync(AccountSubscriptionCreateRequest request, CancellationToken cancellationToken = default) =>
        innerService.CreateAsync(request, cancellationToken);

    public Task<AccountSubscriptionTransitionResult> TransitionAsync(Guid subscriptionId, AccountSubscriptionTransitionRequest request, CancellationToken cancellationToken = default) =>
        innerService.TransitionAsync(subscriptionId, request, cancellationToken);

    public Task<AccountSubscriptionItem> UpdatePeriodsAsync(Guid subscriptionId, SubscriptionPeriodUpdateRequest request, CancellationToken cancellationToken = default) =>
        innerService.UpdatePeriodsAsync(subscriptionId, request, cancellationToken);

    public Task<AccountSubscriptionItem> SetCancelAtPeriodEndAsync(Guid subscriptionId, bool cancelAtPeriodEnd, CancellationToken cancellationToken = default) =>
        innerService.SetCancelAtPeriodEndAsync(subscriptionId, cancelAtPeriodEnd, cancellationToken);

    public async Task<AccountSubscriptionItem> BindProviderReferenceAsync(
        Guid subscriptionId,
        string? providerKey,
        string? providerSubscriptionReference,
        CancellationToken cancellationToken = default)
    {
        if (subscriptionId == Guid.Empty)
            throw new ArgumentException("Subscription ID is required.", nameof(subscriptionId));

        var cleanKey = string.IsNullOrWhiteSpace(providerKey) ? null : providerKey.Trim().ToLowerInvariant();
        var cleanReference = string.IsNullOrWhiteSpace(providerSubscriptionReference) ? null : providerSubscriptionReference.Trim();
        if ((cleanKey is null) != (cleanReference is null))
            throw new ArgumentException("Provider key and subscription reference must both be supplied or both be empty.");
        if (cleanKey is { Length: > 64 })
            throw new ArgumentException("Provider key must be at most 64 characters.", nameof(providerKey));
        if (cleanReference is { Length: > 200 })
            throw new ArgumentException("Provider subscription reference must be at most 200 characters.", nameof(providerSubscriptionReference));

        var current = await dbContext.AccountSubscriptions.AsNoTracking()
            .Where(x => x.Id == subscriptionId)
            .Select(x => new { x.ProviderKey, x.ProviderSubscriptionReference })
            .SingleOrDefaultAsync(cancellationToken)
            ?? throw new KeyNotFoundException("Account subscription was not found.");

        var alreadyBound = !string.IsNullOrWhiteSpace(current.ProviderKey) || !string.IsNullOrWhiteSpace(current.ProviderSubscriptionReference);
        if (alreadyBound)
        {
            if (cleanKey is null || cleanReference is null)
                throw new InvalidOperationException("Provider binding cannot be cleared once established.");

            var sameBinding = string.Equals(current.ProviderKey, cleanKey, StringComparison.OrdinalIgnoreCase) &&
                              string.Equals(current.ProviderSubscriptionReference, cleanReference, StringComparison.OrdinalIgnoreCase);
            if (!sameBinding)
                throw new InvalidOperationException("Provider binding cannot be replaced once established.");

            return await innerService.BindProviderReferenceAsync(
                subscriptionId,
                current.ProviderKey,
                current.ProviderSubscriptionReference,
                cancellationToken);
        }

        if (cleanKey is not null && cleanReference is not null)
        {
            var collision = await dbContext.AccountSubscriptions.AsNoTracking()
                .AnyAsync(x =>
                    x.Id != subscriptionId &&
                    x.ProviderKey == cleanKey &&
                    x.ProviderSubscriptionReference == cleanReference,
                    cancellationToken);
            if (collision)
                throw new InvalidOperationException("Provider subscription reference is already bound to another account subscription.");
        }

        return await innerService.BindProviderReferenceAsync(
            subscriptionId,
            cleanKey,
            cleanReference,
            cancellationToken);
    }

    public Task<IReadOnlyList<AccountSubscriptionTransitionItem>> ListTransitionsAsync(Guid subscriptionId, int take = 100, CancellationToken cancellationToken = default) =>
        innerService.ListTransitionsAsync(subscriptionId, take, cancellationToken);
}
