using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Infrastructure.Billing;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class PayPalSubscriptionCheckoutTests
{
    [Fact]
    public async Task Gateway_Creates_Sandbox_Subscription_With_Server_Request_Id_And_Navigation_Only_Result()
    {
        var handler = new RecordingSequenceHandler(
            OAuthSuccess("access-token-1"),
            CheckoutSuccess("I-SUBSCRIPTION1", "https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-1"),
            OAuthSuccess("access-token-2"),
            CheckoutSuccess("I-SUBSCRIPTION1", "https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-1"));
        using var client = new HttpClient(handler);
        var gateway = new PayPalPaymentGateway(
            client,
            new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "secret"));
        var request = NewGatewayRequest("correlation-same");

        var first = await gateway.CreateSubscriptionCheckoutAsync(request);
        var second = await gateway.CreateSubscriptionCheckoutAsync(request);

        gateway.Descriptor.Capabilities.Should().Be(PaymentGatewayCapability.SubscriptionCheckout);
        first.ProviderSessionReference.Should().Be("I-SUBSCRIPTION1");
        first.Authority.Should().Be(GatewayEvidenceAuthority.NavigationOnly);
        first.CheckoutUri.Host.Should().Be("www.sandbox.paypal.com");
        second.Authority.Should().Be(GatewayEvidenceAuthority.NavigationOnly);

        handler.Requests.Should().HaveCount(4);
        handler.Requests[0].Uri.Should().Be(new Uri("https://api-m.sandbox.paypal.com/v1/oauth2/token"));
        handler.Requests[1].Uri.Should().Be(new Uri("https://api-m.sandbox.paypal.com/v1/billing/subscriptions"));
        handler.Requests[1].AuthorizationScheme.Should().Be("Bearer");
        handler.Requests[1].AuthorizationParameter.Should().Be("access-token-1");
        handler.Requests[1].Headers["PayPal-Request-Id"].Should().HaveCount(1);
        handler.Requests[1].Headers["PayPal-Request-Id"].Single().Length.Should().Be(36);
        handler.Requests[3].Headers["PayPal-Request-Id"].Single()
            .Should().Be(handler.Requests[1].Headers["PayPal-Request-Id"].Single());

        using var body = JsonDocument.Parse(handler.Requests[1].Body!);
        body.RootElement.GetProperty("plan_id").GetString().Should().Be("P-SERVER-MAPPED");
        var context = body.RootElement.GetProperty("application_context");
        context.GetProperty("user_action").GetString().Should().Be("SUBSCRIBE_NOW");
        context.GetProperty("return_url").GetString().Should().Be("https://app.example.test/billing/return");
        context.GetProperty("cancel_url").GetString().Should().Be("https://app.example.test/billing/cancel");
    }

    [Fact]
    public async Task Gateway_Uses_Live_Endpoints_When_Runtime_Environment_Is_Live()
    {
        var handler = new RecordingSequenceHandler(
            OAuthSuccess("live-token"),
            CheckoutSuccess("I-LIVE1", "https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-LIVE"));
        using var client = new HttpClient(handler);
        var gateway = new PayPalPaymentGateway(
            client,
            new FakeRuntimeConfigurationProvider(PayPalEnvironment.Live, "client", "secret"));

        await gateway.CreateSubscriptionCheckoutAsync(NewGatewayRequest("live-correlation"));

        handler.Requests[0].Uri.Should().Be(new Uri("https://api-m.paypal.com/v1/oauth2/token"));
        handler.Requests[1].Uri.Should().Be(new Uri("https://api-m.paypal.com/v1/billing/subscriptions"));
    }

    [Fact]
    public async Task Gateway_Rejects_Malformed_Or_Untrusted_Success_Response()
    {
        var missingLink = new RecordingSequenceHandler(
            OAuthSuccess("token"),
            new HttpResponseMessage(HttpStatusCode.Created)
            {
                Content = Json("{\"id\":\"I-VALID\",\"links\":[]}")
            });
        using (var client = new HttpClient(missingLink))
        {
            var gateway = new PayPalPaymentGateway(client, new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "secret"));
            var action = () => gateway.CreateSubscriptionCheckoutAsync(NewGatewayRequest("missing-link"));
            await action.Should().ThrowAsync<InvalidOperationException>().WithMessage("*approval link*");
        }

        var hostileLink = new RecordingSequenceHandler(
            OAuthSuccess("token"),
            CheckoutSuccess("I-VALID", "https://evil.example.test/approve"));
        using (var client = new HttpClient(hostileLink))
        {
            var gateway = new PayPalPaymentGateway(client, new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "secret"));
            var action = () => gateway.CreateSubscriptionCheckoutAsync(NewGatewayRequest("hostile-link"));
            await action.Should().ThrowAsync<InvalidOperationException>().WithMessage("*invalid approval link*");
        }
    }

    [Fact]
    public async Task Gateway_Failures_Are_Sanitized_And_Do_Not_Leak_Provider_Bodies_Or_Tokens()
    {
        var handler = new RecordingSequenceHandler(
            OAuthSuccess("access-token-must-not-leak"),
            new HttpResponseMessage(HttpStatusCode.UnprocessableEntity)
            {
                Content = new StringContent("secret body access-token-must-not-leak", Encoding.UTF8, "text/plain")
            });
        using var client = new HttpClient(handler);
        var gateway = new PayPalPaymentGateway(client, new FakeRuntimeConfigurationProvider(PayPalEnvironment.Sandbox, "client", "client-secret"));

        var action = () => gateway.CreateSubscriptionCheckoutAsync(NewGatewayRequest("rejected"));
        var exception = await action.Should().ThrowAsync<InvalidOperationException>();
        exception.Which.Message.Should().Contain("HTTP 422");
        exception.Which.Message.Should().NotContain("secret body");
        exception.Which.Message.Should().NotContain("access-token-must-not-leak");
        exception.Which.Message.Should().NotContain("client-secret");
    }

    [Fact]
    public async Task Orchestrator_Uses_Server_Stored_Plan_Mapping_And_Does_Not_Mutate_Subscription_State()
    {
        await using var fixture = await CheckoutFixture.CreateAsync();
        var owner = await fixture.AddUserAsync("checkout-owner");
        var plan = await fixture.AddPlanAsync("checkout-plan", "P-FROM-DATABASE");
        var subscription = await fixture.Subscriptions.CreateAsync(new(
            owner.Id,
            plan.Id,
            AccountSubscriptionStatus.Trialing));
        var gateway = new CapturingGateway();
        var service = new PayPalSubscriptionCheckoutService(
            fixture.Context,
            new PaymentGatewayRegistry(new[] { gateway }));

        var result = await service.CreateAsync(
            owner.Id,
            subscription.Id,
            new Uri("https://app.example.test/return"),
            new Uri("https://app.example.test/cancel"),
            "checkout-correlation");

        result.Authority.Should().Be(GatewayEvidenceAuthority.NavigationOnly);
        gateway.LastRequest.Should().NotBeNull();
        gateway.LastRequest!.ProviderPlanReference.Should().Be("P-FROM-DATABASE");
        gateway.LastRequest.PlanId.Should().Be(plan.Id);
        var reloaded = await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == subscription.Id);
        reloaded.Status.Should().Be(AccountSubscriptionStatus.Trialing);
        (await fixture.Context.AccountSubscriptionTransitions.CountAsync(x => x.SubscriptionId == subscription.Id)).Should().Be(0);
    }

    [Fact]
    public async Task Orchestrator_Rejects_Missing_Plan_Mapping_Wrong_Owner_And_Expired_Row_Before_Gateway_Call()
    {
        await using var fixture = await CheckoutFixture.CreateAsync();
        var owner = await fixture.AddUserAsync("owner-a");
        var other = await fixture.AddUserAsync("owner-b");
        var unmapped = await fixture.AddPlanAsync("unmapped", null);
        var subscription = await fixture.Subscriptions.CreateAsync(new(owner.Id, unmapped.Id, AccountSubscriptionStatus.Active));
        var gateway = new CapturingGateway();
        var service = new PayPalSubscriptionCheckoutService(fixture.Context, new PaymentGatewayRegistry(new[] { gateway }));

        var missingMap = () => service.CreateAsync(owner.Id, subscription.Id, ReturnUri(), CancelUri(), "missing-map");
        await missingMap.Should().ThrowAsync<InvalidOperationException>().WithMessage("*not mapped*");
        gateway.LastRequest.Should().BeNull();

        var wrongOwner = () => service.CreateAsync(other.Id, subscription.Id, ReturnUri(), CancelUri(), "wrong-owner");
        await wrongOwner.Should().ThrowAsync<KeyNotFoundException>();

        var mapped = await fixture.AddPlanAsync("mapped", "P-MAPPED");
        var expiredOwner = await fixture.AddUserAsync("expired-owner");
        var expiredSubscription = await fixture.Subscriptions.CreateAsync(new(expiredOwner.Id, mapped.Id, AccountSubscriptionStatus.Active));
        await fixture.Subscriptions.TransitionAsync(expiredSubscription.Id, new(
            AccountSubscriptionStatus.Expired,
            SubscriptionTransitionSource.System,
            "Test terminal row.",
            DateTime.UtcNow.AddMinutes(1)));

        var expired = () => service.CreateAsync(expiredOwner.Id, expiredSubscription.Id, ReturnUri(), CancelUri(), "expired");
        await expired.Should().ThrowAsync<InvalidOperationException>().WithMessage("*Expired subscriptions*");
    }

    private static GatewayCheckoutRequest NewGatewayRequest(string correlationId) => new(
        Guid.NewGuid(),
        Guid.NewGuid(),
        "P-SERVER-MAPPED",
        new Uri("https://app.example.test/billing/return"),
        new Uri("https://app.example.test/billing/cancel"),
        correlationId);

    private static Uri ReturnUri() => new("https://app.example.test/return");
    private static Uri CancelUri() => new("https://app.example.test/cancel");

    private static HttpResponseMessage OAuthSuccess(string accessToken) => new(HttpStatusCode.OK)
    {
        Content = Json($"{{\"access_token\":\"{accessToken}\",\"token_type\":\"Bearer\"}}")
    };

    private static HttpResponseMessage CheckoutSuccess(string subscriptionId, string approvalUrl) => new(HttpStatusCode.Created)
    {
        Content = Json($"{{\"id\":\"{subscriptionId}\",\"status\":\"APPROVAL_PENDING\",\"links\":[{{\"href\":\"{approvalUrl}\",\"rel\":\"approve\",\"method\":\"GET\"}}]}}")
    };

    private static StringContent Json(string value) => new(value, Encoding.UTF8, "application/json");

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

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var headers = request.Headers.ToDictionary(x => x.Key, x => x.Value.ToArray(), StringComparer.OrdinalIgnoreCase);
            Requests.Add(new(
                request.RequestUri!,
                request.Headers.Authorization?.Scheme,
                request.Headers.Authorization?.Parameter,
                headers,
                request.Content is null ? null : await request.Content.ReadAsStringAsync(cancellationToken)));
            if (_responses.Count == 0) throw new InvalidOperationException("No fake response remains.");
            return _responses.Dequeue();
        }
    }

    private sealed record RecordedRequest(
        Uri Uri,
        string? AuthorizationScheme,
        string? AuthorizationParameter,
        IReadOnlyDictionary<string, string[]> Headers,
        string? Body);

    private sealed class CapturingGateway : IPaymentGateway
    {
        public PaymentGatewayDescriptor Descriptor { get; } = new("paypal", "PayPal", PaymentGatewayCapability.SubscriptionCheckout);
        public GatewayCheckoutRequest? LastRequest { get; private set; }

        public Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(GatewayCheckoutRequest request, CancellationToken cancellationToken = default)
        {
            LastRequest = request;
            return Task.FromResult(new GatewayCheckoutSession(
                "I-CAPTURED",
                new Uri("https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-CAPTURED")));
        }

        public Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(GatewayWebhookEnvelope envelope, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(string providerSubscriptionReference, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> CancelSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> ReactivateSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> ChangeSubscriptionPlanAsync(GatewayPlanChangeRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }

    private sealed class CheckoutFixture : IAsyncDisposable
    {
        private CheckoutFixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            Plans = new SubscriptionPlanCatalog(context);
            Subscriptions = new AccountSubscriptionService(context);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        private SubscriptionPlanCatalog Plans { get; }
        public AccountSubscriptionService Subscriptions { get; }

        public static async Task<CheckoutFixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new CheckoutFixture(connection, context);
        }

        public async Task<AuthUser> AddUserAsync(string userName)
        {
            var user = new AuthUser(userName, "test-password-hash", DateTime.UtcNow);
            Context.AuthUsers.Add(user);
            await Context.SaveChangesAsync();
            return user;
        }

        public Task<SubscriptionPlanItem> AddPlanAsync(string code, string? gatewayPlanId) =>
            Plans.CreateAsync(new SubscriptionPlanCreateRequest(
                code,
                code,
                $"{code} عربي",
                null,
                null,
                SubscriptionPlan.MonthlyInterval,
                0m,
                "USD",
                0,
                0,
                true,
                10,
                GatewayProductId: null,
                GatewayPlanId: gatewayPlanId));

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
