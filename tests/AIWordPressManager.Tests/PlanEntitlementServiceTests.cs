using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class PlanEntitlementServiceTests
{
    [Fact]
    public void Canonical_Definitions_Match_Approved_Initial_Entitlement_Keys()
    {
        var definitions = EntitlementDefinitionCatalog.All;

        definitions.Select(x => x.Key).Should().BeEquivalentTo(
            EntitlementDefinitionCatalog.SitesMax,
            EntitlementDefinitionCatalog.EmailSiteRecipientsMax,
            EntitlementDefinitionCatalog.EmailSchedulesMax,
            EntitlementDefinitionCatalog.EmailDashboardDigest,
            EntitlementDefinitionCatalog.AutomationSchedulesMax,
            EntitlementDefinitionCatalog.AiEnabled,
            EntitlementDefinitionCatalog.AiMonthlyRequestsMax,
            EntitlementDefinitionCatalog.BackupRetentionDays,
            EntitlementDefinitionCatalog.PremiumSeo);
        definitions.Should().HaveCount(9);
        definitions.Single(x => x.Key == EntitlementDefinitionCatalog.SitesMax).ValueType
            .Should().Be(EntitlementValueType.Integer);
        definitions.Single(x => x.Key == EntitlementDefinitionCatalog.EmailDashboardDigest).ValueType
            .Should().Be(EntitlementValueType.Boolean);
        definitions.Where(x => x.ValueType == EntitlementValueType.Integer)
            .Should().OnlyContain(x => x.RequiresNonNegativeNumber);
    }

    [Fact]
    public void Value_Codec_Canonicalizes_All_Supported_Types_Invariantly()
    {
        EntitlementValueCodec.Canonicalize(" TRUE ", EntitlementValueType.Boolean).Should().Be("true");
        EntitlementValueCodec.Canonicalize("00042", EntitlementValueType.Integer).Should().Be("42");
        EntitlementValueCodec.Canonicalize("0012.3400", EntitlementValueType.Decimal).Should().Be("12.34");
        EntitlementValueCodec.Canonicalize("  eu-west  ", EntitlementValueType.String).Should().Be("eu-west");

        EntitlementValueCodec.ParseBoolean("true").Should().BeTrue();
        EntitlementValueCodec.ParseInteger("42").Should().Be(42);
        EntitlementValueCodec.ParseDecimal("12.34").Should().Be(12.34m);
    }

    [Fact]
    public void Value_Codec_Rejects_Type_Mismatch_And_Negative_Bounded_Limits()
    {
        var invalidBoolean = () => EntitlementValueCodec.Canonicalize("yes", EntitlementValueType.Boolean);
        var invalidInteger = () => EntitlementValueCodec.Canonicalize("1.5", EntitlementValueType.Integer);
        var invalidDecimal = () => EntitlementValueCodec.Canonicalize("1,5", EntitlementValueType.Decimal);
        var negativeLimit = () => EntitlementValueCodec.Canonicalize("-1", EntitlementValueType.Integer, true);

        invalidBoolean.Should().Throw<ArgumentException>();
        invalidInteger.Should().Throw<ArgumentException>();
        invalidDecimal.Should().Throw<ArgumentException>();
        negativeLimit.Should().Throw<ArgumentOutOfRangeException>();
    }

    [Fact]
    public async Task Missing_Entitlement_Is_Explicit_And_Does_Not_Grant_Capability_Or_Limit()
    {
        await using var fixture = await Fixture.CreateAsync();
        var plan = await fixture.CreatePlanAsync("starter");

        var boolean = await fixture.Service.CheckBooleanCapabilityAsync(
            plan.Id,
            EntitlementDefinitionCatalog.AiEnabled);
        var integer = await fixture.Service.CheckIntegerLimitAsync(
            plan.Id,
            EntitlementDefinitionCatalog.SitesMax,
            currentUsage: 0,
            requestedAdditional: 1);
        var raw = await fixture.Service.ResolveAsync(plan.Id, EntitlementDefinitionCatalog.PremiumSeo);

        boolean.IsConfigured.Should().BeFalse();
        boolean.IsEnabled.Should().BeFalse();
        integer.IsConfigured.Should().BeFalse();
        integer.Limit.Should().BeNull();
        integer.IsAllowed.Should().BeFalse();
        raw.IsConfigured.Should().BeFalse();
        raw.CanonicalValue.Should().BeNull();
    }

    [Fact]
    public async Task Set_Upserts_Canonical_Value_And_Resolver_Returns_Typed_Value()
    {
        await using var fixture = await Fixture.CreateAsync();
        var plan = await fixture.CreatePlanAsync("pro");

        var first = await fixture.Service.SetAsync(
            plan.Id,
            "AI.ENABLED",
            "TRUE");
        var updated = await fixture.Service.SetAsync(
            plan.Id,
            EntitlementDefinitionCatalog.AiEnabled,
            "false");
        var resolved = await fixture.Service.ResolveAsync(plan.Id, EntitlementDefinitionCatalog.AiEnabled);

        updated.Id.Should().Be(first.Id);
        updated.Key.Should().Be(EntitlementDefinitionCatalog.AiEnabled);
        updated.CanonicalValue.Should().Be("false");
        resolved.IsConfigured.Should().BeTrue();
        resolved.ValueType.Should().Be(EntitlementValueType.Boolean);
        resolved.BooleanValue.Should().BeFalse();
        (await fixture.Context.PlanEntitlements.CountAsync(x => x.PlanId == plan.Id)).Should().Be(1);
    }

    [Fact]
    public async Task Integer_Limit_Check_Is_Server_Reusable_And_Overflow_Safe()
    {
        await using var fixture = await Fixture.CreateAsync();
        var plan = await fixture.CreatePlanAsync("limited");
        await fixture.Service.SetAsync(plan.Id, EntitlementDefinitionCatalog.SitesMax, "3");

        var allowed = await fixture.Service.CheckIntegerLimitAsync(
            plan.Id,
            EntitlementDefinitionCatalog.SitesMax,
            currentUsage: 2,
            requestedAdditional: 1);
        var blocked = await fixture.Service.CheckIntegerLimitAsync(
            plan.Id,
            EntitlementDefinitionCatalog.SitesMax,
            currentUsage: 3,
            requestedAdditional: 1);
        var alreadyOver = await fixture.Service.CheckIntegerLimitAsync(
            plan.Id,
            EntitlementDefinitionCatalog.SitesMax,
            currentUsage: long.MaxValue,
            requestedAdditional: long.MaxValue);

        allowed.IsConfigured.Should().BeTrue();
        allowed.Limit.Should().Be(3);
        allowed.IsAllowed.Should().BeTrue();
        blocked.IsAllowed.Should().BeFalse();
        alreadyOver.IsAllowed.Should().BeFalse();
    }

    [Fact]
    public async Task Same_Key_Is_Isolated_Per_Plan_And_List_Is_Stable()
    {
        await using var fixture = await Fixture.CreateAsync();
        var starter = await fixture.CreatePlanAsync("starter");
        var pro = await fixture.CreatePlanAsync("pro");

        await fixture.Service.SetAsync(starter.Id, EntitlementDefinitionCatalog.SitesMax, "1");
        await fixture.Service.SetAsync(pro.Id, EntitlementDefinitionCatalog.SitesMax, "10");
        await fixture.Service.SetAsync(pro.Id, EntitlementDefinitionCatalog.AiEnabled, "true");

        var starterValue = await fixture.Service.ResolveAsync(starter.Id, EntitlementDefinitionCatalog.SitesMax);
        var proValue = await fixture.Service.ResolveAsync(pro.Id, EntitlementDefinitionCatalog.SitesMax);
        var proList = await fixture.Service.ListAsync(pro.Id);

        starterValue.IntegerValue.Should().Be(1);
        proValue.IntegerValue.Should().Be(10);
        proList.Select(x => x.Key).Should().Equal(
            EntitlementDefinitionCatalog.AiEnabled,
            EntitlementDefinitionCatalog.SitesMax);
    }

    [Fact]
    public async Task Remove_Returns_Explicit_State_And_Restores_Missing_Resolution()
    {
        await using var fixture = await Fixture.CreateAsync();
        var plan = await fixture.CreatePlanAsync("removable");
        await fixture.Service.SetAsync(plan.Id, EntitlementDefinitionCatalog.PremiumSeo, "true");

        (await fixture.Service.RemoveAsync(plan.Id, EntitlementDefinitionCatalog.PremiumSeo)).Should().BeTrue();
        (await fixture.Service.RemoveAsync(plan.Id, EntitlementDefinitionCatalog.PremiumSeo)).Should().BeFalse();
        (await fixture.Service.ResolveAsync(plan.Id, EntitlementDefinitionCatalog.PremiumSeo)).IsConfigured.Should().BeFalse();
    }

    [Fact]
    public async Task Unknown_Key_And_Type_Mismatch_Are_Rejected_Before_Persistence()
    {
        await using var fixture = await Fixture.CreateAsync();
        var plan = await fixture.CreatePlanAsync("safe");

        var unknown = () => fixture.Service.SetAsync(plan.Id, "sites.typo", "3");
        var wrongBoolean = () => fixture.Service.SetAsync(plan.Id, EntitlementDefinitionCatalog.AiEnabled, "1");
        var negativeLimit = () => fixture.Service.SetAsync(plan.Id, EntitlementDefinitionCatalog.SitesMax, "-1");
        var wrongChecker = () => fixture.Service.CheckBooleanCapabilityAsync(plan.Id, EntitlementDefinitionCatalog.SitesMax);

        await unknown.Should().ThrowAsync<ArgumentException>();
        await wrongBoolean.Should().ThrowAsync<ArgumentException>();
        await negativeLimit.Should().ThrowAsync<ArgumentOutOfRangeException>();
        await wrongChecker.Should().ThrowAsync<ArgumentException>();
        (await fixture.Context.PlanEntitlements.CountAsync(x => x.PlanId == plan.Id)).Should().Be(0);
    }

    [Fact]
    public async Task Missing_Plan_Is_Rejected_And_Foreign_Key_Cascade_Removes_Entitlements()
    {
        await using var fixture = await Fixture.CreateAsync();
        var missing = () => fixture.Service.SetAsync(Guid.NewGuid(), EntitlementDefinitionCatalog.AiEnabled, "true");
        await missing.Should().ThrowAsync<KeyNotFoundException>();

        var plan = await fixture.CreatePlanAsync("cascade");
        await fixture.Service.SetAsync(plan.Id, EntitlementDefinitionCatalog.AiEnabled, "true");
        var trackedPlan = await fixture.Context.SubscriptionPlans.SingleAsync(x => x.Id == plan.Id);
        fixture.Context.SubscriptionPlans.Remove(trackedPlan);
        await fixture.Context.SaveChangesAsync();

        (await fixture.Context.PlanEntitlements.CountAsync(x => x.PlanId == plan.Id)).Should().Be(0);
    }

    [Fact]
    public async Task Database_Enforces_Case_Insensitive_Key_Uniqueness_Per_Plan()
    {
        await using var fixture = await Fixture.CreateAsync();
        var plan = await fixture.CreatePlanAsync("db-unique");
        fixture.Context.PlanEntitlements.Add(new PlanEntitlement(
            plan.Id,
            EntitlementDefinitionCatalog.SitesMax,
            "3",
            DateTime.UtcNow));
        await fixture.Context.SaveChangesAsync();

        fixture.Context.PlanEntitlements.Add(new PlanEntitlement(
            plan.Id,
            "SITES.MAX",
            "4",
            DateTime.UtcNow));
        var duplicate = () => fixture.Context.SaveChangesAsync();

        await duplicate.Should().ThrowAsync<DbUpdateException>();
    }

    [Fact]
    public async Task Sqlite_Migration_Creates_Entitlement_Table_And_Unique_Index()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
        await connection.OpenAsync();
        var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
        await using var context = new AppDbContext(options);

        await context.Database.MigrateAsync();

        await using var tableCommand = connection.CreateCommand();
        tableCommand.CommandText = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='PlanEntitlements';";
        Convert.ToInt32(await tableCommand.ExecuteScalarAsync()).Should().Be(1);

        await using var indexCommand = connection.CreateCommand();
        indexCommand.CommandText = "SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='IX_PlanEntitlements_PlanId_NormalizedKey';";
        Convert.ToInt32(await indexCommand.ExecuteScalarAsync()).Should().Be(1);
    }

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            Service = new PlanEntitlementService(context);
            Plans = new SubscriptionPlanCatalog(context);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public PlanEntitlementService Service { get; }
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

        public Task<SubscriptionPlanItem> CreatePlanAsync(string code) =>
            Plans.CreateAsync(new SubscriptionPlanCreateRequest(
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

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}