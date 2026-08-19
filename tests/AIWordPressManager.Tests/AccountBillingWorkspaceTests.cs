using System.Security.Claims;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class AccountBillingWorkspaceTests
{
    [Fact]
    public async Task Workspace_Composes_Current_Subscription_Plan_Entitlements_And_Transitions_For_Authenticated_Owner()
    {
        var now = DateTime.UtcNow;
        var owner = Guid.NewGuid();
        var planId = Guid.NewGuid();
        var subscriptionId = Guid.NewGuid();
        var currentPlan = Plan(planId, "pro", enabled: true, gatewayPlanId: "P-PRO", sortOrder: 2, now);
        var starter = Plan(Guid.NewGuid(), "starter", enabled: true, gatewayPlanId: "P-START", sortOrder: 1, now);
        var disabled = Plan(Guid.NewGuid(), "legacy", enabled: false, gatewayPlanId: null, sortOrder: 0, now);
        var subscription = Subscription(subscriptionId, owner, planId, AccountSubscriptionStatus.Active, now);
        var transition = new AccountSubscriptionTransitionItem(
            Guid.NewGuid(), subscriptionId, AccountSubscriptionStatus.Trialing, AccountSubscriptionStatus.Active,
            SubscriptionTransitionSource.Provider, "Verified provider reconciliation.", now.AddMinutes(-5), now.AddMinutes(-5), now.AddMinutes(-5));
        var entitlement = new PlanEntitlementItem(
            Guid.NewGuid(), planId, EntitlementDefinitionCatalog.SitesMax, EntitlementValueType.Integer, "5", now, now);

        var subscriptions = new FakeSubscriptionService(subscription, [transition]);
        var service = CreateService(
            owner,
            subscriptions,
            new FakePlanCatalog([currentPlan, starter, disabled]),
            new FakeEntitlementCatalog([entitlement]),
            new FakeCheckoutService());

        var workspace = await service.GetAsync();

        subscriptions.RequestedOwnerUserId.Should().Be(owner);
        workspace.OwnerUserId.Should().Be(owner);
        workspace.Subscription.Should().Be(subscription);
        workspace.CurrentPlan.Should().Be(currentPlan);
        workspace.AvailablePlans.Select(x => x.Code).Should().Equal("starter", "pro");
        workspace.AvailablePlans.Should().NotContain(x => x.Code == "legacy");
        workspace.Entitlements.Should().ContainSingle(x => x.Key == EntitlementDefinitionCatalog.SitesMax && x.CanonicalValue == "5");
        workspace.Transitions.Should().ContainSingle(x => x.Id == transition.Id);
        workspace.PlanChanges.Should().BeEmpty();
        workspace.Checkout.CanStart.Should().BeTrue();
        workspace.Mutations.IsPayPalBound.Should().BeFalse();
        workspace.UsageTelemetryAvailable.Should().BeFalse();
    }

    [Fact]
    public async Task Provider_Binding_Is_Masked_Blocks_New_Checkout_And_Enables_Plan_Changes()
    {
        var now = DateTime.UtcNow;
        var owner = Guid.NewGuid();
        var planId = Guid.NewGuid();
        var subscription = Subscription(
            Guid.NewGuid(), owner, planId, AccountSubscriptionStatus.Active, now,
            providerKey: "paypal", providerReference: "I-1234567890");
        var checkout = new FakeCheckoutService();
        var service = CreateService(
            owner,
            new FakeSubscriptionService(subscription, []),
            new FakePlanCatalog([Plan(planId, "pro", true, "P-PRO", 1, now)]),
            new FakeEntitlementCatalog([]),
            checkout);

        var workspace = await service.GetAsync();

        workspace.Checkout.CanStart.Should().BeFalse();
        workspace.Checkout.BlockReason.Should().Be(AccountBillingCheckoutBlockReason.ProviderAlreadyBound);
        workspace.Checkout.ProviderKey.Should().Be("paypal");
        workspace.Checkout.ProviderReferenceDisplay.Should().Be("I-1…7890");
        workspace.Checkout.ProviderReferenceDisplay.Should().NotContain("1234567890");
        workspace.Mutations.IsPayPalBound.Should().BeTrue();
        workspace.Mutations.CanChangePlan.Should().BeTrue();
        workspace.Mutations.CanCancelPermanently.Should().BeTrue();

        var action = async () => await service.CreatePayPalCheckoutAsync(
            new Uri("https://example.test/account/billing?checkout=returned"),
            new Uri("https://example.test/account/billing?checkout=cancelled"));

        await action.Should().ThrowAsync<InvalidOperationException>();
        checkout.CallCount.Should().Be(0);
    }

    [Fact]
    public async Task Checkout_Revalidates_Server_Side_Uses_Deterministic_Correlation_And_Does_Not_Transition_Status()
    {
        var now = DateTime.UtcNow;
        var owner = Guid.NewGuid();
        var planId = Guid.NewGuid();
        var subscriptionId = Guid.NewGuid();
        var subscription = Subscription(subscriptionId, owner, planId, AccountSubscriptionStatus.Trialing, now);
        var subscriptions = new FakeSubscriptionService(subscription, []);
        var checkout = new FakeCheckoutService();
        var service = CreateService(
            owner,
            subscriptions,
            new FakePlanCatalog([Plan(planId, "pro", true, "P-PRO", 1, now)]),
            new FakeEntitlementCatalog([]),
            checkout);

        var session = await service.CreatePayPalCheckoutAsync(
            new Uri("http://localhost/account/billing?checkout=returned"),
            new Uri("http://localhost/account/billing?checkout=cancelled"));

        session.ProviderSessionReference.Should().Be("I-CHECKOUT");
        checkout.CallCount.Should().Be(1);
        checkout.OwnerUserId.Should().Be(owner);
        checkout.SubscriptionId.Should().Be(subscriptionId);
        checkout.CorrelationId.Should().Be($"account-billing:{subscriptionId:D}");
        subscriptions.TransitionCallCount.Should().Be(0);
        subscriptions.PlanChangeCallCount.Should().Be(0);
        subscription.Status.Should().Be(AccountSubscriptionStatus.Trialing);
    }

    [Fact]
    public async Task Plan_Change_Command_Does_Not_Mutate_Local_Plan_Before_Provider_Reconciliation()
    {
        var now = DateTime.UtcNow;
        var owner = Guid.NewGuid();
        var currentPlanId = Guid.NewGuid();
        var targetPlanId = Guid.NewGuid();
        var subscription = Subscription(Guid.NewGuid(), owner, currentPlanId, AccountSubscriptionStatus.Active, now, "paypal", "I-BOUND1234");
        var subscriptions = new FakeSubscriptionService(subscription, []);
        var gateway = new FakeGateway
        {
            PlanChangeResult = GatewayCommandResult.AcceptedResult(
                "I-BOUND1234",
                "PayPal requires approval.",
                new Uri("https://www.paypal.com/approve?token=change"))
        };
        var service = CreateService(
            owner,
            subscriptions,
            new FakePlanCatalog([
                Plan(currentPlanId, "pro", true, "P-PRO", 1, now),
                Plan(targetPlanId, "premium", true, "P-PREMIUM", 2, now)]),
            new FakeEntitlementCatalog([]),
            new FakeCheckoutService(),
            new FakeGatewayRegistry(gateway));

        var result = await service.ChangePlanAsync(targetPlanId);

        result.Accepted.Should().BeTrue();
        result.RequiresUserApproval.Should().BeTrue();
        result.AwaitingProviderReconciliation.Should().BeTrue();
        gateway.PlanChangeCalls.Should().Be(1);
        gateway.LastPlanChange!.ProviderSubscriptionReference.Should().Be("I-BOUND1234");
        gateway.LastPlanChange.TargetProviderPlanReference.Should().Be("P-PREMIUM");
        subscriptions.PlanChangeCallCount.Should().Be(0);
        subscriptions.TransitionCallCount.Should().Be(0);
        subscription.PlanId.Should().Be(currentPlanId);
    }

    [Fact]
    public async Task Same_Disabled_And_Unmapped_Target_Plans_Are_Rejected_Before_Provider_Call()
    {
        var now = DateTime.UtcNow;
        var owner = Guid.NewGuid();
        var currentPlanId = Guid.NewGuid();
        var disabledPlanId = Guid.NewGuid();
        var unmappedPlanId = Guid.NewGuid();
        var subscription = Subscription(Guid.NewGuid(), owner, currentPlanId, AccountSubscriptionStatus.Active, now, "paypal", "I-BOUND1234");
        var gateway = new FakeGateway();
        var service = CreateService(
            owner,
            new FakeSubscriptionService(subscription, []),
            new FakePlanCatalog([
                Plan(currentPlanId, "pro", true, "P-PRO", 1, now),
                Plan(disabledPlanId, "legacy", false, "P-LEGACY", 2, now),
                Plan(unmappedPlanId, "manual", true, null, 3, now)]),
            new FakeEntitlementCatalog([]),
            new FakeCheckoutService(),
            new FakeGatewayRegistry(gateway));

        await FluentActions.Invoking(() => service.ChangePlanAsync(currentPlanId)).Should().ThrowAsync<InvalidOperationException>();
        await FluentActions.Invoking(() => service.ChangePlanAsync(disabledPlanId)).Should().ThrowAsync<InvalidOperationException>();
        await FluentActions.Invoking(() => service.ChangePlanAsync(unmappedPlanId)).Should().ThrowAsync<InvalidOperationException>();
        gateway.PlanChangeCalls.Should().Be(0);
    }

    [Fact]
    public async Task Hard_Cancellation_Is_Not_Treated_As_Reactivatable_While_Suspended_Subscriptions_Can_Request_Activation()
    {
        var now = DateTime.UtcNow;
        var owner = Guid.NewGuid();
        var planId = Guid.NewGuid();
        var gateway = new FakeGateway
        {
            ReactivateResult = GatewayCommandResult.AcceptedResult("I-SUSPENDED", "Accepted.")
        };
        var suspended = Subscription(Guid.NewGuid(), owner, planId, AccountSubscriptionStatus.Suspended, now, "paypal", "I-SUSPENDED");
        var suspendedService = CreateService(
            owner,
            new FakeSubscriptionService(suspended, []),
            new FakePlanCatalog([Plan(planId, "pro", true, "P-PRO", 1, now)]),
            new FakeEntitlementCatalog([]),
            new FakeCheckoutService(),
            new FakeGatewayRegistry(gateway));

        var result = await suspendedService.ReactivateAsync();
        result.AwaitingProviderReconciliation.Should().BeTrue();
        gateway.ReactivateCalls.Should().Be(1);

        var cancelled = Subscription(Guid.NewGuid(), owner, planId, AccountSubscriptionStatus.Cancelled, now, "paypal", "I-CANCELLED");
        var cancelledService = CreateService(
            owner,
            new FakeSubscriptionService(cancelled, []),
            new FakePlanCatalog([Plan(planId, "pro", true, "P-PRO", 1, now)]),
            new FakeEntitlementCatalog([]),
            new FakeCheckoutService(),
            new FakeGatewayRegistry(gateway));
        await FluentActions.Invoking(() => cancelledService.ReactivateAsync())
            .Should().ThrowAsync<InvalidOperationException>().WithMessage("*permanently cancelled*");
    }

    [Fact]
    public async Task Workspace_Rejects_A_Subscription_Outside_The_Current_Account_And_Navigation_Is_Discoverable()
    {
        var now = DateTime.UtcNow;
        var currentOwner = Guid.NewGuid();
        var foreignOwner = Guid.NewGuid();
        var planId = Guid.NewGuid();
        var service = CreateService(
            currentOwner,
            new FakeSubscriptionService(Subscription(Guid.NewGuid(), foreignOwner, planId, AccountSubscriptionStatus.Active, now), []),
            new FakePlanCatalog([Plan(planId, "pro", true, "P-PRO", 1, now)]),
            new FakeEntitlementCatalog([]),
            new FakeCheckoutService());

        var action = async () => await service.GetAsync();
        await action.Should().ThrowAsync<InvalidOperationException>().WithMessage("*outside the current account scope*");

        var navigation = AppNavigationCatalog.FindItem("/account/billing", isAdministrator: false);
        navigation.Should().NotBeNull();
        navigation!.ShowInSidebar.Should().BeTrue();
        navigation.MatchesSearch("billing").Should().BeTrue();
        navigation.MatchesSearch("فوترة").Should().BeTrue();
    }

    private static AccountBillingService CreateService(
        Guid owner,
        IAccountSubscriptionService subscriptions,
        ISubscriptionPlanCatalog plans,
        IPlanEntitlementCatalog entitlements,
        IPayPalSubscriptionCheckoutService checkout,
        IPaymentGatewayRegistry? gatewayRegistry = null)
    {
        var http = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(
                [new Claim(ClaimTypes.NameIdentifier, owner.ToString())], "Test"))
        };
        var currentUser = new CurrentUserContext(new HttpContextAccessor { HttpContext = http });
        return gatewayRegistry is null
            ? new AccountBillingService(currentUser, subscriptions, plans, entitlements, checkout)
            : new AccountBillingService(currentUser, subscriptions, plans, entitlements, checkout, gatewayRegistry);
    }

    private static SubscriptionPlanItem Plan(Guid id, string code, bool enabled, string? gatewayPlanId, int sortOrder, DateTime now) =>
        new(id, code, code.ToUpperInvariant(), $"{code}-ar", $"{code} description", $"{code} ar description",
            SubscriptionPlan.MonthlyInterval, 19.99m, "USD", 7, 5, enabled, sortOrder, null, gatewayPlanId, now, now);

    private static AccountSubscriptionItem Subscription(Guid id, Guid owner, Guid planId, AccountSubscriptionStatus status, DateTime now, string? providerKey = null, string? providerReference = null) =>
        new(id, owner, planId, status, now.AddDays(-7), now.AddDays(7), now, now.AddMonths(1), false, null, null, null, null,
            providerKey, providerReference, null, now, now);

    private sealed class FakeSubscriptionService(AccountSubscriptionItem? current, IReadOnlyList<AccountSubscriptionTransitionItem> transitions) : IAccountSubscriptionService
    {
        public Guid? RequestedOwnerUserId { get; private set; }
        public int TransitionCallCount { get; private set; }
        public int PlanChangeCallCount { get; private set; }

        public Task<AccountSubscriptionItem?> GetCurrentAsync(Guid ownerUserId, CancellationToken cancellationToken = default)
        {
            RequestedOwnerUserId = ownerUserId;
            return Task.FromResult(current);
        }

        public Task<IReadOnlyList<AccountSubscriptionTransitionItem>> ListTransitionsAsync(Guid subscriptionId, int take = 100, CancellationToken cancellationToken = default) => Task.FromResult(transitions);
        public Task<IReadOnlyList<AccountSubscriptionPlanChangeItem>> ListPlanChangesAsync(Guid subscriptionId, int take = 100, CancellationToken cancellationToken = default) => Task.FromResult<IReadOnlyList<AccountSubscriptionPlanChangeItem>>([]);

        public Task<AccountSubscriptionTransitionResult> TransitionAsync(Guid subscriptionId, AccountSubscriptionTransitionRequest request, CancellationToken cancellationToken = default)
        {
            TransitionCallCount++;
            throw new NotSupportedException();
        }

        public Task<AccountSubscriptionPlanChangeResult> ChangePlanAsync(Guid subscriptionId, AccountSubscriptionPlanChangeRequest request, CancellationToken cancellationToken = default)
        {
            PlanChangeCallCount++;
            throw new NotSupportedException();
        }

        public Task<AccountSubscriptionItem> CreateAsync(AccountSubscriptionCreateRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<AccountSubscriptionItem> UpdatePeriodsAsync(Guid subscriptionId, SubscriptionPeriodUpdateRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<AccountSubscriptionItem> SetCancelAtPeriodEndAsync(Guid subscriptionId, bool cancelAtPeriodEnd, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<AccountSubscriptionItem> BindProviderReferenceAsync(Guid subscriptionId, string? providerKey, string? providerSubscriptionReference, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }

    private sealed class FakePlanCatalog(IReadOnlyList<SubscriptionPlanItem> plans) : ISubscriptionPlanCatalog
    {
        public Task<IReadOnlyList<SubscriptionPlanItem>> ListAsync(bool includeDisabled = false, CancellationToken cancellationToken = default) => Task.FromResult<IReadOnlyList<SubscriptionPlanItem>>(includeDisabled ? plans : plans.Where(x => x.IsEnabled).ToArray());
        public Task<SubscriptionPlanItem?> GetByCodeAsync(string code, bool includeDisabled = false, CancellationToken cancellationToken = default) => Task.FromResult(plans.FirstOrDefault(x => string.Equals(x.Code, code, StringComparison.OrdinalIgnoreCase) && (includeDisabled || x.IsEnabled)));
        public Task<SubscriptionPlanItem> CreateAsync(SubscriptionPlanCreateRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<SubscriptionPlanItem> UpdateAsync(Guid planId, SubscriptionPlanUpdateRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<SubscriptionPlanItem> SetEnabledAsync(Guid planId, bool enabled, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }

    private sealed class FakeEntitlementCatalog(IReadOnlyList<PlanEntitlementItem> items) : IPlanEntitlementCatalog
    {
        public IReadOnlyList<EntitlementDefinitionItem> ListDefinitions() => EntitlementDefinitionCatalog.All.Select(x => new EntitlementDefinitionItem(x.Key, x.ValueType, x.RequiresNonNegativeNumber)).ToArray();
        public Task<IReadOnlyList<PlanEntitlementItem>> ListAsync(Guid planId, CancellationToken cancellationToken = default) => Task.FromResult<IReadOnlyList<PlanEntitlementItem>>(items.Where(x => x.PlanId == planId).ToArray());
        public Task<PlanEntitlementItem> SetAsync(Guid planId, string key, string? rawValue, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<bool> RemoveAsync(Guid planId, string key, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }

    private sealed class FakeCheckoutService : IPayPalSubscriptionCheckoutService
    {
        public int CallCount { get; private set; }
        public Guid OwnerUserId { get; private set; }
        public Guid SubscriptionId { get; private set; }
        public string? CorrelationId { get; private set; }

        public Task<GatewayCheckoutSession> CreateAsync(Guid ownerUserId, Guid accountSubscriptionId, Uri returnUri, Uri cancelUri, string correlationId, CancellationToken cancellationToken = default)
        {
            CallCount++;
            OwnerUserId = ownerUserId;
            SubscriptionId = accountSubscriptionId;
            CorrelationId = correlationId;
            return Task.FromResult(new GatewayCheckoutSession("I-CHECKOUT", new Uri("https://www.paypal.com/checkoutnow?token=I-CHECKOUT")));
        }
    }

    private sealed class FakeGatewayRegistry(IPaymentGateway gateway) : IPaymentGatewayRegistry
    {
        public IReadOnlyList<PaymentGatewayDescriptor> List() => [gateway.Descriptor];
        public bool TryResolve(string gatewayKey, out IPaymentGateway resolved)
        {
            resolved = gateway;
            return string.Equals(gatewayKey, gateway.Descriptor.Key, StringComparison.OrdinalIgnoreCase);
        }
        public IPaymentGateway GetRequired(string gatewayKey) => TryResolve(gatewayKey, out var resolved) ? resolved : throw new KeyNotFoundException();
        public IPaymentGateway GetRequired(string gatewayKey, PaymentGatewayCapability requiredCapability)
        {
            var resolved = GetRequired(gatewayKey);
            if (!resolved.Descriptor.Supports(requiredCapability)) throw new InvalidOperationException("Required gateway capability is not available.");
            return resolved;
        }
    }

    private sealed class FakeGateway : IPaymentGateway
    {
        public PaymentGatewayDescriptor Descriptor { get; } = new("paypal", "PayPal", PaymentGatewayCapability.SubscriptionCheckout | PaymentGatewayCapability.SubscriptionLookup | PaymentGatewayCapability.CancelSubscription | PaymentGatewayCapability.ReactivateSubscription | PaymentGatewayCapability.ChangeSubscriptionPlan);
        public int PlanChangeCalls { get; private set; }
        public int CancelCalls { get; private set; }
        public int ReactivateCalls { get; private set; }
        public GatewayPlanChangeRequest? LastPlanChange { get; private set; }
        public GatewayCommandResult PlanChangeResult { get; set; } = GatewayCommandResult.AcceptedResult("I-TEST", "Accepted.");
        public GatewayCommandResult CancelResult { get; set; } = GatewayCommandResult.AcceptedResult("I-TEST", "Accepted.");
        public GatewayCommandResult ReactivateResult { get; set; } = GatewayCommandResult.AcceptedResult("I-TEST", "Accepted.");

        public Task<GatewayCommandResult> ChangeSubscriptionPlanAsync(GatewayPlanChangeRequest request, CancellationToken cancellationToken = default) { PlanChangeCalls++; LastPlanChange = request; return Task.FromResult(PlanChangeResult); }
        public Task<GatewayCommandResult> CancelSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) { CancelCalls++; return Task.FromResult(CancelResult); }
        public Task<GatewayCommandResult> ReactivateSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) { ReactivateCalls++; return Task.FromResult(ReactivateResult); }
        public Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(GatewayCheckoutRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(GatewayWebhookEnvelope envelope, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(string providerSubscriptionReference, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }
}
