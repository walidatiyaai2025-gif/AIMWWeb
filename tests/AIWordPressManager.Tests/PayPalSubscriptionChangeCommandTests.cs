using System.Net;
using System.Text;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Infrastructure.Billing;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class PayPalSubscriptionChangeCommandTests
{
    [Fact]
    public async Task Revise_Returns_Only_Validated_PayPal_Approval_Navigation_And_Does_Not_Claim_Local_Completion()
    {
        var handler = new RecordingSequenceHandler(
            Json(HttpStatusCode.OK, "{\"access_token\":\"oauth-secret\"}"),
            Json(HttpStatusCode.OK, "{\"id\":\"I-CHANGE\",\"links\":[{\"rel\":\"approve\",\"href\":\"https://www.paypal.com/webapps/billing/approve?ba_token=abc\"}]}"));
        using var client = new HttpClient(handler);
        var gateway = CreateGateway(client);

        var result = await gateway.ChangeSubscriptionPlanAsync(new GatewayPlanChangeRequest("I-CHANGE", "P-TARGET", "corr-change"));

        result.Accepted.Should().BeTrue();
        result.RequiresUserApproval.Should().BeTrue();
        result.ApprovalUri.Should().Be(new Uri("https://www.paypal.com/webapps/billing/approve?ba_token=abc"));
        result.SanitizedSummary.Should().Contain("approval");
        handler.Requests.Should().HaveCount(2);
        handler.Requests[1].Method.Should().Be(HttpMethod.Post);
        handler.Requests[1].Uri.Should().Be(new Uri("https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-CHANGE/revise"));
        handler.Requests[1].Body.Should().Contain("\"plan_id\":\"P-TARGET\"");
        handler.Requests[1].AuthorizationParameter.Should().Be("oauth-secret");
    }

    [Fact]
    public async Task Hard_Cancel_And_Reactivate_Use_Distinct_PayPal_Actions_And_Return_Awaiting_Reconciliation_Summaries()
    {
        var handler = new RecordingSequenceHandler(
            Json(HttpStatusCode.OK, "{\"access_token\":\"token-one\"}"),
            new HttpResponseMessage(HttpStatusCode.NoContent),
            Json(HttpStatusCode.OK, "{\"access_token\":\"token-two\"}"),
            new HttpResponseMessage(HttpStatusCode.NoContent));
        using var client = new HttpClient(handler);
        var gateway = CreateGateway(client);

        var cancelled = await gateway.CancelSubscriptionAsync(new GatewaySubscriptionCommandRequest("I-LIFE", "cancel-correlation"));
        var reactivated = await gateway.ReactivateSubscriptionAsync(new GatewaySubscriptionCommandRequest("I-LIFE", "reactivate-correlation"));

        cancelled.Accepted.Should().BeTrue();
        cancelled.SanitizedSummary.Should().Contain("Await provider reconciliation");
        reactivated.Accepted.Should().BeTrue();
        reactivated.SanitizedSummary.Should().Contain("Await provider reconciliation");
        handler.Requests[1].Uri.Should().Be(new Uri("https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-LIFE/cancel"));
        handler.Requests[3].Uri.Should().Be(new Uri("https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-LIFE/activate"));
        handler.Requests[1].Body.Should().Contain("permanent subscription cancellation");
        handler.Requests[3].Body.Should().Contain("subscription reactivation");
    }

    [Fact]
    public async Task Provider_Rejection_Is_Sanitized_And_Does_Not_Expose_Response_Body_Or_Credentials()
    {
        var handler = new RecordingSequenceHandler(
            Json(HttpStatusCode.OK, "{\"access_token\":\"access-token-secret\"}"),
            new HttpResponseMessage(HttpStatusCode.UnprocessableEntity)
            {
                Content = new StringContent("provider-body access-token-secret client-secret", Encoding.UTF8, "text/plain")
            });
        using var client = new HttpClient(handler);
        var gateway = CreateGateway(client, "client-secret");

        var result = await gateway.ChangeSubscriptionPlanAsync(new GatewayPlanChangeRequest("I-REJECT", "P-REJECT", "reject-correlation"));

        result.Accepted.Should().BeFalse();
        result.SanitizedSummary.Should().Contain("HTTP 422");
        result.SanitizedSummary.Should().NotContain("provider-body");
        result.SanitizedSummary.Should().NotContain("access-token-secret");
        result.SanitizedSummary.Should().NotContain("client-secret");
    }

    [Fact]
    public async Task Revise_Ignores_Non_PayPal_Approval_Link()
    {
        var handler = new RecordingSequenceHandler(
            Json(HttpStatusCode.OK, "{\"access_token\":\"token\"}"),
            Json(HttpStatusCode.OK, "{\"id\":\"I-CHANGE\",\"links\":[{\"rel\":\"approve\",\"href\":\"https://evil.example/approve\"}]}"));
        using var client = new HttpClient(handler);
        var gateway = CreateGateway(client);

        var result = await gateway.ChangeSubscriptionPlanAsync(new GatewayPlanChangeRequest("I-CHANGE", "P-TARGET", "corr-change"));

        result.Accepted.Should().BeTrue();
        result.ApprovalUri.Should().BeNull();
        result.RequiresUserApproval.Should().BeFalse();
    }

    private static PayPalLifecyclePaymentGateway CreateGateway(HttpClient lifecycleClient, string clientSecret = "secret")
    {
        var runtime = new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", clientSecret);
        var inner = new PayPalPaymentGateway(new HttpClient(new ThrowingHandler()), runtime);
        return new PayPalLifecyclePaymentGateway(inner, lifecycleClient, runtime);
    }

    private static HttpResponseMessage Json(HttpStatusCode status, string body) => new(status)
    {
        Content = new StringContent(body, Encoding.UTF8, "application/json")
    };

    private sealed class FakeRuntimeConfigurationProvider(PayPalEnvironment environment, string clientId, string clientSecret) : IPayPalRuntimeConfigurationProvider
    {
        public Task<PayPalRuntimeConfiguration> GetRequiredAsync(CancellationToken cancellationToken = default) => Task.FromResult(new PayPalRuntimeConfiguration(environment, clientId, clientSecret));
    }

    private sealed class RecordingSequenceHandler(params HttpResponseMessage[] responses) : HttpMessageHandler
    {
        private readonly Queue<HttpResponseMessage> _responses = new(responses);
        public List<RecordedRequest> Requests { get; } = [];

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var body = request.Content is null ? string.Empty : await request.Content.ReadAsStringAsync(cancellationToken);
            Requests.Add(new(request.Method, request.RequestUri!, request.Headers.Authorization?.Parameter, body));
            if (_responses.Count == 0) throw new InvalidOperationException("No fake response remains.");
            return _responses.Dequeue();
        }
    }

    private sealed class ThrowingHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) => throw new InvalidOperationException("Inner gateway path is not used in lifecycle command tests.");
    }

    private sealed record RecordedRequest(HttpMethod Method, Uri Uri, string? AuthorizationParameter, string Body);
}
