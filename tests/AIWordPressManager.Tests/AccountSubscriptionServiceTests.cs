using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class AccountSubscriptionServiceTests
{
    [Fact]
    public void State_Machine_Exposes_Seven_Canonical_States_And_Expired_Is_Terminal()
    {
        Enum.GetValues<AccountSubscriptionStatus>().Should().HaveCount(7);
        AccountSubscriptionStateMachine.CanTransition(AccountSubscriptionStatus.Active, AccountSubscriptionStatus.PastDue).Should().BeTrue();
        AccountSubscriptionStateMachine.CanTransition(AccountSubscriptionStatus.PastDue, AccountSubscriptionStatus.Active).Should().BeTrue();
        AccountSubscriptionStateMachine.CanTransition(AccountSubscriptionStatus.Cancelled, AccountSubscriptionStatus.Active).Should().BeTrue();
        foreach (var target in Enum.GetValues<AccountSubscriptionStatus>().Where(x => x != AccountSubscriptionStatus.Expired))
            AccountSubscriptionStateMachine.CanTransition(AccountSubscriptionStatus.Expired, target).Should().BeFalse();
    }

    [Fact]
    public async Task Create_Requires_Existing_Owner_And_Plan_And_Enforces_One_Current_Row()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("subscriber");
        var plan = await fixture.AddPlanAsync("starter");
        var created = await fixture.Service.CreateAsync(new(owner.Id, plan.Id, AccountSubscriptionStatus.Active));

        created.OwnerUserId.Should().Be(owner.Id);
        created.PlanId.Should().Be(plan.Id);
        var duplicate = () => fixture.Service.CreateAsync(new(owner.Id, plan.Id, AccountSubscriptionStatus.Active));
        await duplicate.Should().ThrowAsync<InvalidOperationException>().WithMessage("*already has a current subscription*");
        var missingOwner = () => fixture.Service.CreateAsync(new(Guid.NewGuid(), plan.Id, AccountSubscriptionStatus.Active));
        await missingOwner.Should().ThrowAsync<KeyNotFoundException>();
    }

    [Fact]
    public async Task Real_Status_Change_And_Audit_Are_Persisted_Together()
    {
        await using var fixture = await Fixture.CreateAsync();
        var subscription = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.Active);
        var at = DateTime.UtcNow.AddMinutes(1);

        var result = await fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.PastDue,
            SubscriptionTransitionSource.System,
            "Renewal attempt requires attention.",
            at));

        result.StatusChanged.Should().BeTrue();
        result.Subscription.Status.Should().Be(AccountSubscriptionStatus.PastDue);
        result.Transition.Should().NotBeNull();
        var history = await fixture.Service.ListTransitionsAsync(subscription.Id);
        history.Should().ContainSingle();
        history[0].FromStatus.Should().Be(AccountSubscriptionStatus.Active);
        history[0].ToStatus.Should().Be(AccountSubscriptionStatus.PastDue);
    }

    [Fact]
    public async Task Same_State_Request_Creates_No_Fake_Audit_But_Newer_Provider_Event_Advances_Watermark()
    {
        await using var fixture = await Fixture.CreateAsync();
        var subscription = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.Active);
        var providerAt = DateTime.UtcNow.AddMinutes(2);

        var result = await fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.Active,
            SubscriptionTransitionSource.Provider,
            "Provider confirmed active state.",
            providerAt,
            providerAt));

        result.StatusChanged.Should().BeFalse();
        result.Transition.Should().BeNull();
        result.Subscription.LastProviderEventAtUtc.Should().Be(providerAt);
        (await fixture.Service.ListTransitionsAsync(subscription.Id)).Should().BeEmpty();
    }

    [Fact]
    public async Task Stale_Or_Duplicate_Provider_Event_Cannot_Overwrite_Newer_State()
    {
        await using var fixture = await Fixture.CreateAsync();
        var subscription = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.Active);
        var newer = DateTime.UtcNow.AddMinutes(10);
        await fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.PastDue,
            SubscriptionTransitionSource.Provider,
            "Provider event newer.",
            newer,
            newer));

        var stale = () => fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.Active,
            SubscriptionTransitionSource.Provider,
            "Provider event stale.",
            newer.AddMinutes(1),
            newer.AddSeconds(-1)));
        await stale.Should().ThrowAsync<InvalidOperationException>().WithMessage("*stale or duplicated*");

        var reloaded = await fixture.Service.GetCurrentAsync(subscription.OwnerUserId);
        reloaded!.Status.Should().Be(AccountSubscriptionStatus.PastDue);
        reloaded.LastProviderEventAtUtc.Should().Be(newer);
        (await fixture.Service.ListTransitionsAsync(subscription.Id)).Should().ContainSingle();
    }

    [Fact]
    public async Task Invalid_Transition_Does_Not_Dirty_Tracked_State_Or_Create_Audit()
    {
        await using var fixture = await Fixture.CreateAsync();
        var subscription = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.Cancelled);
        await fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.Expired,
            SubscriptionTransitionSource.System,
            "Retention period ended.",
            DateTime.UtcNow.AddMinutes(1)));

        var invalid = () => fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.Active,
            SubscriptionTransitionSource.System,
            "Illegal reactivation of expired row.",
            DateTime.UtcNow.AddMinutes(2)));
        await invalid.Should().ThrowAsync<InvalidOperationException>();
        await fixture.Context.SaveChangesAsync();

        var row = await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == subscription.Id);
        row.Status.Should().Be(AccountSubscriptionStatus.Expired);
        (await fixture.Service.ListTransitionsAsync(subscription.Id)).Should().ContainSingle();
    }

    [Fact]
    public async Task Grace_Requires_Future_Utc_Bound_And_Leaving_Grace_Clears_It()
    {
        await using var fixture = await Fixture.CreateAsync();
        var subscription = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.PastDue);
        var at = DateTime.UtcNow.AddMinutes(1);
        var until = at.AddDays(3);

        var grace = await fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.Grace, SubscriptionTransitionSource.System, "Grace policy applied.", at, null, until));
        grace.Subscription.GraceUntilUtc.Should().Be(until);

        var active = await fixture.Service.TransitionAsync(subscription.Id, new(
            AccountSubscriptionStatus.Active, SubscriptionTransitionSource.System, "Payment recovered.", at.AddMinutes(1)));
        active.Subscription.GraceUntilUtc.Should().BeNull();
    }

    [Fact]
    public async Task Period_Cancel_And_Provider_Reference_Updates_Are_Validated_And_Durable()
    {
        await using var fixture = await Fixture.CreateAsync();
        var subscription = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.Active);
        var start = DateTime.UtcNow;
        var end = start.AddMonths(1);

        var periods = await fixture.Service.UpdatePeriodsAsync(subscription.Id, new(null, null, start, end));
        periods.CurrentPeriodEndsAtUtc.Should().Be(end);
        (await fixture.Service.SetCancelAtPeriodEndAsync(subscription.Id, true)).CancelAtPeriodEnd.Should().BeTrue();
        var bound = await fixture.Service.BindProviderReferenceAsync(subscription.Id, "gateway-x", "external-sub-1");
        bound.ProviderKey.Should().Be("gateway-x");

        var invalid = () => fixture.Service.BindProviderReferenceAsync(subscription.Id, "gateway-x", null);
        await invalid.Should().ThrowAsync<ArgumentException>();
        await fixture.Context.SaveChangesAsync();
        (await fixture.Service.GetCurrentAsync(subscription.OwnerUserId))!.ProviderSubscriptionReference.Should().Be("external-sub-1");
    }

    [Fact]
    public async Task Sqlite_Migration_Creates_Subscription_And_Audit_Tables_With_Unique_Owner_Index()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
        await connection.OpenAsync();
        var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
        await using var context = new AppDbContext(options);
        await context.Database.MigrateAsync();

        foreach (var name in new[] { "AccountSubscriptions", "AccountSubscriptionTransitions" })
        {
            await using var command = connection.CreateCommand();
            command.CommandText = $"SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{name}';";
            Convert.ToInt32(await command.ExecuteScalarAsync()).Should().Be(1);
        }
        await using var indexCommand = connection.CreateCommand();
        indexCommand.CommandText = "SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='IX_AccountSubscriptions_OwnerUserId';";
        Convert.ToInt32(await indexCommand.ExecuteScalarAsync()).Should().Be(1);
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            Service = new AccountSubscriptionService(context);
            Plans = new SubscriptionPlanCatalog(context);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public AccountSubscriptionService Service { get; }
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
            code, code, $"{code} عربي", null, null, SubscriptionPlan.MonthlyInterval,
            0m, "USD", 0, 0, true, 10));

        public async Task<AccountSubscriptionItem> CreateSubscriptionAsync(AccountSubscriptionStatus status)
        {
            var owner = await AddUserAsync($"owner-{Guid.NewGuid():N}");
            var plan = await AddPlanAsync($"plan-{Guid.NewGuid():N}"[..20]);
            return await Service.CreateAsync(new(owner.Id, plan.Id, status));
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
