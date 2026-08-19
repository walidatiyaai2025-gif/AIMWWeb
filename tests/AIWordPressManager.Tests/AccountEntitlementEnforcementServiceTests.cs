using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class AccountEntitlementEnforcementServiceTests
{
    [Fact]
    public async Task Missing_Subscription_Fails_Closed()
    {
        await using var fixture = await Fixture.CreateAsync();
        var action = () => fixture.Enforcement.RequireBooleanCapabilityAsync(
            Guid.NewGuid(),
            EntitlementDefinitionCatalog.AiEnabled);

        var error = await action.Should().ThrowAsync<AccountEntitlementDeniedException>();
        error.Which.Code.Should().Be("subscription_required");
        error.Which.EntitlementKey.Should().Be(EntitlementDefinitionCatalog.AiEnabled);
    }

    [Fact]
    public async Task Active_Platform_Administrator_Is_Not_Locked_Out_By_Tenant_Billing()
    {
        await using var fixture = await Fixture.CreateAsync();
        var admin = new AuthUser("Admin", "test-password-hash", DateTime.UtcNow, "Administrator");
        fixture.Context.AuthUsers.Add(admin);
        await fixture.Context.SaveChangesAsync();

        var booleanAction = () => fixture.Enforcement.RequireBooleanCapabilityAsync(
            admin.Id,
            EntitlementDefinitionCatalog.PremiumSeo);
        var limitAction = () => fixture.Enforcement.RequireAdditionalUsageAsync(
            admin.Id,
            EntitlementDefinitionCatalog.SitesMax,
            currentUsage: 100,
            requestedAdditional: 1);

        await booleanAction.Should().NotThrowAsync();
        await limitAction.Should().NotThrowAsync();
    }

    [Fact]
    public async Task Inactive_Administrator_Does_Not_Bypass_Subscription_Gate()
    {
        await using var fixture = await Fixture.CreateAsync();
        var admin = new AuthUser("DisabledAdmin", "test-password-hash", DateTime.UtcNow, "Administrator");
        admin.SetActive(false, DateTime.UtcNow);
        fixture.Context.AuthUsers.Add(admin);
        await fixture.Context.SaveChangesAsync();

        var action = () => fixture.Enforcement.RequireBooleanCapabilityAsync(
            admin.Id,
            EntitlementDefinitionCatalog.PremiumSeo);

        var error = await action.Should().ThrowAsync<AccountEntitlementDeniedException>();
        error.Which.Code.Should().Be("subscription_required");
    }

    [Fact]
    public async Task Missing_And_Disabled_Boolean_Entitlements_Are_Denied_Deterministically()
    {
        await using var fixture = await Fixture.CreateAsync();
        var account = await fixture.CreateActiveAccountAsync("starter");

        var missing = () => fixture.Enforcement.RequireBooleanCapabilityAsync(
            account.OwnerUserId,
            EntitlementDefinitionCatalog.AiEnabled);
        var missingError = await missing.Should().ThrowAsync<AccountEntitlementDeniedException>();
        missingError.Which.Code.Should().Be("subscription_entitlement_missing");

        await fixture.Entitlements.SetAsync(account.PlanId, EntitlementDefinitionCatalog.AiEnabled, "false");
        var disabled = () => fixture.Enforcement.RequireBooleanCapabilityAsync(
            account.OwnerUserId,
            EntitlementDefinitionCatalog.AiEnabled);
        var disabledError = await disabled.Should().ThrowAsync<AccountEntitlementDeniedException>();
        disabledError.Which.Code.Should().Be("subscription_feature_disabled");
    }

    [Fact]
    public async Task Enabled_Boolean_Entitlement_Is_Allowed()
    {
        await using var fixture = await Fixture.CreateAsync();
        var account = await fixture.CreateActiveAccountAsync("pro");
        await fixture.Entitlements.SetAsync(account.PlanId, EntitlementDefinitionCatalog.PremiumSeo, "true");

        var action = () => fixture.Enforcement.RequireBooleanCapabilityAsync(
            account.OwnerUserId,
            EntitlementDefinitionCatalog.PremiumSeo);

        await action.Should().NotThrowAsync();
    }

    [Fact]
    public async Task Integer_Limit_Allows_Exact_Boundary_And_Denies_The_Next_Unit()
    {
        await using var fixture = await Fixture.CreateAsync();
        var account = await fixture.CreateActiveAccountAsync("limited");
        await fixture.Entitlements.SetAsync(account.PlanId, EntitlementDefinitionCatalog.SitesMax, "3");

        var boundary = () => fixture.Enforcement.RequireAdditionalUsageAsync(
            account.OwnerUserId,
            EntitlementDefinitionCatalog.SitesMax,
            currentUsage: 2,
            requestedAdditional: 1);
        await boundary.Should().NotThrowAsync();

        var blocked = () => fixture.Enforcement.RequireAdditionalUsageAsync(
            account.OwnerUserId,
            EntitlementDefinitionCatalog.SitesMax,
            currentUsage: 3,
            requestedAdditional: 1);
        var error = await blocked.Should().ThrowAsync<AccountEntitlementDeniedException>();
        error.Which.Code.Should().Be("subscription_usage_limit_reached");
        error.Which.Limit.Should().Be(3);
        error.Which.CurrentUsage.Should().Be(3);
        error.Which.RequestedAdditional.Should().Be(1);
    }

    [Fact]
    public async Task Missing_Integer_Entitlement_Is_Denied_Instead_Of_Granting_Unlimited_Usage()
    {
        await using var fixture = await Fixture.CreateAsync();
        var account = await fixture.CreateActiveAccountAsync("missing-limit");

        var action = () => fixture.Enforcement.RequireAdditionalUsageAsync(
            account.OwnerUserId,
            EntitlementDefinitionCatalog.EmailSchedulesMax,
            currentUsage: 0,
            requestedAdditional: 1);

        var error = await action.Should().ThrowAsync<AccountEntitlementDeniedException>();
        error.Which.Code.Should().Be("subscription_entitlement_missing");
    }

    [Fact]
    public async Task Cancelled_Subscription_Cannot_Use_Configured_Features()
    {
        await using var fixture = await Fixture.CreateAsync();
        var account = await fixture.CreateActiveAccountAsync("cancelled");
        await fixture.Entitlements.SetAsync(account.PlanId, EntitlementDefinitionCatalog.AiEnabled, "true");

        var subscription = await fixture.Context.AccountSubscriptions.SingleAsync(x => x.OwnerUserId == account.OwnerUserId);
        subscription.TransitionTo(
            AccountSubscriptionStatus.Cancelled,
            SubscriptionTransitionSource.Administration,
            DateTime.UtcNow);
        await fixture.Context.SaveChangesAsync();

        var action = () => fixture.Enforcement.RequireBooleanCapabilityAsync(
            account.OwnerUserId,
            EntitlementDefinitionCatalog.AiEnabled);
        var error = await action.Should().ThrowAsync<AccountEntitlementDeniedException>();
        error.Which.Code.Should().Be("subscription_inactive");
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            Entitlements = new PlanEntitlementService(context);
            Plans = new SubscriptionPlanCatalog(context);
            Enforcement = new AccountEntitlementEnforcementService(context, Entitlements);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public PlanEntitlementService Entitlements { get; }
        public AccountEntitlementEnforcementService Enforcement { get; }
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

        public async Task<AccountFixture> CreateActiveAccountAsync(string code)
        {
            var plan = await Plans.CreateAsync(new SubscriptionPlanCreateRequest(
                code,
                $"{code} English",
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

            var now = DateTime.UtcNow;
            var user = new AuthUser($"billing-{Guid.NewGuid():N}", "test-password-hash", now);
            Context.AuthUsers.Add(user);
            await Context.SaveChangesAsync();

            Context.AccountSubscriptions.Add(new AccountSubscription(
                user.Id,
                plan.Id,
                AccountSubscriptionStatus.Active,
                null,
                null,
                now.AddDays(-1),
                now.AddDays(30),
                now));
            await Context.SaveChangesAsync();
            return new AccountFixture(user.Id, plan.Id);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }

    private sealed record AccountFixture(Guid OwnerUserId, Guid PlanId);
}