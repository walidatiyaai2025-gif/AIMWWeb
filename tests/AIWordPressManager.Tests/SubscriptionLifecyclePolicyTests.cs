using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class SubscriptionLifecyclePolicyTests
{
    private static readonly DateTime Now = new(2026, 8, 18, 19, 10, 0, DateTimeKind.Utc);

    [Fact]
    public void Expired_Trial_Does_Not_Infer_Activation()
    {
        var result = SubscriptionLifecyclePolicy.Evaluate(new(
            AccountSubscriptionStatus.Trialing, Now.AddMinutes(-1), null, false, null, 7), Now);
        result.RequiresTransition.Should().BeTrue();
        result.TargetStatus.Should().Be(AccountSubscriptionStatus.Expired);
    }

    [Fact]
    public void PastDue_Uses_Configured_Grace_Then_Suspends_When_Already_Elapsed()
    {
        var grace = SubscriptionLifecyclePolicy.Evaluate(new(
            AccountSubscriptionStatus.PastDue, null, Now, false, null, 3), Now);
        grace.TargetStatus.Should().Be(AccountSubscriptionStatus.Grace);
        grace.GraceUntilUtc.Should().Be(Now.AddDays(3));

        var elapsed = SubscriptionLifecyclePolicy.Evaluate(new(
            AccountSubscriptionStatus.PastDue, null, Now.AddDays(-4), false, null, 3), Now);
        elapsed.TargetStatus.Should().Be(AccountSubscriptionStatus.Suspended);
    }

    [Fact]
    public void Zero_Grace_Suspends_Immediately_And_Grace_Deadline_Is_Idempotent()
    {
        SubscriptionLifecyclePolicy.Evaluate(new(
            AccountSubscriptionStatus.PastDue, null, Now, false, null, 0), Now).TargetStatus
            .Should().Be(AccountSubscriptionStatus.Suspended);

        SubscriptionLifecyclePolicy.Evaluate(new(
            AccountSubscriptionStatus.Grace, null, null, false, Now.AddMinutes(1), 7), Now).RequiresTransition
            .Should().BeFalse();
        SubscriptionLifecyclePolicy.Evaluate(new(
            AccountSubscriptionStatus.Grace, null, null, false, Now, 7), Now).TargetStatus
            .Should().Be(AccountSubscriptionStatus.Suspended);
    }

    [Fact]
    public void CancelAtPeriodEnd_Expires_Only_After_Period_And_Terminal_States_NoOp()
    {
        SubscriptionLifecyclePolicy.Evaluate(new(
            AccountSubscriptionStatus.Active, null, Now.AddMinutes(1), true, null, 7), Now).RequiresTransition
            .Should().BeFalse();
        SubscriptionLifecyclePolicy.Evaluate(new(
            AccountSubscriptionStatus.Active, null, Now, true, null, 7), Now).TargetStatus
            .Should().Be(AccountSubscriptionStatus.Expired);
        SubscriptionLifecyclePolicy.Evaluate(new(
            AccountSubscriptionStatus.Expired, null, null, false, null, 7), Now).RequiresTransition
            .Should().BeFalse();
        SubscriptionLifecyclePolicy.Evaluate(new(
            AccountSubscriptionStatus.Suspended, null, null, false, null, 7), Now).RequiresTransition
            .Should().BeFalse();
    }

    [Fact]
    public async Task Policy_Service_Applies_Through_Audited_Transition_And_Reevaluation_Is_NoOp()
    {
        await using var fixture = await Fixture.CreateAsync(graceDays: 2);
        var subscription = await fixture.CreateSubscriptionAsync(
            AccountSubscriptionStatus.PastDue,
            currentPeriodStartUtc: Now.AddMonths(-1),
            currentPeriodEndsAtUtc: Now);

        var first = await fixture.Policy.EvaluateAsync(subscription.Id, Now);
        first.StatusChanged.Should().BeTrue();
        first.CurrentStatus.Should().Be(AccountSubscriptionStatus.Grace);
        first.GraceUntilUtc.Should().Be(Now.AddDays(2));
        (await fixture.Subscriptions.ListTransitionsAsync(subscription.Id)).Should().ContainSingle();

        var second = await fixture.Policy.EvaluateAsync(subscription.Id, Now.AddMinutes(1));
        second.StatusChanged.Should().BeFalse();
        (await fixture.Subscriptions.ListTransitionsAsync(subscription.Id)).Should().ContainSingle();
    }

    [Fact]
    public async Task Batch_Is_Bounded_And_Preserves_Account_Data()
    {
        await using var fixture = await Fixture.CreateAsync(graceDays: 0);
        var owner = await fixture.AddUserAsync("preserved-owner");
        var plan = await fixture.AddPlanAsync("preserved-plan", 0);
        var subscription = await fixture.Subscriptions.CreateAsync(new(
            owner.Id, plan.Id, AccountSubscriptionStatus.PastDue));

        var result = await fixture.Policy.EvaluateBatchAsync(Now, take: 1);
        result.Scanned.Should().Be(1);
        result.Changed.Should().Be(1);
        (await fixture.Context.AuthUsers.CountAsync(x => x.Id == owner.Id)).Should().Be(1);
        (await fixture.Context.SubscriptionPlans.CountAsync(x => x.Id == plan.Id)).Should().Be(1);
        (await fixture.Subscriptions.GetCurrentAsync(owner.Id))!.Status.Should().Be(AccountSubscriptionStatus.Suspended);
    }

    [Fact]
    public async Task Batch_Skips_NonDue_Rows_So_Bounded_Work_Cannot_Starve_Due_Subscriptions()
    {
        await using var fixture = await Fixture.CreateAsync(graceDays: 0);

        for (var i = 0; i < 3; i++)
        {
            var owner = await fixture.AddUserAsync($"healthy-owner-{i}");
            var plan = await fixture.AddPlanAsync($"healthy-plan-{i}", 0);
            await fixture.Subscriptions.CreateAsync(new(
                owner.Id,
                plan.Id,
                AccountSubscriptionStatus.Active,
                CurrentPeriodStartUtc: Now.AddDays(-1),
                CurrentPeriodEndsAtUtc: Now.AddDays(1)));
        }

        var dueOwner = await fixture.AddUserAsync("due-owner");
        var duePlan = await fixture.AddPlanAsync("due-plan", 0);
        var due = await fixture.Subscriptions.CreateAsync(new(
            dueOwner.Id,
            duePlan.Id,
            AccountSubscriptionStatus.PastDue));

        var result = await fixture.Policy.EvaluateBatchAsync(Now, take: 1);

        result.Scanned.Should().Be(1);
        result.Changed.Should().Be(1);
        (await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == due.Id)).Status
            .Should().Be(AccountSubscriptionStatus.Suspended);
        (await fixture.Context.AccountSubscriptions.AsNoTracking().CountAsync(x => x.Status == AccountSubscriptionStatus.Active))
            .Should().Be(3);
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context, int graceDays)
        {
            Connection = connection;
            Context = context;
            Plans = new SubscriptionPlanCatalog(context);
            Subscriptions = new AccountSubscriptionService(context);
            Policy = new SubscriptionLifecyclePolicyService(context, Subscriptions, NullLogger<SubscriptionLifecyclePolicyService>.Instance);
            GraceDays = graceDays;
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        private SubscriptionPlanCatalog Plans { get; }
        public AccountSubscriptionService Subscriptions { get; }
        public SubscriptionLifecyclePolicyService Policy { get; }
        private int GraceDays { get; }

        public static async Task<Fixture> CreateAsync(int graceDays)
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context, graceDays);
        }

        public async Task<AuthUser> AddUserAsync(string userName)
        {
            var user = new AuthUser(userName, "test-password-hash", DateTime.UtcNow);
            Context.AuthUsers.Add(user);
            await Context.SaveChangesAsync();
            return user;
        }

        public Task<SubscriptionPlanItem> AddPlanAsync(string code, int? graceDays = null) => Plans.CreateAsync(new(
            code, code, $"{code} عربي", null, null, SubscriptionPlan.MonthlyInterval,
            0m, "USD", 0, graceDays ?? GraceDays, true, 10));

        public async Task<AccountSubscriptionItem> CreateSubscriptionAsync(
            AccountSubscriptionStatus status,
            DateTime? currentPeriodStartUtc = null,
            DateTime? currentPeriodEndsAtUtc = null)
        {
            var owner = await AddUserAsync($"owner-{Guid.NewGuid():N}");
            var plan = await AddPlanAsync($"plan-{Guid.NewGuid():N}"[..20]);
            return await Subscriptions.CreateAsync(new(
                owner.Id, plan.Id, status, null, null, currentPeriodStartUtc, currentPeriodEndsAtUtc));
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
