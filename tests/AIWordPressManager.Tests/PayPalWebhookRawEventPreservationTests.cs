using System.Net;
using System.Text;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Infrastructure.Billing;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class PayPalWebhookRawEventPreservationTests
{
    [Fact]
    public async Task Verification_Postback_Preserves_The_Original_Webhook_Event_JSON_Exactly()
    {
        const string rawEvent = """
        {
          "id": "WH-RAW-1",
          "event_type": "BILLING.SUBSCRIPTION.ACTIVATED",
          "create_time": "2026-08-18T20:11:00Z",
          "resource": {
            "status": "ACTIVE",
            "id": "I-RAW-SUB"
          }
        }
        """;

        var handler = new RecordingHandler(
            JsonResponse(HttpStatusCode.OK, "{\"access_token\":\"oauth-token\"}"),
            JsonResponse(HttpStatusCode.OK, "{\"verification_status\":\"SUCCESS\"}"));
        using var client = new HttpClient(handler);
        var gateway = new PayPalPaymentGateway(
            client,
            new RuntimeConfigurationProvider());

        var result = await gateway.VerifyWebhookAsync(new GatewayWebhookEnvelope(
            rawEvent,
            new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
            {
                ["PAYPAL-AUTH-ALGO"] = "SHA256withRSA",
                ["PAYPAL-CERT-URL"] = "https://api.paypal.com/v1/notifications/certs/CERT-RAW",
                ["PAYPAL-TRANSMISSION-ID"] = "TRANS-RAW",
                ["PAYPAL-TRANSMISSION-SIG"] = "signature-value",
                ["PAYPAL-TRANSMISSION-TIME"] = "2026-08-18T20:10:00Z"
            },
            "correlation"));

        result.IsAuthentic.Should().BeTrue();
        handler.Bodies.Should().HaveCount(2);
        handler.Bodies[1].Should().Contain("\"webhook_event\":" + rawEvent);
    }

    private static HttpResponseMessage JsonResponse(HttpStatusCode status, string json) => new(status)
    {
        Content = new StringContent(json, Encoding.UTF8, "application/json")
    };

    private sealed class RuntimeConfigurationProvider : IPayPalRuntimeConfigurationProvider
    {
        public Task<PayPalRuntimeConfiguration> GetRequiredAsync(CancellationToken cancellationToken = default) =>
            Task.FromResult(new PayPalRuntimeConfiguration(
                PayPalEnvironment.Sandbox,
                "client",
                "secret",
                "8XX123456789"));
    }

    private sealed class RecordingHandler(params HttpResponseMessage[] responses) : HttpMessageHandler
    {
        private readonly Queue<HttpResponseMessage> _responses = new(responses);
        public List<string> Bodies { get; } = [];

        protected override async Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken)
        {
            Bodies.Add(request.Content is null
                ? string.Empty
                : await request.Content.ReadAsStringAsync(cancellationToken));
            return _responses.Dequeue();
        }
    }
}
