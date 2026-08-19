using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;

namespace AIWordPressManager.Web.Services;

public enum AccountBillingCheckoutBlockReason
{
    None = 0,
    NoSubscription = 1,
    SubscriptionExpired = 2,
    CurrentPlanUnavailable = 3,
    ProviderPlanNotConfigured = 4,
    ProviderAlreadyBound = 5
}

public sealed record AccountBillingCheckoutAvailability(
    bool CanStart,
    AccountBillingCheckoutBlockReason BlockReason,
    bool HasProviderBinding,
    string? ProviderKey,
    string? ProviderReferenceDisplay);

public sealed record AccountBillingWorkspaceView(
    Guid OwnerUserId,
    AccountSubscriptionItem? Subscription,
    SubscriptionPlanItem? CurrentPlan,
    IReadOnlyList<SubscriptionPlanItem> AvailablePlans,
    IReadOnlyList<PlanEntitlementItem> Entitlements,
    IReadOnlyList<AccountSubscriptionTransitionItem> Transitions,
    AccountBillingCheckoutAvailability Checkout,
    bool UsageTelemetryAvailable);

public sealed class AccountBillingService(
    CurrentUserContext currentUser,
    IAccountSubscriptionService subscriptionService,
    ISubscriptionPlanCatalog planCatalog,
    IPlanEntitlementCatalog entitlementCatalog,
    IPayPalSubscriptionCheckoutService checkoutService)
{
    public async Task<AccountBillingWorkspaceView> GetAsync(CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.RequireUserId();
        var subscription = await subscriptionService.GetCurrentAsync(ownerUserId, cancellationToken);
        var allPlans = await planCatalog.ListAsync(includeDisabled: true, cancellationToken);
        var availablePlans = allPlans
            .Where(x => x.IsEnabled)
            .OrderBy(x => x.SortOrder)
            .ThenBy(x => x.Code, StringComparer.OrdinalIgnoreCase)
            .ToArray();

        if (subscription is null)
        {
            return new(
                ownerUserId,
                null,
                null,
                availablePlans,
                [],
                [],
                new(false, AccountBillingCheckoutBlockReason.NoSubscription, false, null, null),
                UsageTelemetryAvailable: false);
        }

        if (subscription.OwnerUserId != ownerUserId)
            throw new InvalidOperationException("The subscription service returned a record outside the current account scope.");

        var currentPlan = allPlans.SingleOrDefault(x => x.Id == subscription.PlanId);
        var entitlements = currentPlan is null
            ? Array.Empty<PlanEntitlementItem>()
            : await entitlementCatalog.ListAsync(currentPlan.Id, cancellationToken);
        var transitions = await subscriptionService.ListTransitionsAsync(subscription.Id, 50, cancellationToken);
        var checkout = EvaluateCheckout(subscription, currentPlan);

        return new(
            ownerUserId,
            subscription,
            currentPlan,
            availablePlans,
            entitlements.OrderBy(x => x.Key, StringComparer.OrdinalIgnoreCase).ToArray(),
            transitions.OrderByDescending(x => x.OccurredAtUtc).ThenByDescending(x => x.CreatedAtUtc).ToArray(),
            checkout,
            UsageTelemetryAvailable: false);
    }

    public async Task<GatewayCheckoutSession> CreatePayPalCheckoutAsync(
        Uri returnUri,
        Uri cancelUri,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(returnUri);
        ArgumentNullException.ThrowIfNull(cancelUri);
        RequireNavigationUri(returnUri, nameof(returnUri));
        RequireNavigationUri(cancelUri, nameof(cancelUri));

        var workspace = await GetAsync(cancellationToken);
        if (workspace.Subscription is null || !workspace.Checkout.CanStart)
            throw new InvalidOperationException("The current subscription is not eligible to start PayPal checkout.");

        return await checkoutService.CreateAsync(
            workspace.OwnerUserId,
            workspace.Subscription.Id,
            returnUri,
            cancelUri,
            $"account-billing:{workspace.Subscription.Id:D}",
            cancellationToken);
    }

    private static AccountBillingCheckoutAvailability EvaluateCheckout(
        AccountSubscriptionItem subscription,
        SubscriptionPlanItem? currentPlan)
    {
        var hasBinding = !string.IsNullOrWhiteSpace(subscription.ProviderKey) ||
                         !string.IsNullOrWhiteSpace(subscription.ProviderSubscriptionReference);
        var providerDisplay = MaskProviderReference(subscription.ProviderSubscriptionReference);

        if (hasBinding)
        {
            return new(
                false,
                AccountBillingCheckoutBlockReason.ProviderAlreadyBound,
                true,
                subscription.ProviderKey,
                providerDisplay);
        }

        if (subscription.Status == AccountSubscriptionStatus.Expired)
            return new(false, AccountBillingCheckoutBlockReason.SubscriptionExpired, false, null, null);
        if (currentPlan is null || !currentPlan.IsEnabled)
            return new(false, AccountBillingCheckoutBlockReason.CurrentPlanUnavailable, false, null, null);
        if (string.IsNullOrWhiteSpace(currentPlan.GatewayPlanId))
            return new(false, AccountBillingCheckoutBlockReason.ProviderPlanNotConfigured, false, null, null);

        return new(true, AccountBillingCheckoutBlockReason.None, false, null, null);
    }

    private static string? MaskProviderReference(string? reference)
    {
        var clean = (reference ?? string.Empty).Trim();
        if (clean.Length == 0) return null;
        if (clean.Length <= 8) return clean;
        return $"{clean[..3]}…{clean[^4..]}";
    }

    private static void RequireNavigationUri(Uri uri, string parameterName)
    {
        if (!uri.IsAbsoluteUri ||
            (uri.Scheme != Uri.UriSchemeHttps && uri.Scheme != Uri.UriSchemeHttp))
        {
            throw new ArgumentException("Billing navigation URI must be an absolute HTTP or HTTPS URI.", parameterName);
        }
    }
}
