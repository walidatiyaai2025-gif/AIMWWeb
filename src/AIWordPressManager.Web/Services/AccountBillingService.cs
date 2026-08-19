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

public sealed record AccountBillingMutationAvailability(
    bool IsPayPalBound,
    bool CanChangePlan,
    bool CanCancelPermanently,
    bool CanReactivate,
    bool HardCancellationIsPermanent,
    string? BlockReason);

public sealed record AccountBillingCommandResult(
    bool Accepted,
    string Summary,
    Uri? ApprovalUri,
    bool AwaitingProviderReconciliation)
{
    public bool RequiresUserApproval => ApprovalUri is not null;
}

public sealed record AccountBillingWorkspaceView(
    Guid OwnerUserId,
    AccountSubscriptionItem? Subscription,
    SubscriptionPlanItem? CurrentPlan,
    IReadOnlyList<SubscriptionPlanItem> AvailablePlans,
    IReadOnlyList<PlanEntitlementItem> Entitlements,
    IReadOnlyList<AccountSubscriptionTransitionItem> Transitions,
    IReadOnlyList<AccountSubscriptionPlanChangeItem> PlanChanges,
    AccountBillingCheckoutAvailability Checkout,
    AccountBillingMutationAvailability Mutations,
    bool UsageTelemetryAvailable,
    IReadOnlyList<AccountBillingHistoryItem>? History = null);

