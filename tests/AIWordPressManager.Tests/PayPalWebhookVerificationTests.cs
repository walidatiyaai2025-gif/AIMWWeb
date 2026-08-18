using System.Net;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Infrastructure.Billing;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class PayPalWebhookVerificationTests
{
    [Fact]
    public async Task Verification_Posts_Required_Headers_WebhookId_And_Original_Event_To_Selected_Environment()
    {
        var handler = new RecordingSequenceHandler(
            JsonResponse(HttpStatusCode.OK, "{\"access_token\":\"oauth-token\"}"),
            JsonResponse(HttpStatusCode.OK, "{\"verification_status\":\"SUCCESS\"}"));
        using var client = new HttpClient(handler);
        var gateway = new PayPalPaymentGateway(
            client,
            new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "secret", "8XX123456789"));
        var envelope = new GatewayWebhookEnvelope(
            ActivatedEvent("WH-EVENT-1", "I-SUB-1"),
            VerificationHeaders(),
            "correlation");

        var result = await gateway.VerifyWebhookAsync(envelope);

        result.IsAuthentic.Should().BeTrue();
        result.Event.Should().NotBeNull();
        result.Event!.ProviderEventId.Should().Be("WH-EVENT-1");
        result.Event.ProviderSubscriptionReference.Should().Be("I-SUB-1");
        result.Event.State.Should().Be(GatewaySubscriptionState.Active);
        result.Event.Authority.Should().Be(GatewayEvidenceAuthority.VerifiedWebhook);

        handler.Requests.Should().HaveCount(2);
        handler.Requests[0].Uri.Should().Be(new Uri("https://api-m.sandbox.paypal.com/v1/oauth2/token"));
        handler.Requests[1].Uri.Should().Be(new Uri("https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature"));
        handler.Requests[1].AuthorizationScheme.Should().Be("Bearer");
        handler.Requests[1].AuthorizationParameter.Should().Be("oauth-token");

        using var verificationBody = JsonDocument.Parse(handler.Requests[1].Body!);
        var root = verificationBody.RootElement;
        root.GetProperty("auth_algo").GetString().Should().Be("SHA256withRSA");
        root.GetProperty("cert_url").GetString().Should().Be("https://api.paypal.com/v1/notifications/certs/CERT-1");
        root.GetProperty("transmission_id").GetString().Should().Be("TRANS-1");
        root.GetProperty("transmission_sig").GetString().Should().Be("signature-value");
        root.GetProperty("transmission_time").GetString().Should().Be("2026-08-18T20:10:00Z");
        root.GetProperty("webhook_id").GetString().Should().Be("8XX123456789");
        root.GetProperty("webhook_event").GetProperty("id").GetString().Should().Be("WH-EVENT-1");
    }

    [Fact]
    public async Task Verification_Failure_Or_Invalid_Headers_Never_Produce_Trusted_Event()
    {
        var failureHandler = new RecordingSequenceHandler(
            JsonResponse(HttpStatusCode.OK, "{\"access_token\":\"oauth-token\"}"),
            JsonResponse(HttpStatusCode.OK, "{\"verification_status\":\"FAILURE\"}"));
        using var failureClient = new HttpClient(failureHandler);
        var gateway = new PayPalPaymentGateway(
            failureClient,
            new FakeRuntimeConfigurationProvider(PayPalEnvironment.Live, "client", "secret", "8XX123456789"));

        var rejected = await gateway.VerifyWebhookAsync(new GatewayWebhookEnvelope(
            ActivatedEvent("WH-EVENT-2", "I-SUB-2"),
            VerificationHeaders(),
            "correlation"));
        rejected.IsAuthentic.Should().BeFalse();
        rejected.Event.Should().BeNull();
        rejected.SanitizedFailure.Should().Contain("verification failed");

        var invalidHandler = new RecordingSequenceHandler();
        using var invalidClient = new HttpClient(invalidHandler);
        var invalidGateway = new PayPalPaymentGateway(
            invalidClient,
            new FakeRuntimeConfigurationProvider(PayPalEnvironment.Live, "client", "secret", "8XX123456789"));
        var invalidHeaders = VerificationHeaders();
        invalidHeaders["PAYPAL-CERT-URL"] = "https://evil.example.test/cert";
        var invalid = await invalidGateway.VerifyWebhookAsync(new GatewayWebhookEnvelope(
            ActivatedEvent("WH-EVENT-3", "I-SUB-3"),
            invalidHeaders,
            "correlation"));
        invalid.IsAuthentic.Should().BeFalse();
        invalidHandler.Requests.Should().BeEmpty();
    }

    [Fact]
    public async Task Missing_WebhookId_Fails_Closed_Before_Network_Call()
    {
        var handler = new RecordingSequenceHandler();
        using var client = new HttpClient(handler);
        var gateway = new PayPalPaymentGateway(
            client,
            new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "secret", ""));

        var action = () => gateway.VerifyWebhookAsync(new GatewayWebhookEnvelope(
            ActivatedEvent("WH-EVENT-4", "I-SUB-4"),
            VerificationHeaders(),
            "correlation"));

        await action.Should().ThrowAsync<InvalidOperationException>().WithMessage("*webhook ID is not configured*");
        handler.Requests.Should().BeEmpty();
    }

    [Theory]
    [InlineData("BILLING.SUBSCRIPTION.CREATED", "APPROVAL_PENDING", GatewaySubscriptionState.Pending)]
    [InlineData("BILLING.SUBSCRIPTION.ACTIVATED", "ACTIVE", GatewaySubscriptionState.Active)]
    [InlineData("BILLING.SUBSCRIPTION.UPDATED", "SUSPENDED", GatewaySubscriptionState.Suspended)]
    [InlineData("BILLING.SUBSCRIPTION.EXPIRED", "EXPIRED", GatewaySubscriptionState.Expired)]
    [InlineData("BILLING.SUBSCRIPTION.CANCELLED", "CANCELLED", GatewaySubscriptionState.Cancelled)]
    [InlineData("BILLING.SUBSCRIPTION.SUSPENDED", "SUSPENDED", GatewaySubscriptionState.Suspended)]
    [InlineData("BILLING.SUBSCRIPTION.PAYMENT.FAILED", "ACTIVE", GatewaySubscriptionState.PastDue)]
    public async Task Verified_Subscription_Events_Are_Normalized(
        string eventType,
        string resourceStatus,
        GatewaySubscriptionState expectedState)
    {
        var handler = new RecordingSequenceHandler(
            JsonResponse(HttpStatusCode.OK, "{\"access_token\":\"oauth-token\"}"),
            JsonResponse(HttpStatusCode.OK, "{\"verification_status\":\"SUCCESS\"}"));
        using var client = new HttpClient(handler);
        var gateway = new PayPalPaymentGateway(
            client,
            new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "secret", "8XX123456789"));
        var body = $$"""
        {
          "id":"WH-EVENT-NORMALIZE",
          "event_type":"{{eventType}}",
          "create_time":"2026-08-18T20:12:00Z",
          "resource":{"id":"I-SUB-NORMALIZE","status":"{{resourceStatus}}"}
        }
        """;

        var result = await gateway.VerifyWebhookAsync(new GatewayWebhookEnvelope(body, VerificationHeaders(), "correlation"));

        result.IsAuthentic.Should().BeTrue();
        result.Event!.State.Should().Be(expectedState);
        result.Event.OccurredAtUtc.Kind.Should().Be(DateTimeKind.Utc);
    }

    [Fact]
    public async Task Unavailable_Verification_Is_Sanitized_And_Does_Not_Leak_Response_Body()
    {
        var handler = new RecordingSequenceHandler(
            JsonResponse(HttpStatusCode.OK, "{\"access_token\":\"oauth-token-secret\"}"),
            new HttpResponseMessage(HttpStatusCode.ServiceUnavailable)
            {
                Content = new StringContent("provider body oauth-token-secret client-secret", Encoding.UTF8, "text/plain")
            });
        using var client = new HttpClient(handler);
        var gateway = new PayPalPaymentGateway(
            client,
            new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "client-secret", "8XX123456789"));

        var action = () => gateway.VerifyWebhookAsync(new GatewayWebhookEnvelope(
            ActivatedEvent("WH-EVENT-5", "I-SUB-5"),
            VerificationHeaders(),
            "correlation"));
        var exception = await action.Should().ThrowAsync<InvalidOperationException>();
        exception.Which.Message.Should().Contain("HTTP 503");
        exception.Which.Message.Should().NotContain("provider body");
        exception.Which.Message.Should().NotContain("oauth-token-secret");
        exception.Which.Message.Should().NotContain("client-secret");
    }

    private static Dictionary<string, string> VerificationHeaders() => new(StringComparer.OrdinalIgnoreCase)
    {
        ["PAYPAL-AUTH-ALGO"] = "SHA256withRSA",
        ["PAYPAL-CERT-URL"] = "https://api.paypal.com/v1/notifications/certs/CERT-1",
        ["PAYPAL-TRANSMISSION-ID"] = "TRANS-1",
        ["PAYPAL-TRANSMISSION-SIG"] = "signature-value",
        ["PAYPAL-TRANSMISSION-TIME"] = "2026-08-18T20:10:00Z"
    };

    private static string ActivatedEvent(string eventId, string subscriptionId) => $$"""
    {
      "id":"{{eventId}}",
      "event_type":"BILLING.SUBSCRIPTION.ACTIVATED",
      "create_time":"2026-08-18T20:11:00Z",
      "resource":{"id":"{{subscriptionId}}","status":"ACTIVE"}
    }
    """;

    private static HttpResponseMessage JsonResponse(HttpStatusCode code, string json) => new(code)
    {
        Content = new StringContent(json, Encoding.UTF8, "application/json")
    };

    private sealed class FakeRuntimeConfigurationProvider(
        PayPalEnvironment environment,
        string clientId,
        string clientSecret,
        string webhookId) : IPayPalRuntimeConfigurationProvider
    {
        public Task<PayPalRuntimeConfiguration> GetRequiredAsync(CancellationToken cancellationToken = default) =>
            Task.FromResult(new PayPalRuntimeConfiguration(environment, clientId, clientSecret, webhookId));
    }

    private sealed class RecordingSequenceHandler(params HttpResponseMessage[] responses) : HttpMessageHandler
    {
        private readonly Queue<HttpResponseMessage> _responses = new(responses);
        public List<RecordedRequest> Requests { get; } = [];

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Requests.Add(new(
                request.RequestUri!,
                request.Headers.Authorization?.Scheme,
                request.Headers.Authorization?.Parameter,
                request.Content is null ? null : await request.Content.ReadAsStringAsync(cancellationToken)));
            if (_responses.Count == 0) throw new InvalidOperationException("No fake response remains.");
            return _responses.Dequeue();
        }
    }

    private sealed record RecordedRequest(
        Uri Uri,
        string? AuthorizationScheme,
        string? AuthorizationParameter,
        string? Body);
}
