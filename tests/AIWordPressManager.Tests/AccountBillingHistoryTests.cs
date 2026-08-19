using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using AIWordPressManager.Persistence.Email;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class AccountBillingHistoryTests
{
    [Fact]
    public async Task History_Composes_Committed_Audits_In_Deterministic_Order_With_Email_State()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("history.owner");
        await fixture.AddRecipientAsync(owner.Id, "billing@example.com");
        var starter = await fixture.AddPlanAsync("history-starter", "Starter", 9m, "USD", 1);
        var business = await fixture.AddPlanAsync("history-business", "Business", 49m, "USD", 2);
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, starter.Id, "I-SUB-HISTORY-1001");
        var transitionAt = DateTime.UtcNow.AddMinutes(-10);
        var planChangeAt = DateTime.UtcNow.AddMinutes(-5);

        var transition = await fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.PastDue,
            SubscriptionTransitionSource.Provider,
            "internal provider reason with secret-reference-I-SUB-HISTORY-1001",
            transitionAt,
            transitionAt));
        var planChange = await fixture.Service.ChangePlanAsync(subscription.Id, new(
            business.Id,
            SubscriptionTransitionSource.Provider,
            "internal revise payload details should not be customer visible",
            planChangeAt,
            planChangeAt));

        var relay = await fixture.Relay.RelayPendingAsync(DateTime.UtcNow.AddHours(-1));
        relay.Enqueued.Should().Be(2);

        var transitionMessage = await fixture.Context.EmailOutboxMessages.SingleAsync(
            x => x.IdempotencyKey == $"alert:billing:status-transition:{transition.Transition!.Id:N}");
        transitionMessage.Claim("history-status-claim", DateTime.UtcNow);
        transitionMessage.MarkSent(DateTime.UtcNow.AddSeconds(1));

        var planMessage = await fixture.Context.EmailOutboxMessages.SingleAsync(
            x => x.IdempotencyKey == $"alert:billing:plan-change:{planChange.Change!.Id:N}");
        var failureAt = DateTime.UtcNow.AddSeconds(2);
        planMessage.Claim("history-plan-claim", failureAt);
        planMessage.MarkDeliveryFailure("Transient SMTP failure.", failureAt.AddMinutes(5), failureAt);
        await fixture.Context.SaveChangesAsync();

        var history = await fixture.Service.ListBillingHistoryAsync(owner.Id, subscription.Id, 100);
        var repeated = await fixture.Service.ListBillingHistoryAsync(owner.Id, subscription.Id, 100);

        history.Should().HaveCount(2);
        history.Select(x => x.EventId).Should().Equal(repeated.Select(x => x.EventId));
        history[0].Kind.Should().Be(AccountBillingHistoryKind.PlanChange);
        history[0].FromPlanNameEn.Should().Be("Starter");
        history[0].ToPlanNameEn.Should().Be("Business");
        history[0].Amount.Should().Be(49m);
        history[0].Currency.Should().Be("USD");
        history[0].ProviderEvidenceAtUtc.Should().Be(planChangeAt);
        history[0].NotificationState.Should().Be(AccountBillingNotificationState.Retrying);
        history[0].NotificationAttemptCount.Should().Be(1);
        history[0].NotificationNextAttemptAtUtc.Should().Be(failureAt.AddMinutes(5));

        history[1].Kind.Should().Be(AccountBillingHistoryKind.StatusTransition);
        history[1].FromStatus.Should().Be(AccountSubscriptionStatus.Active);
        history[1].ToStatus.Should().Be(AccountSubscriptionStatus.PastDue);
        history[1].ProviderEvidenceAtUtc.Should().Be(transitionAt);
        history[1].NotificationState.Should().Be(AccountBillingNotificationState.Sent);
        history[1].NotificationSentAtUtc.Should().NotBeNull();

        history.Select(x => x.Reason).Should().OnlyContain(x => !x.Contains("secret-reference", StringComparison.OrdinalIgnoreCase));
        history.Select(x => x.Reason).Should().OnlyContain(x => !x.Contains("payload", StringComparison.OrdinalIgnoreCase));
    }

    [Fact]
    public async Task History_Rejects_Cross_Account_Subscription_Access()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("history.tenant.owner");
        var other = await fixture.AddUserAsync("history.tenant.other");
        var plan = await fixture.AddPlanAsync("history-team", "Team", 19m, "USD", 1);
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, plan.Id);
        await fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.PastDue,
            SubscriptionTransitionSource.System,
            "Internal lifecycle check.",
            DateTime.UtcNow.AddMinutes(-1)));

        var crossAccount = () => fixture.Service.ListBillingHistoryAsync(other.Id, subscription.Id, 100);

        await crossAccount.Should().ThrowAsync<KeyNotFoundException>()
            .WithMessage("*current owner*");
    }

    [Fact]
    public async Task History_Reports_NotConfigured_When_Billing_Recipients_Are_Missing_Or_Disabled()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("history.no-recipient");
        await fixture.AddRecipientAsync(owner.Id, "disabled@example.com", enabled: false);
        var plan = await fixture.AddPlanAsync("history-basic", "Basic", 5m, "USD", 1);
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, plan.Id);
        await fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.PastDue,
            SubscriptionTransitionSource.System,
            "Lifecycle policy changed status.",
            DateTime.UtcNow.AddMinutes(-1)));

        var history = await fixture.Service.ListBillingHistoryAsync(owner.Id, subscription.Id, 100);

        history.Should().ContainSingle();
        history[0].NotificationState.Should().Be(AccountBillingNotificationState.NotConfigured);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task History_Reports_NotQueued_When_Recipient_Exists_But_Relay_Has_Not_Produced_Outbox_Row()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("history.awaiting-relay");
        await fixture.AddRecipientAsync(owner.Id, "enabled@example.com");
        var plan = await fixture.AddPlanAsync("history-plus", "Plus", 15m, "USD", 1);
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, plan.Id);
        await fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.PastDue,
            SubscriptionTransitionSource.Provider,
            "Provider detail should remain internal.",
            DateTime.UtcNow.AddMinutes(-1),
            DateTime.UtcNow.AddMinutes(-1)));

        var history = await fixture.Service.ListBillingHistoryAsync(owner.Id, subscription.Id, 100);

        history.Should().ContainSingle();
        history[0].NotificationState.Should().Be(AccountBillingNotificationState.NotQueued);
        (await fixture.Context.EmailOutboxMessages.CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task History_Contains_No_Event_When_Only_Subscription_Or_Browser_Navigation_State_Exists()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("history.no-audit");
        await fixture.AddRecipientAsync(owner.Id, "billing@example.com");
        var plan = await fixture.AddPlanAsync("history-pro", "Pro", 29m, "USD", 1);
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, plan.Id, "I-BROWSER-RETURN-ONLY");

        var history = await fixture.Service.ListBillingHistoryAsync(owner.Id, subscription.Id, 100);

        history.Should().BeEmpty();
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            Service = new AccountSubscriptionService(context);
            Relay = new SubscriptionBillingEmailAlertRelay(
                context,
                new EmailTemplateRenderer(),
                new EmailOutboxService(context),
                NullLogger<SubscriptionBillingEmailAlertRelay>.Instance);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public AccountSubscriptionService Service { get; }
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
                $"{name} عربي",
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
