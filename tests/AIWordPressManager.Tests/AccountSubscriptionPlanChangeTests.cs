using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class AccountSubscriptionPlanChangeTests
{
    [Fact]
    public async Task Provider_Plan_Change_Updates_Plan_And_Persists_Audit_Together()
    {
        await using var fixture = await Fixture.CreateAsync();
        var source = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.Active, "source");
        var target = await fixture.AddPlanAsync("target");
        var observedAt = DateTime.UtcNow.AddMinutes(1);

        var result = await fixture.Service.ChangePlanAsync(source.Id, new(
            target.Id,
            SubscriptionTransitionSource.Provider,
            "Authoritative provider plan reconciliation.",
            observedAt,
            observedAt));

        result.PlanChanged.Should().BeTrue();
        result.Subscription.PlanId.Should().Be(target.Id);
        result.Change.Should().NotBeNull();
        result.Change!.FromPlanId.Should().Be(source.PlanId);
        result.Change.ToPlanId.Should().Be(target.Id);
        result.Change.ProviderObservedAtUtc.Should().Be(observedAt);

        var history = await fixture.Service.ListPlanChangesAsync(source.Id);
        history.Should().ContainSingle();
        history[0].Source.Should().Be(SubscriptionTransitionSource.Provider);
        (await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == source.Id)).PlanId.Should().Be(target.Id);
    }

    [Fact]
    public async Task Same_Plan_Is_A_NoOp_And_Does_Not_Create_Fake_Audit()
    {
        await using var fixture = await Fixture.CreateAsync();
        var source = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.Active, "same");
        var at = DateTime.UtcNow.AddMinutes(1);

        var result = await fixture.Service.ChangePlanAsync(source.Id, new(
            source.PlanId,
            SubscriptionTransitionSource.Administration,
            "Administrative plan confirmation.",
            at));

        result.PlanChanged.Should().BeFalse();
        result.Change.Should().BeNull();
        (await fixture.Service.ListPlanChangesAsync(source.Id)).Should().BeEmpty();
    }

    [Fact]
    public async Task Provider_Change_Requires_Provider_Observation_And_NonProvider_Change_Rejects_It()
    {
        await using var fixture = await Fixture.CreateAsync();
        var source = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.Active, "validation-source");
        var target = await fixture.AddPlanAsync("validation-target");
        var at = DateTime.UtcNow.AddMinutes(1);

        await FluentActions.Invoking(() => fixture.Service.ChangePlanAsync(source.Id, new(
                target.Id,
                SubscriptionTransitionSource.Provider,
                "Missing provider observation.",
                at)))
            .Should().ThrowAsync<ArgumentException>();

        await FluentActions.Invoking(() => fixture.Service.ChangePlanAsync(source.Id, new(
                target.Id,
                SubscriptionTransitionSource.System,
                "Unexpected provider observation.",
                at,
                at)))
            .Should().ThrowAsync<ArgumentException>();

        (await fixture.Service.ListPlanChangesAsync(source.Id)).Should().BeEmpty();
        (await fixture.Service.GetCurrentAsync(source.OwnerUserId))!.PlanId.Should().Be(source.PlanId);
    }

    [Fact]
    public async Task Expired_Subscription_Cannot_Change_Plan_And_No_Audit_Is_Created()
    {
        await using var fixture = await Fixture.CreateAsync();
        var source = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.Cancelled, "expired-source");
        await fixture.Service.TransitionAsync(source.Id, new(
            AccountSubscriptionStatus.Expired,
            SubscriptionTransitionSource.System,
            "Subscription reached terminal expiry.",
            DateTime.UtcNow.AddMinutes(1)));
        var target = await fixture.AddPlanAsync("expired-target");

        await FluentActions.Invoking(() => fixture.Service.ChangePlanAsync(source.Id, new(
                target.Id,
                SubscriptionTransitionSource.Administration,
                "Invalid expired plan change.",
                DateTime.UtcNow.AddMinutes(2))))
            .Should().ThrowAsync<InvalidOperationException>().WithMessage("*expired subscription cannot change plans*");

        (await fixture.Service.ListPlanChangesAsync(source.Id)).Should().BeEmpty();
        (await fixture.Service.GetCurrentAsync(source.OwnerUserId))!.PlanId.Should().Be(source.PlanId);
    }

    [Fact]
    public async Task Unknown_Target_Plan_Fails_Before_Mutating_Subscription()
    {
        await using var fixture = await Fixture.CreateAsync();
        var source = await fixture.CreateSubscriptionAsync(AccountSubscriptionStatus.Active, "missing-target");

        await FluentActions.Invoking(() => fixture.Service.ChangePlanAsync(source.Id, new(
                Guid.NewGuid(),
                SubscriptionTransitionSource.Administration,
                "Unknown target.",
                DateTime.UtcNow.AddMinutes(1))))
            .Should().ThrowAsync<KeyNotFoundException>();

        (await fixture.Service.GetCurrentAsync(source.OwnerUserId))!.PlanId.Should().Be(source.PlanId);
        (await fixture.Service.ListPlanChangesAsync(source.Id)).Should().BeEmpty();
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
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context);
        }

        public async Task<SubscriptionPlanItem> AddPlanAsync(string code) => await Plans.CreateAsync(new(
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
            null,
            $"P-{code.ToUpperInvariant()}"));

        public async Task<AccountSubscriptionItem> CreateSubscriptionAsync(AccountSubscriptionStatus status, string planCode)
        {
            var owner = new AuthUser($"owner-{Guid.NewGuid():N}", "test-password-hash", DateTime.UtcNow);
            Context.AuthUsers.Add(owner);
            await Context.SaveChangesAsync();
            var plan = await AddPlanAsync(planCode);
            return await Service.CreateAsync(new(owner.Id, plan.Id, status));
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
