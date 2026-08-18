using System.Net;
using System.Text;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Infrastructure.Billing;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class PayPalSubscriptionLookupTests
{
    [Fact]
    public async Task Lifecycle_Gateway_Uses_Authoritative_Subscription_Lookup_And_Maps_Billing_Period()
    {
        var lookupHandler = new RecordingSequenceHandler(
            Json(HttpStatusCode.OK, "{\"access_token\":\"lookup-token\"}"),
            Json(HttpStatusCode.OK, "{\"id\":\"I-SUB-LOOKUP\",\"plan_id\":\"P-PLAN-LOOKUP\",\"status\":\"ACTIVE\",\"billing_info\":{\"last_payment\":{\"time\":\"2026-08-01T00:00:00Z\"},\"next_billing_time\":\"2026-09-01T00:00:00Z\"}}"));
        using var lookupClient = new HttpClient(lookupHandler);
        using var innerClient = new HttpClient(new ThrowingHandler());
        var runtime = new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "secret");
        var inner = new PayPalPaymentGateway(innerClient, runtime);
        var gateway = new PayPalLifecyclePaymentGateway(inner, lookupClient, runtime);

        var snapshot = await gateway.GetSubscriptionAsync("I-SUB-LOOKUP");

        gateway.Descriptor.Supports(PaymentGatewayCapability.SubscriptionCheckout).Should().BeTrue();
        gateway.Descriptor.Supports(PaymentGatewayCapability.WebhookVerification).Should().BeTrue();
        gateway.Descriptor.Supports(PaymentGatewayCapability.SubscriptionLookup).Should().BeTrue();
        snapshot.Authority.Should().Be(GatewayEvidenceAuthority.ProviderApiSnapshot);
        snapshot.State.Should().Be(GatewaySubscriptionState.Active);
        snapshot.CurrentPeriodStartUtc.Should().Be(new DateTime(2026, 8, 1, 0, 0, 0, DateTimeKind.Utc));
        snapshot.CurrentPeriodEndsAtUtc.Should().Be(new DateTime(2026, 9, 1, 0, 0, 0, DateTimeKind.Utc));
        snapshot.ObservedAtUtc.Kind.Should().Be(DateTimeKind.Utc);

        lookupHandler.Requests.Should().HaveCount(2);
        lookupHandler.Requests[0].Method.Should().Be(HttpMethod.Post);
        lookupHandler.Requests[0].Uri.Should().Be(new Uri("https://api-m.sandbox.paypal.com/v1/oauth2/token"));
        lookupHandler.Requests[1].Method.Should().Be(HttpMethod.Get);
        lookupHandler.Requests[1].Uri.Should().Be(new Uri("https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB-LOOKUP"));
        lookupHandler.Requests[1].AuthorizationScheme.Should().Be("Bearer");
        lookupHandler.Requests[1].AuthorizationParameter.Should().Be("lookup-token");
    }

    [Theory]
    [InlineData("APPROVAL_PENDING", GatewaySubscriptionState.Pending)]
    [InlineData("APPROVED", GatewaySubscriptionState.Pending)]
    [InlineData("SUSPENDED", GatewaySubscriptionState.Suspended)]
    [InlineData("CANCELLED", GatewaySubscriptionState.Cancelled)]
    [InlineData("EXPIRED", GatewaySubscriptionState.Expired)]
    public async Task Lifecycle_Gateway_Normalizes_Provider_Status(string providerStatus, GatewaySubscriptionState expected)
    {
        var handler = new RecordingSequenceHandler(
            Json(HttpStatusCode.OK, "{\"access_token\":\"token\"}"),
            Json(HttpStatusCode.OK, $"{{\"id\":\"I-STATUS\",\"plan_id\":\"P-STATUS\",\"status\":\"{providerStatus}\"}}"));
        using var client = new HttpClient(handler);
        using var innerClient = new HttpClient(new ThrowingHandler());
        var runtime = new FakeRuntimeConfigurationProvider(PayPalEnvironment.Live, "client", "secret");
        var gateway = new PayPalLifecyclePaymentGateway(new PayPalPaymentGateway(innerClient, runtime), client, runtime);

        var snapshot = await gateway.GetSubscriptionAsync("I-STATUS");

        snapshot.State.Should().Be(expected);
        handler.Requests[1].Uri.Should().Be(new Uri("https://api-m.paypal.com/v1/billing/subscriptions/I-STATUS"));
    }

    [Fact]
    public async Task Lifecycle_Gateway_Rejects_Mismatched_Or_Failed_Lookup_Without_Leaking_Provider_Body()
    {
        var mismatch = new RecordingSequenceHandler(
            Json(HttpStatusCode.OK, "{\"access_token\":\"token\"}"),
            Json(HttpStatusCode.OK, "{\"id\":\"I-OTHER\",\"plan_id\":\"P-PLAN\",\"status\":\"ACTIVE\"}"));
        using (var client = new HttpClient(mismatch))
        using (var innerClient = new HttpClient(new ThrowingHandler()))
        {
            var runtime = new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "secret");
            var gateway = new PayPalLifecyclePaymentGateway(new PayPalPaymentGateway(innerClient, runtime), client, runtime);
            var action = () => gateway.GetSubscriptionAsync("I-EXPECTED");
            await action.Should().ThrowAsync<InvalidOperationException>().WithMessage("*different subscription ID*");
        }

        var failed = new RecordingSequenceHandler(
            Json(HttpStatusCode.OK, "{\"access_token\":\"access-token-secret\"}"),
            new HttpResponseMessage(HttpStatusCode.ServiceUnavailable)
            {
                Content = new StringContent("provider body access-token-secret client-secret", Encoding.UTF8, "text/plain")
            });
        using (var client = new HttpClient(failed))
        using (var innerClient = new HttpClient(new ThrowingHandler()))
        {
            var runtime = new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "client-secret");
            var gateway = new PayPalLifecyclePaymentGateway(new PayPalPaymentGateway(innerClient, runtime), client, runtime);
            var action = () => gateway.GetSubscriptionAsync("I-FAILED");
            var exception = await action.Should().ThrowAsync<InvalidOperationException>();
            exception.Which.Message.Should().Contain("HTTP 503");
            exception.Which.Message.Should().NotContain("provider body");
            exception.Which.Message.Should().NotContain("access-token-secret");
            exception.Which.Message.Should().NotContain("client-secret");
        }
    }

    private static HttpResponseMessage Json(HttpStatusCode status, string body) => new(status)
    {
        Content = new StringContent(body, Encoding.UTF8, "application/json")
    };

    private sealed class FakeRuntimeConfigurationProvider(
        PayPalEnvironment environment,
        string clientId,
        string clientSecret) : IPayPalRuntimeConfigurationProvider
    {
        public Task<PayPalRuntimeConfiguration> GetRequiredAsync(CancellationToken cancellationToken = default) =>
            Task.FromResult(new PayPalRuntimeConfiguration(environment, clientId, clientSecret));
    }

    private sealed class RecordingSequenceHandler(params HttpResponseMessage[] responses) : HttpMessageHandler
    {
        private readonly Queue<HttpResponseMessage> _responses = new(responses);
        public List<RecordedRequest> Requests { get; } = [];

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Requests.Add(new(
                request.Method,
                request.RequestUri!,
                request.Headers.Authorization?.Scheme,
                request.Headers.Authorization?.Parameter));
            if (_responses.Count == 0) throw new InvalidOperationException("No fake response remains.");
            return Task.FromResult(_responses.Dequeue());
        }
    }

    private sealed class ThrowingHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
            throw new InvalidOperationException("Inner gateway network path should not be used by lookup tests.");
    }

    private sealed record RecordedRequest(
        HttpMethod Method,
        Uri Uri,
        string? AuthorizationScheme,
        string? AuthorizationParameter);
}
