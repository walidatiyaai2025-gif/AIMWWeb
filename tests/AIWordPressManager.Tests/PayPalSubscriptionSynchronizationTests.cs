using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class PayPalSubscriptionSynchronizationTests
{
    [Fact]
    public async Task Verified_Event_Is_Claimed_Reconciled_And_Replay_Safe()
    {
        await using var fixture = await Fixture.CreateAsync();
        var source = await fixture.CreateBoundSubscriptionAsync("sync-owner", "sync-plan", AccountSubscriptionStatus.Trialing, "I-SYNC-1");
        var now = new DateTime(2026, 8, 18, 20, 50, 0, DateTimeKind.Utc);
        var eventAt = now.AddMinutes(-1);
        await fixture.Inbox.AcceptVerifiedAsync(new GatewayVerifiedEvent(
            "WH-SYNC-1",
            "I-SYNC-1",
            GatewaySubscriptionState.Active,
            eventAt,
            "BILLING.SUBSCRIPTION.ACTIVATED"), now);

        var periodStart = now.AddDays(-10);
        var periodEnd = now.AddDays(20);
        var gateway = new SequenceLookupGateway(new GatewaySubscriptionSnapshot(
            "I-SYNC-1",
            GatewaySubscriptionState.Active,
            now,
            periodStart,
            periodEnd));
        var service = fixture.CreateSynchronizationService(gateway);

        var first = await service.ProcessVerifiedEventsAsync(now, 10);

        first.Scanned.Should().Be(1);
        first.Changed.Should().Be(1);
        first.Failed.Should().Be(0);
        gateway.LookupCount.Should().Be(1);
        var reloaded = await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == source.Subscription.Id);
        reloaded.Status.Should().Be(AccountSubscriptionStatus.Active);
        reloaded.LastProviderEventAtUtc.Should().Be(eventAt);
        reloaded.CurrentPeriodStartUtc.Should().Be(periodStart);
        reloaded.CurrentPeriodEndsAtUtc.Should().Be(periodEnd);
        (await fixture.Context.AccountSubscriptionTransitions.CountAsync(x => x.SubscriptionId == source.Subscription.Id)).Should().Be(1);
        var processing = await fixture.Context.Set<PayPalWebhookProcessingState>().AsNoTracking().SingleAsync();
        processing.Status.Should().Be(PayPalWebhookProcessingStatus.Processed);
        processing.AttemptCount.Should().Be(1);

        var replay = await service.ProcessVerifiedEventsAsync(now.AddMinutes(1), 10);
        replay.Scanned.Should().Be(0);
        gateway.LookupCount.Should().Be(1);
    }

    [Fact]
    public async Task Payment_Failure_Stays_PastDue_Until_A_Newer_Provider_Payment_Is_Observed()
    {
        await using var fixture = await Fixture.CreateAsync();
        var source = await fixture.CreateBoundSubscriptionAsync("payment-owner", "payment-plan", AccountSubscriptionStatus.Active, "I-PAYMENT-1");
        var now = new DateTime(2026, 8, 18, 20, 50, 0, DateTimeKind.Utc);
        var eventAt = now.AddMinutes(-5);
        await fixture.Inbox.AcceptVerifiedAsync(new GatewayVerifiedEvent(
            "WH-FAILED-1",
            "I-PAYMENT-1",
            GatewaySubscriptionState.PastDue,
            eventAt,
            "BILLING.SUBSCRIPTION.PAYMENT.FAILED"), now);

        var oldPaymentStart = eventAt.AddDays(-20);
        var recoveredPaymentStart = eventAt.AddMinutes(5);
        var gateway = new SequenceLookupGateway(
            new GatewaySubscriptionSnapshot(
                "I-PAYMENT-1",
                GatewaySubscriptionState.Active,
                now,
                oldPaymentStart,
                oldPaymentStart.AddMonths(1)),
            new GatewaySubscriptionSnapshot(
                "I-PAYMENT-1",
                GatewaySubscriptionState.Active,
                now.AddMinutes(10),
                recoveredPaymentStart,
                recoveredPaymentStart.AddMonths(1)));
        var service = fixture.CreateSynchronizationService(gateway);

        var failedPayment = await service.ProcessVerifiedEventsAsync(now, 10);
        failedPayment.Changed.Should().Be(1);
        (await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == source.Subscription.Id))
            .Status.Should().Be(AccountSubscriptionStatus.PastDue);

        var recovery = await service.ReconcileBoundSubscriptionsAsync(now.AddMinutes(10), 10);
        recovery.Changed.Should().Be(1);
        var recovered = await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == source.Subscription.Id);
        recovered.Status.Should().Be(AccountSubscriptionStatus.Active);
        recovered.CurrentPeriodStartUtc.Should().Be(recoveredPaymentStart);
        gateway.LookupCount.Should().Be(2);
    }

    [Fact]
    public async Task Unknown_Provider_Reference_Is_Processed_Without_Network_Or_Cross_Account_Mutation()
    {
        await using var fixture = await Fixture.CreateAsync();
        var source = await fixture.CreateBoundSubscriptionAsync("known-owner", "known-plan", AccountSubscriptionStatus.Active, "I-KNOWN");
        var now = new DateTime(2026, 8, 18, 20, 50, 0, DateTimeKind.Utc);
        await fixture.Inbox.AcceptVerifiedAsync(new GatewayVerifiedEvent(
            "WH-UNKNOWN-1",
            "I-UNKNOWN",
            GatewaySubscriptionState.Cancelled,
            now.AddMinutes(-1),
            "BILLING.SUBSCRIPTION.CANCELLED"), now);
        var gateway = new SequenceLookupGateway();
        var service = fixture.CreateSynchronizationService(gateway);

        var result = await service.ProcessVerifiedEventsAsync(now, 10);

        result.Ignored.Should().Be(1);
        result.Failed.Should().Be(0);
        gateway.LookupCount.Should().Be(0);
        var reloaded = await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == source.Subscription.Id);
        reloaded.Status.Should().Be(AccountSubscriptionStatus.Active);
        reloaded.ProviderSubscriptionReference.Should().Be("I-KNOWN");
        (await fixture.Context.AccountSubscriptionTransitions.CountAsync(x => x.SubscriptionId == source.Subscription.Id)).Should().Be(0);
        (await fixture.Context.Set<PayPalWebhookProcessingState>().AsNoTracking().SingleAsync()).Status
            .Should().Be(PayPalWebhookProcessingStatus.Processed);
    }

    [Fact]
    public async Task Periodic_Reconciliation_Repairs_Status_Drift_From_Provider_Snapshot()
    {
        await using var fixture = await Fixture.CreateAsync();
        var source = await fixture.CreateBoundSubscriptionAsync("drift-owner", "drift-plan", AccountSubscriptionStatus.Active, "I-DRIFT");
        var now = new DateTime(2026, 8, 18, 20, 50, 0, DateTimeKind.Utc);
        var gateway = new SequenceLookupGateway(new GatewaySubscriptionSnapshot(
            "I-DRIFT",
            GatewaySubscriptionState.Suspended,
            now));
        var service = fixture.CreateSynchronizationService(gateway);

        var result = await service.ReconcileBoundSubscriptionsAsync(now, 10);

        result.Changed.Should().Be(1);
        var reloaded = await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == source.Subscription.Id);
        reloaded.Status.Should().Be(AccountSubscriptionStatus.Suspended);
        reloaded.LastProviderEventAtUtc.Should().Be(now);
        gateway.LookupCount.Should().Be(1);
    }

    [Fact]
    public async Task Sqlite_Migration_Creates_Durable_Webhook_Processing_State()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
        await connection.OpenAsync();
        var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
        await using var context = new AppDbContext(options);
        await context.Database.MigrateAsync();

        await using var command = connection.CreateCommand();
        command.CommandText = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='PayPalWebhookProcessingStates';";
        Convert.ToInt32(await command.ExecuteScalarAsync()).Should().Be(1);
    }

    private sealed class SequenceLookupGateway(params GatewaySubscriptionSnapshot[] snapshots) : IPaymentGateway
    {
        private readonly Queue<GatewaySubscriptionSnapshot> _snapshots = new(snapshots);
        public int LookupCount { get; private set; }
        public PaymentGatewayDescriptor Descriptor { get; } = new("paypal", "PayPal", PaymentGatewayCapability.SubscriptionLookup);

        public Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(string providerSubscriptionReference, CancellationToken cancellationToken = default)
        {
            LookupCount++;
            if (_snapshots.Count == 0) throw new InvalidOperationException("No provider snapshot remains.");
            return Task.FromResult(_snapshots.Dequeue());
        }

        public Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(GatewayCheckoutRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(GatewayWebhookEnvelope envelope, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> CancelSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> ReactivateSubscriptionAsync(GatewaySubscriptionCommandRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewayCommandResult> ChangeSubscriptionPlanAsync(GatewayPlanChangeRequest request, CancellationToken cancellationToken = default) => throw new NotSupportedException();
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            InnerSubscriptions = new AccountSubscriptionService(context);
            SafeSubscriptions = new ProviderBindingAccountSubscriptionService(context, InnerSubscriptions);
            Plans = new SubscriptionPlanCatalog(context);
            Inbox = new PayPalWebhookInbox(context);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        private AccountSubscriptionService InnerSubscriptions { get; }
        private ProviderBindingAccountSubscriptionService SafeSubscriptions { get; }
        private SubscriptionPlanCatalog Plans { get; }
        public PayPalWebhookInbox Inbox { get; }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context);
        }

        public async Task<(AuthUser Owner, SubscriptionPlanItem Plan, AccountSubscriptionItem Subscription)> CreateBoundSubscriptionAsync(
            string ownerName,
            string planCode,
            AccountSubscriptionStatus status,
            string providerReference)
        {
            var owner = new AuthUser(ownerName, "test-password-hash", DateTime.UtcNow);
            Context.AuthUsers.Add(owner);
            await Context.SaveChangesAsync();
            var plan = await Plans.CreateAsync(new SubscriptionPlanCreateRequest(
                planCode,
                planCode,
                $"{planCode} عربي",
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
                GatewayPlanId: "P-SYNC"));
            var subscription = await SafeSubscriptions.CreateAsync(new(owner.Id, plan.Id, status));
            var bound = await SafeSubscriptions.BindProviderReferenceAsync(subscription.Id, "paypal", providerReference);
            return (owner, plan, bound);
        }

        public PayPalSubscriptionSynchronizationService CreateSynchronizationService(IPaymentGateway gateway) =>
            new(Context, new PaymentGatewayRegistry(new[] { gateway }), SafeSubscriptions);

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