public sealed class AccountBillingService(
    CurrentUserContext currentUser,
    IAccountSubscriptionService subscriptionService,
    ISubscriptionPlanCatalog planCatalog,
    IPlanEntitlementCatalog entitlementCatalog,
    IPayPalSubscriptionCheckoutService checkoutService,
    IPaymentGatewayRegistry paymentGatewayRegistry)
{
    private const string PayPalGatewayKey = "paypal";

    public AccountBillingService(
        CurrentUserContext currentUser,
        IAccountSubscriptionService subscriptionService,
        ISubscriptionPlanCatalog planCatalog,
        IPlanEntitlementCatalog entitlementCatalog,
        IPayPalSubscriptionCheckoutService checkoutService)
        : this(
            currentUser,
            subscriptionService,
            planCatalog,
            entitlementCatalog,
            checkoutService,
            UnavailablePaymentGatewayRegistry.Instance)
    {
    }

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
                [],
                new(false, AccountBillingCheckoutBlockReason.NoSubscription, false, null, null),
                new(false, false, false, false, true, "No current subscription is available."),
                UsageTelemetryAvailable: false,
                History: []);
        }

        RequireOwner(subscription, ownerUserId);

        var currentPlan = allPlans.SingleOrDefault(x => x.Id == subscription.PlanId);
        var entitlements = currentPlan is null
            ? Array.Empty<PlanEntitlementItem>()
            : await entitlementCatalog.ListAsync(currentPlan.Id, cancellationToken);
        var transitions = await subscriptionService.ListTransitionsAsync(subscription.Id, 50, cancellationToken);
        var planChanges = await subscriptionService.ListPlanChangesAsync(subscription.Id, 50, cancellationToken);
        var history = await subscriptionService.ListBillingHistoryAsync(ownerUserId, subscription.Id, 100, cancellationToken);
        var checkout = EvaluateCheckout(subscription, currentPlan);
        var mutations = EvaluateMutations(subscription);

        return new(
            ownerUserId,
            subscription,
            currentPlan,
            availablePlans,
            entitlements.OrderBy(x => x.Key, StringComparer.OrdinalIgnoreCase).ToArray(),
            transitions.OrderByDescending(x => x.OccurredAtUtc).ThenByDescending(x => x.CreatedAtUtc).ToArray(),
            planChanges.OrderByDescending(x => x.OccurredAtUtc).ThenByDescending(x => x.CreatedAtUtc).ToArray(),
            checkout,
            mutations,
            UsageTelemetryAvailable: false,
            History: history);
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

    public async Task<AccountBillingCommandResult> ChangePlanAsync(
        Guid targetPlanId,
        CancellationToken cancellationToken = default)
    {
        if (targetPlanId == Guid.Empty) throw new ArgumentException("Target plan ID is required.", nameof(targetPlanId));
        var ownerUserId = currentUser.RequireUserId();
        var subscription = await RequireCurrentSubscriptionAsync(ownerUserId, cancellationToken);
        var binding = RequirePayPalBinding(subscription);

        if (subscription.Status is not (AccountSubscriptionStatus.Active or AccountSubscriptionStatus.Suspended))
            throw new InvalidOperationException("PayPal plan changes are available only for active or suspended subscriptions.");
        if (subscription.PlanId == targetPlanId)
            throw new InvalidOperationException("The selected plan is already the current subscription plan.");

        var plans = await planCatalog.ListAsync(includeDisabled: true, cancellationToken);
        var target = plans.SingleOrDefault(x => x.Id == targetPlanId)
            ?? throw new KeyNotFoundException("Target subscription plan was not found.");
        if (!target.IsEnabled)
            throw new InvalidOperationException("The selected subscription plan is not enabled for new changes.");
        if (string.IsNullOrWhiteSpace(target.GatewayPlanId))
            throw new InvalidOperationException("The selected subscription plan does not have a PayPal plan mapping.");

        var gateway = paymentGatewayRegistry.GetRequired(PayPalGatewayKey, PaymentGatewayCapability.ChangeSubscriptionPlan);
        var command = new GatewayPlanChangeRequest(
            binding,
            target.GatewayPlanId,
            $"billing-change:{subscription.Id:N}:{targetPlanId:N}");
        var providerResult = await gateway.ChangeSubscriptionPlanAsync(command, cancellationToken);
        return RequireAccepted(providerResult);
    }

    public async Task<AccountBillingCommandResult> CancelPermanentlyAsync(CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.RequireUserId();
        var subscription = await RequireCurrentSubscriptionAsync(ownerUserId, cancellationToken);
        var binding = RequirePayPalBinding(subscription);
        if (subscription.Status is AccountSubscriptionStatus.Cancelled or AccountSubscriptionStatus.Expired)
            throw new InvalidOperationException("The subscription is already cancelled or expired.");

        var gateway = paymentGatewayRegistry.GetRequired(PayPalGatewayKey, PaymentGatewayCapability.CancelSubscription);
        var providerResult = await gateway.CancelSubscriptionAsync(
            new GatewaySubscriptionCommandRequest(binding, $"billing-cancel:{subscription.Id:N}"),
            cancellationToken);
        return RequireAccepted(providerResult);
    }

    public async Task<AccountBillingCommandResult> ReactivateAsync(CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.RequireUserId();
        var subscription = await RequireCurrentSubscriptionAsync(ownerUserId, cancellationToken);
        var binding = RequirePayPalBinding(subscription);
        if (subscription.Status == AccountSubscriptionStatus.Cancelled)
            throw new InvalidOperationException("A permanently cancelled PayPal subscription cannot be reactivated. Start a new subscription instead.");
        if (subscription.Status != AccountSubscriptionStatus.Suspended)
            throw new InvalidOperationException("Only a suspended PayPal subscription can be reactivated.");

        var gateway = paymentGatewayRegistry.GetRequired(PayPalGatewayKey, PaymentGatewayCapability.ReactivateSubscription);
        var providerResult = await gateway.ReactivateSubscriptionAsync(
            new GatewaySubscriptionCommandRequest(binding, $"billing-reactivate:{subscription.Id:N}"),
            cancellationToken);
        return RequireAccepted(providerResult);
    }

    private async Task<AccountSubscriptionItem> RequireCurrentSubscriptionAsync(Guid ownerUserId, CancellationToken cancellationToken)
    {
        var subscription = await subscriptionService.GetCurrentAsync(ownerUserId, cancellationToken)
            ?? throw new InvalidOperationException("The current account does not have a subscription record.");
        RequireOwner(subscription, ownerUserId);
        return subscription;
    }

    private static string RequirePayPalBinding(AccountSubscriptionItem subscription)
    {
        if (!string.Equals(subscription.ProviderKey, PayPalGatewayKey, StringComparison.OrdinalIgnoreCase) ||
            string.IsNullOrWhiteSpace(subscription.ProviderSubscriptionReference))
        {
            throw new InvalidOperationException("The current subscription is not bound to a PayPal subscription reference.");
        }

        return subscription.ProviderSubscriptionReference.Trim();
    }

    private static AccountBillingCommandResult RequireAccepted(GatewayCommandResult result)
    {
        ArgumentNullException.ThrowIfNull(result);
        if (!result.Accepted)
            throw new InvalidOperationException(result.SanitizedSummary);
        return new(
            true,
            result.SanitizedSummary,
            result.ApprovalUri,
            AwaitingProviderReconciliation: true);
    }

    private static void RequireOwner(AccountSubscriptionItem subscription, Guid ownerUserId)
    {
        if (subscription.OwnerUserId != ownerUserId)
            throw new InvalidOperationException("The subscription service returned a record outside the current account scope.");
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

    private static AccountBillingMutationAvailability EvaluateMutations(AccountSubscriptionItem subscription)
    {
        var paypalBound = string.Equals(subscription.ProviderKey, PayPalGatewayKey, StringComparison.OrdinalIgnoreCase) &&
                          !string.IsNullOrWhiteSpace(subscription.ProviderSubscriptionReference);
        if (!paypalBound)
            return new(false, false, false, false, true, "Subscription changes require a bound PayPal subscription.");

        var canChangePlan = subscription.Status is AccountSubscriptionStatus.Active or AccountSubscriptionStatus.Suspended;
        var canCancel = subscription.Status is not (AccountSubscriptionStatus.Cancelled or AccountSubscriptionStatus.Expired);
        var canReactivate = subscription.Status == AccountSubscriptionStatus.Suspended;
        return new(
            true,
            canChangePlan,
            canCancel,
            canReactivate,
            true,
            canChangePlan || canCancel || canReactivate ? null : "No provider mutation is available for the current subscription state.");
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

    private sealed class UnavailablePaymentGatewayRegistry : IPaymentGatewayRegistry
    {
        public static readonly UnavailablePaymentGatewayRegistry Instance = new();
        public IReadOnlyList<PaymentGatewayDescriptor> List() => [];
        public bool TryResolve(string gatewayKey, out IPaymentGateway gateway)
        {
            gateway = null!;
            return false;
        }
        public IPaymentGateway GetRequired(string gatewayKey) =>
            throw new InvalidOperationException("No payment gateway registry was supplied for this billing operation.");
        public IPaymentGateway GetRequired(string gatewayKey, PaymentGatewayCapability requiredCapability) =>
            throw new InvalidOperationException("No payment gateway registry was supplied for this billing operation.");
    }
}
