using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class PayPalWebhookInboxTests
{
    [Fact]
    public async Task Verified_Event_Is_Durable_And_Replay_Is_Idempotent_Across_EventId_Casing()
    {
        await using var fixture = await Fixture.CreateAsync();
        var firstEvent = VerifiedEvent("WH-Event-AbC", "I-SUB-1", GatewaySubscriptionState.Active);
        var replayEvent = VerifiedEvent("wh-event-abc", "I-SUB-1", GatewaySubscriptionState.Active);
        var receivedAt = DateTime.UtcNow;

        var first = await fixture.Inbox.AcceptVerifiedAsync(firstEvent, receivedAt);
        var replay = await fixture.Inbox.AcceptVerifiedAsync(replayEvent, receivedAt.AddSeconds(1));

        first.Inserted.Should().BeTrue();
        replay.Inserted.Should().BeFalse();
        replay.Event.Id.Should().Be(first.Event.Id);
        (await fixture.Context.Set<PayPalWebhookInboxEvent>().CountAsync()).Should().Be(1);
        (await fixture.Inbox.GetByProviderEventIdAsync("WH-EVENT-ABC"))!.Id.Should().Be(first.Event.Id);
    }

    [Fact]
    public async Task Same_EventId_With_Different_Verified_Data_Is_Rejected_As_Conflict()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Inbox.AcceptVerifiedAsync(
            VerifiedEvent("WH-CONFLICT", "I-SUB-1", GatewaySubscriptionState.Active),
            DateTime.UtcNow);

        var conflict = () => fixture.Inbox.AcceptVerifiedAsync(
            VerifiedEvent("WH-CONFLICT", "I-SUB-1", GatewaySubscriptionState.Cancelled),
            DateTime.UtcNow.AddSeconds(1));

        await conflict.Should().ThrowAsync<InvalidOperationException>().WithMessage("*different normalized event data*");
        (await fixture.Context.Set<PayPalWebhookInboxEvent>().CountAsync()).Should().Be(1);
    }

    [Fact]
    public async Task Intake_Accepts_New_Then_Duplicate_Without_Mutating_Account_Subscription()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("webhook-owner");
        var plan = await fixture.AddPlanAsync("webhook-plan");
        var subscriptions = new AccountSubscriptionService(fixture.Context);
        var subscription = await subscriptions.CreateAsync(new(owner.Id, plan.Id, AccountSubscriptionStatus.Trialing));
        var gateway = new VerifiedGateway(VerifiedEvent("WH-INTAKE", "I-PROVIDER-SUB", GatewaySubscriptionState.Active));
        var intake = new PayPalWebhookIntakeService(
            new AIWordPressManager.Infrastructure.Billing.PaymentGatewayRegistry(new[] { gateway }),
            fixture.Inbox);

        var first = await intake.HandleAsync("{}", new Dictionary<string, string>(), DateTime.UtcNow);
        var second = await intake.HandleAsync("{}", new Dictionary<string, string>(), DateTime.UtcNow.AddSeconds(1));

        first.Status.Should().Be(PayPalWebhookIntakeStatus.Accepted);
        second.Status.Should().Be(PayPalWebhookIntakeStatus.Duplicate);
        (await fixture.Context.Set<PayPalWebhookInboxEvent>().CountAsync()).Should().Be(1);
        (await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == subscription.Id)).Status
            .Should().Be(AccountSubscriptionStatus.Trialing);
        (await fixture.Context.AccountSubscriptionTransitions.CountAsync(x => x.SubscriptionId == subscription.Id)).Should().Be(0);
    }

    [Fact]
    public async Task Rejected_Or_Unavailable_Verification_Is_Never_Trusted_Or_Persisted()
    {
        await using var fixture = await Fixture.CreateAsync();
        var rejected = new RejectingGateway();
        var rejectedIntake = new PayPalWebhookIntakeService(
            new AIWordPressManager.Infrastructure.Billing.PaymentGatewayRegistry(new[] { rejected }),
            fixture.Inbox);

        var rejectedResult = await rejectedIntake.HandleAsync("{}", new Dictionary<string, string>(), DateTime.UtcNow);
        rejectedResult.Status.Should().Be(PayPalWebhookIntakeStatus.Rejected);
        (await fixture.Context.Set<PayPalWebhookInboxEvent>().CountAsync()).Should().Be(0);

        var unavailable = new UnavailableGateway();
        var unavailableIntake = new PayPalWebhookIntakeService(
            new AIWordPressManager.Infrastructure.Billing.PaymentGatewayRegistry(new[] { unavailable }),
            fixture.Inbox);
        var unavailableResult = await unavailableIntake.HandleAsync("{}", new Dictionary<string, string>(), DateTime.UtcNow);
        unavailableResult.Status.Should().Be(PayPalWebhookIntakeStatus.Unavailable);
        (await fixture.Context.Set<PayPalWebhookInboxEvent>().CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task Sqlite_Migration_Creates_Verified_Inbox_And_Unique_Event_Index()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
        await connection.OpenAsync();
        var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
        await using var context = new AppDbContext(options);

        await context.Database.MigrateAsync();

        await using var tableCommand = connection.CreateCommand();
        tableCommand.CommandText = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='PayPalWebhookInboxEvents';";
        Convert.ToInt32(await tableCommand.ExecuteScalarAsync()).Should().Be(1);

        await using var indexCommand = connection.CreateCommand();
        indexCommand.CommandText = "SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='IX_PayPalWebhookInboxEvents_NormalizedProviderEventId';";
        Convert.ToInt32(await indexCommand.ExecuteScalarAsync()).Should().Be(1);
    }

    private static GatewayVerifiedEvent VerifiedEvent(
        string eventId,
        string subscriptionReference,
        GatewaySubscriptionState state) =>
        new(
            eventId,
            subscriptionReference,
            state,
            new DateTime(2026, 8, 18, 20, 20, 0, DateTimeKind.Utc),
            state == GatewaySubscriptionState.Active
                ? "BILLING.SUBSCRIPTION.ACTIVATED"
                : "BILLING.SUBSCRIPTION.CANCELLED");

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            Inbox = new PayPalWebhookInbox(context);
            Plans = new SubscriptionPlanCatalog(context);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public PayPalWebhookInbox Inbox { get; }
        private SubscriptionPlanCatalog Plans { get; }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context);
        }

        public async Task<AuthUser> AddUserAsync(string userName)
        {
            var user = new AuthUser(userName, "test-password-hash", DateTime.UtcNow);
            Context.AuthUsers.Add(user);
            await Context.SaveChangesAsync();
            return user;
        }

        public Task<SubscriptionPlanItem> AddPlanAsync(string code) => Plans.CreateAsync(new(
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
            10));

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }

    private sealed class VerifiedGateway(GatewayVerifiedEvent verifiedEvent) : IPaymentGateway
    {
        public PaymentGatewayDescriptor Descriptor { get; } = new("paypal", "PayPal", PaymentGatewayCapability.WebhookVerification);
        public Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(GatewayWebhookEnvelope envelope, CancellationToken cancellationToken = default) =>
            Task.FromResult(GatewayWebhookVerificationResult.Verified(verifiedEvent));
        public Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(GatewayCheckoutRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(string providerSubscriptionReference, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> CancelSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> ReactivateSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> ChangeSubscriptionPlanAsync(GatewayPlanChangeRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }

    private sealed class RejectingGateway : IPaymentGateway
    {
        public PaymentGatewayDescriptor Descriptor { get; } = new("paypal", "PayPal", PaymentGatewayCapability.WebhookVerification);
        public Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(GatewayWebhookEnvelope envelope, CancellationToken cancellationToken = default) =>
            Task.FromResult(GatewayWebhookVerificationResult.Rejected("signature mismatch"));
        public Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(GatewayCheckoutRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(string providerSubscriptionReference, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> CancelSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> ReactivateSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> ChangeSubscriptionPlanAsync(GatewayPlanChangeRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }

    private sealed class UnavailableGateway : IPaymentGateway
    {
        public PaymentGatewayDescriptor Descriptor { get; } = new("paypal", "PayPal", PaymentGatewayCapability.WebhookVerification);
        public Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(GatewayWebhookEnvelope envelope, CancellationToken cancellationToken = default) =>
            throw new InvalidOperationException("provider unavailable secret-detail");
        public Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(GatewayCheckoutRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(string providerSubscriptionReference, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> CancelSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> ReactivateSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> ChangeSubscriptionPlanAsync(GatewayPlanChangeRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }
}
