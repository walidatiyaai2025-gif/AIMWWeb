using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Infrastructure.Billing;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class PaymentGatewayAbstractionTests
{
    [Fact]
    public void Descriptor_Normalizes_Key_And_Advertises_Capabilities()
    {
        var descriptor = new PaymentGatewayDescriptor(
            " Gateway-X ",
            "Gateway X",
            PaymentGatewayCapability.SubscriptionCheckout | PaymentGatewayCapability.SubscriptionLookup);

        descriptor.Key.Should().Be("gateway-x");
        descriptor.Supports(PaymentGatewayCapability.SubscriptionCheckout).Should().BeTrue();
        descriptor.Supports(PaymentGatewayCapability.WebhookVerification).Should().BeFalse();
        descriptor.Supports(PaymentGatewayCapability.SubscriptionCheckout | PaymentGatewayCapability.SubscriptionLookup).Should().BeTrue();
    }

    [Theory]
    [InlineData("bad key!")]
    [InlineData("/gateway")]
    [InlineData("")]
    public void Descriptor_Rejects_Invalid_Gateway_Key(string key)
    {
        var action = () => new PaymentGatewayDescriptor(key, "Gateway", PaymentGatewayCapability.SubscriptionLookup);
        action.Should().Throw<ArgumentException>();
    }

    [Fact]
    public void Registry_Is_Empty_Safe_CaseInsensitive_And_Deterministically_Ordered()
    {
        new PaymentGatewayRegistry(Array.Empty<IPaymentGateway>()).List().Should().BeEmpty();

        var zeta = new FakeGateway("zeta", PaymentGatewayCapability.SubscriptionLookup);
        var alpha = new FakeGateway("alpha", PaymentGatewayCapability.SubscriptionCheckout);
        var registry = new PaymentGatewayRegistry(new IPaymentGateway[] { zeta, alpha });

        registry.List().Select(x => x.Key).Should().Equal("alpha", "zeta");
        registry.TryResolve(" ALPHA ", out var resolved).Should().BeTrue();
        resolved.Should().BeSameAs(alpha);
        registry.GetRequired("ZETA").Should().BeSameAs(zeta);
    }

    [Fact]
    public void Registry_Rejects_CaseInsensitive_Duplicate_Keys()
    {
        var action = () => new PaymentGatewayRegistry(new IPaymentGateway[]
        {
            new FakeGateway("gateway-x", PaymentGatewayCapability.SubscriptionLookup),
            new FakeGateway("GATEWAY-X", PaymentGatewayCapability.SubscriptionCheckout)
        });

        action.Should().Throw<InvalidOperationException>().WithMessage("*registered more than once*");
    }

    [Fact]
    public void Registry_Enforces_Required_Capability_Before_Provider_Call()
    {
        var gateway = new FakeGateway("lookup-only", PaymentGatewayCapability.SubscriptionLookup);
        var registry = new PaymentGatewayRegistry(new[] { gateway });

        registry.GetRequired("lookup-only", PaymentGatewayCapability.SubscriptionLookup).Should().BeSameAs(gateway);
        var unsupported = () => registry.GetRequired("lookup-only", PaymentGatewayCapability.SubscriptionCheckout);
        unsupported.Should().Throw<NotSupportedException>().WithMessage("*does not support capability*");
        var missing = () => registry.GetRequired("missing");
        missing.Should().Throw<KeyNotFoundException>();
    }

    [Fact]
    public void Checkout_Is_Explicitly_Navigation_Only_And_Cannot_Carry_Subscription_State()
    {
        var checkout = new GatewayCheckoutSession(
            "session-1",
            new Uri("https://example.test/checkout/session-1"),
            DateTime.UtcNow.AddMinutes(15));

        checkout.Authority.Should().Be(GatewayEvidenceAuthority.NavigationOnly);
        typeof(GatewayCheckoutSession).GetProperty("State").Should().BeNull();
        typeof(GatewayCheckoutSession).GetProperty("SubscriptionState").Should().BeNull();
    }

    [Fact]
    public void Only_Verified_Webhook_And_Provider_Snapshot_Expose_Authoritative_Evidence_Types()
    {
        var occurred = DateTime.UtcNow;
        var gatewayEvent = new GatewayVerifiedEvent(
            "event-1",
            "subscription-1",
            GatewaySubscriptionState.Active,
            occurred,
            "subscription.activated");
        var snapshot = new GatewaySubscriptionSnapshot(
            "subscription-1",
            GatewaySubscriptionState.Active,
            occurred,
            occurred.AddDays(-1),
            occurred.AddMonths(1));

        gatewayEvent.Authority.Should().Be(GatewayEvidenceAuthority.VerifiedWebhook);
        snapshot.Authority.Should().Be(GatewayEvidenceAuthority.ProviderApiSnapshot);
        GatewayWebhookVerificationResult.Verified(gatewayEvent).IsAuthentic.Should().BeTrue();
        GatewayWebhookVerificationResult.Rejected("signature mismatch").Event.Should().BeNull();
    }

    [Fact]
    public void Contracts_Reject_Invalid_Identifiers_Uris_Timestamps_And_Periods()
    {
        var invalidId = () => new GatewayCheckoutRequest(
            Guid.Empty,
            Guid.NewGuid(),
            "provider-plan",
            new Uri("https://example.test/return"),
            new Uri("https://example.test/cancel"),
            "correlation");
        invalidId.Should().Throw<ArgumentException>();

        var invalidUri = () => new GatewayCheckoutSession("session", new Uri("ftp://example.test/session"));
        invalidUri.Should().Throw<ArgumentException>();

        var localTimestamp = DateTime.SpecifyKind(DateTime.UtcNow, DateTimeKind.Unspecified);
        var invalidTime = () => new GatewayVerifiedEvent(
            "event", "subscription", GatewaySubscriptionState.Active, localTimestamp, "event.type");
        invalidTime.Should().Throw<ArgumentException>();

        var invalidRange = () => new GatewaySubscriptionSnapshot(
            "subscription", GatewaySubscriptionState.Active, DateTime.UtcNow,
            DateTime.UtcNow, DateTime.UtcNow.AddMinutes(-1));
        invalidRange.Should().Throw<ArgumentException>();
    }

    private sealed class FakeGateway : IPaymentGateway
    {
        public FakeGateway(string key, PaymentGatewayCapability capabilities)
        {
            Descriptor = new PaymentGatewayDescriptor(key, key, capabilities);
        }

        public PaymentGatewayDescriptor Descriptor { get; }

        public Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(GatewayCheckoutRequest request, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(GatewayWebhookEnvelope envelope, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(string providerSubscriptionReference, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<GatewayCommandResult> CancelSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<GatewayCommandResult> ReactivateSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<GatewayCommandResult> ChangeSubscriptionPlanAsync(GatewayPlanChangeRequest request, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();
    }
}
