using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Email;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class SubscriptionBillingEmailAlertTests
{
    [Fact]
    public async Task Committed_Status_Transition_Queues_Account_Billing_Event()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("billing.owner");
        await fixture.AddRecipientAsync(owner.Id, "billing@example.com");
        var plan = await fixture.AddPlanAsync("pro", "Pro", 29m, "USD", 1);
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, plan.Id, "I-SUB-1001");
        var occurredAt = DateTime.UtcNow.AddMinutes(-5);
        subscription.TransitionTo(
            AccountSubscriptionStatus.PastDue,
            SubscriptionTransitionSource.Provider,
            occurredAt,
            occurredAt);
        var transition = new AccountSubscriptionTransition(
            subscription.Id,
            AccountSubscriptionStatus.Active,
            AccountSubscriptionStatus.PastDue,
            SubscriptionTransitionSource.Provider,
            "PayPal subscription reconciliation",
            occurredAt,
            occurredAt);
        fixture.Context.AccountSubscriptionTransitions.Add(transition);
        await fixture.Context.SaveChangesAsync();

        var result = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        result.Scanned.Should().Be(1);
        result.Enqueued.Should().Be(1);
        result.Failed.Should().Be(0);
        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.OwnerUserId.Should().Be(owner.Id);
        stored.SiteId.Should().BeNull();
        stored.TemplateKey.Should().Be(EmailTemplateKeys.BillingEvent);
        stored.RecipientsJson.Should().Contain("billing@example.com");
        stored.IdempotencyKey.Should().Be($"alert:billing:status-transition:{transition.Id:N}");
        stored.CorrelationId.Should().Be($"billing:status:{transition.Id:N}");
        stored.TextBody.Should().Contain("PastDue");
        stored.TextBody.Should().Contain("Pro");
        stored.TextBody.Should().Contain("29 USD");
        stored.TextBody.Should().Contain("I-SUB-1001");
    }

    [Fact]
    public async Task Committed_Plan_Change_Uses_Target_Plan_Price_And_Independent_Idempotency_Key()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("plan.owner");
        await fixture.AddRecipientAsync(owner.Id, "plans@example.com");
        var starter = await fixture.AddPlanAsync("starter", "Starter", 9m, "USD", 1);
        var business = await fixture.AddPlanAsync("business", "Business", 49m, "USD", 2);
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, starter.Id, "I-SUB-2002");
        var occurredAt = DateTime.UtcNow.AddMinutes(-4);
        subscription.ChangePlan(business.Id, occurredAt);
        var change = new AccountSubscriptionPlanChange(
            subscription.Id,
            starter.Id,
            business.Id,
            SubscriptionTransitionSource.Provider,
            "Provider plan reconciliation",
            occurredAt,
            occurredAt);
        fixture.Context.AccountSubscriptionPlanChanges.Add(change);
        await fixture.Context.SaveChangesAsync();

        var first = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));
        var second = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        first.Enqueued.Should().Be(1);
        second.Enqueued.Should().Be(1);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(1);
        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.IdempotencyKey.Should().Be($"alert:billing:plan-change:{change.Id:N}");
        stored.CorrelationId.Should().Be($"billing:plan:{change.Id:N}");
        stored.TextBody.Should().Contain("Plan changed");
        stored.TextBody.Should().Contain("Starter");
        stored.TextBody.Should().Contain("Business");
        stored.TextBody.Should().Contain("49 USD");
    }

    [Fact]
    public async Task Relay_Uses_Subscription_Owner_Recipients_Without_Cross_Account_Leakage()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("tenant.owner");
        var other = await fixture.AddUserAsync("tenant.other");
        await fixture.AddRecipientAsync(owner.Id, "owner@example.com");
        await fixture.AddRecipientAsync(other.Id, "other@example.com");
        var plan = await fixture.AddPlanAsync("team", "Team", 19m, "USD", 1);
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, plan.Id);
        var occurredAt = DateTime.UtcNow.AddMinutes(-3);
        var transition = new AccountSubscriptionTransition(
            subscription.Id,
            AccountSubscriptionStatus.Active,
            AccountSubscriptionStatus.Cancelled,
            SubscriptionTransitionSource.Administration,
            "Account cancellation confirmed",
            occurredAt,
            null);
        fixture.Context.AccountSubscriptionTransitions.Add(transition);
        await fixture.Context.SaveChangesAsync();

        var result = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        result.Enqueued.Should().Be(1);
        var stored = await fixture.Context.EmailOutboxMessages.AsNoTracking().SingleAsync();
        stored.OwnerUserId.Should().Be(owner.Id);
        stored.RecipientsJson.Should().Contain("owner@example.com");
        stored.RecipientsJson.Should().NotContain("other@example.com");
    }

    [Fact]
    public async Task Subscription_Without_Committed_Audit_Record_Does_Not_Queue_Email()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("no-audit.owner");
        await fixture.AddRecipientAsync(owner.Id, "billing@example.com");
        var plan = await fixture.AddPlanAsync("pro", "Pro", 29m, "USD", 1);
        await fixture.AddSubscriptionAsync(owner.Id, plan.Id, "I-BROWSER-RETURN-ONLY");

        var result = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        result.Scanned.Should().Be(0);
        result.Enqueued.Should().Be(0);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task Disabled_Recipients_Cause_Deterministic_Skip_And_Restart_Can_Reevaluate()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("disabled-recipient.owner");
        await fixture.AddRecipientAsync(owner.Id, "disabled@example.com", enabled: false);
        var plan = await fixture.AddPlanAsync("pro", "Pro", 29m, "USD", 1);
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, plan.Id);
        var occurredAt = DateTime.UtcNow.AddMinutes(-2);
        var transition = new AccountSubscriptionTransition(
            subscription.Id,
            AccountSubscriptionStatus.Active,
            AccountSubscriptionStatus.PastDue,
            SubscriptionTransitionSource.System,
            "Lifecycle policy transition",
            occurredAt,
            null);
        fixture.Context.AccountSubscriptionTransitions.Add(transition);
        await fixture.Context.SaveChangesAsync();

        var skipped = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        skipped.Skipped.Should().Be(1);
        skipped.HandledEventKeys.Should().ContainSingle();
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(0);

        var recipient = await fixture.Context.AccountEmailRecipients.SingleAsync();
        recipient.Update("disabled@example.com", null, true, DateTime.UtcNow);
        await fixture.Context.SaveChangesAsync();

        var restarted = new SubscriptionBillingEmailAlertRelay(
            fixture.Context,
            new EmailTemplateRenderer(),
            new EmailOutboxService(fixture.Context),
            NullLogger<SubscriptionBillingEmailAlertRelay>.Instance);
        var retry = await restarted.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));

        retry.Enqueued.Should().Be(1);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(1);
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            Relay = new SubscriptionBillingEmailAlertRelay(
                context,
                new EmailTemplateRenderer(),
                new EmailOutboxService(context),
                NullLogger<SubscriptionBillingEmailAlertRelay>.Instance);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public SubscriptionBillingEmailAlertRelay Relay { get; }

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

        public async Task AddRecipientAsync(Guid ownerUserId, string emailAddress, bool enabled = true)
        {
            var recipient = new AccountEmailRecipient(ownerUserId, emailAddress, null, DateTime.UtcNow);
            if (!enabled)
                recipient.Update(emailAddress, null, false, DateTime.UtcNow);
            Context.AccountEmailRecipients.Add(recipient);
            await Context.SaveChangesAsync();
        }

        public async Task<SubscriptionPlan> AddPlanAsync(
            string code,
            string name,
            decimal price,
            string currency,
            int sortOrder)
        {
            var plan = new SubscriptionPlan(
                code,
                name,
                name,
                null,
                null,
                SubscriptionPlan.MonthlyInterval,
                price,
                currency,
                0,
                7,
                true,
                sortOrder,
                null,
                null,
                DateTime.UtcNow);
            Context.SubscriptionPlans.Add(plan);
            await Context.SaveChangesAsync();
            return plan;
        }

        public async Task<AccountSubscription> AddSubscriptionAsync(
            Guid ownerUserId,
            Guid planId,
            string? providerReference = null)
        {
            var now = DateTime.UtcNow;
            var subscription = new AccountSubscription(
                ownerUserId,
                planId,
                AccountSubscriptionStatus.Active,
                null,
                null,
                now.AddDays(-1),
                now.AddDays(29),
                now);
            if (!string.IsNullOrWhiteSpace(providerReference))
                subscription.BindProviderReference("paypal", providerReference, now);
            Context.AccountSubscriptions.Add(subscription);
            await Context.SaveChangesAsync();
            return subscription;
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
