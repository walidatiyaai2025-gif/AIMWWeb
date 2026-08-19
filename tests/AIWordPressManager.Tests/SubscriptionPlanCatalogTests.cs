using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class SubscriptionPlanCatalogTests
{
    [Fact]
    public void Domain_Normalizes_Stable_Code_Currency_And_Interval()
    {
        var plan = CreateDomainPlan(" Pro-Monthly ", "monthly", "kwd");

        plan.Code.Should().Be("pro-monthly");
        plan.NormalizedCode.Should().Be("PRO-MONTHLY");
        plan.BillingInterval.Should().Be(SubscriptionPlan.MonthlyInterval);
        plan.Currency.Should().Be("KWD");
    }

    [Theory]
    [InlineData("bad code!")]
    [InlineData("/unsafe")]
    [InlineData("")]
    public void Domain_Rejects_Invalid_Plan_Code(string code)
    {
        var action = () => CreateDomainPlan(code);
        action.Should().Throw<ArgumentException>();
    }

    [Fact]
    public void Domain_Rejects_Invalid_Commercial_Fields()
    {
        var negativePrice = () => NewPlan(price: -0.01m);
        var invalidCurrency = () => NewPlan(currency: "US");
        var invalidInterval = () => NewPlan(interval: "Weekly");
        var excessiveTrial = () => NewPlan(trialDays: SubscriptionPlan.MaximumTrialDays + 1);
        var excessiveGrace = () => NewPlan(graceDays: SubscriptionPlan.MaximumGracePeriodDays + 1);
        var invalidSort = () => NewPlan(sortOrder: -1);

        negativePrice.Should().Throw<ArgumentOutOfRangeException>();
        invalidCurrency.Should().Throw<ArgumentException>();
        invalidInterval.Should().Throw<ArgumentException>();
        excessiveTrial.Should().Throw<ArgumentOutOfRangeException>();
        excessiveGrace.Should().Throw<ArgumentOutOfRangeException>();
        invalidSort.Should().Throw<ArgumentOutOfRangeException>();
    }

    [Fact]
    public async Task Catalog_Rejects_CaseInsensitive_Duplicate_Codes()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Catalog.CreateAsync(CreateRequest("starter"));

        var action = () => fixture.Catalog.CreateAsync(CreateRequest("STARTER"));

        await action.Should().ThrowAsync<InvalidOperationException>()
            .WithMessage("*starter*already exists*");
        (await fixture.Context.SubscriptionPlans.CountAsync(x => x.NormalizedCode == "STARTER")).Should().Be(1);
    }

    [Fact]
    public async Task Free_Trial_Is_Bootstrapped_With_Approved_Limits()
    {
        await using var fixture = await Fixture.CreateAsync();

        var trial = await fixture.Catalog.GetByCodeAsync(SubscriptionPlanCatalog.FreeTrialCode);
        trial.Should().NotBeNull();
        trial!.Price.Should().Be(0m);
        trial.TrialDays.Should().Be(SubscriptionPlanCatalog.FreeTrialDays);
        trial.IsEnabled.Should().BeTrue();
        trial.SortOrder.Should().Be(0);

        var entitlements = await fixture.Context.PlanEntitlements.AsNoTracking()
            .Where(x => x.PlanId == trial.Id)
            .ToDictionaryAsync(x => x.Key, x => x.CanonicalValue, StringComparer.OrdinalIgnoreCase);

        entitlements.Should().Contain(new Dictionary<string, string>
        {
            [EntitlementDefinitionCatalog.SitesMax] = "1",
            [EntitlementDefinitionCatalog.EmailSiteRecipientsMax] = "2",
            [EntitlementDefinitionCatalog.EmailSchedulesMax] = "1",
            [EntitlementDefinitionCatalog.EmailDashboardDigest] = "false",
            [EntitlementDefinitionCatalog.AutomationSchedulesMax] = "1",
            [EntitlementDefinitionCatalog.AiEnabled] = "true",
            [EntitlementDefinitionCatalog.AiMonthlyRequestsMax] = "50",
            [EntitlementDefinitionCatalog.BackupRetentionDays] = "3",
            [EntitlementDefinitionCatalog.PremiumSeo] = "false"
        });
        entitlements.Should().HaveCount(9);
    }

    [Fact]
    public async Task Enabled_Reads_Are_Ordered_And_Disabled_Plans_Are_Hidden_By_Default()
    {
        await using var fixture = await Fixture.CreateAsync();
        await fixture.Catalog.CreateAsync(CreateRequest("zeta", sortOrder: 10));
        await fixture.Catalog.CreateAsync(CreateRequest("beta", sortOrder: 1));
        await fixture.Catalog.CreateAsync(CreateRequest("alpha", sortOrder: 1));
        await fixture.Catalog.CreateAsync(CreateRequest("hidden", isEnabled: false, sortOrder: 0));

        var enabled = await fixture.Catalog.ListAsync();
        var all = await fixture.Catalog.ListAsync(includeDisabled: true);

        enabled.Select(x => x.Code).Should().Equal("free-trial", "alpha", "beta", "zeta");
        all.Select(x => x.Code).Should().Equal("free-trial", "hidden", "alpha", "beta", "zeta");
        (await fixture.Catalog.GetByCodeAsync("HIDDEN")).Should().BeNull();
        (await fixture.Catalog.GetByCodeAsync("HIDDEN", includeDisabled: true))!.Code.Should().Be("hidden");
    }

    [Fact]
    public async Task Update_Preserves_Code_And_Can_Rotate_Gateway_Mapping()
    {
        await using var fixture = await Fixture.CreateAsync();
        var created = await fixture.Catalog.CreateAsync(CreateRequest("business"));

        var updated = await fixture.Catalog.UpdateAsync(
            created.Id,
            new SubscriptionPlanUpdateRequest(
                "Business Annual",
                "الأعمال السنوية",
                "Updated English description",
                "وصف عربي محدث",
                SubscriptionPlan.YearlyInterval,
                120m,
                "KWD",
                14,
                7,
                true,
                5,
                "provider-product-v2",
                "provider-plan-v2"));

        updated.Code.Should().Be("business");
        updated.BillingInterval.Should().Be(SubscriptionPlan.YearlyInterval);
        updated.Price.Should().Be(120m);
        updated.Currency.Should().Be("KWD");
        updated.GatewayProductId.Should().Be("provider-product-v2");
        updated.GatewayPlanId.Should().Be("provider-plan-v2");
    }

    [Fact]
    public async Task Free_Trial_Bootstrap_And_Enable_Disable_Are_Durable()
    {
        await using var fixture = await Fixture.CreateAsync();
        (await fixture.Catalog.ListAsync()).Select(x => x.Code).Should().Equal("free-trial");
        var created = await fixture.Catalog.CreateAsync(CreateRequest("free"));

        await fixture.Catalog.SetEnabledAsync(created.Id, false);
        (await fixture.Catalog.ListAsync()).Select(x => x.Code).Should().Equal("free-trial");
        (await fixture.Catalog.GetByCodeAsync("free", includeDisabled: true))!.IsEnabled.Should().BeFalse();

        await fixture.Catalog.SetEnabledAsync(created.Id, true);
        (await fixture.Catalog.GetByCodeAsync("FREE"))!.IsEnabled.Should().BeTrue();
    }

    [Fact]
    public async Task Sqlite_Migration_Creates_Catalog_With_Unique_Code_Index()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
        await connection.OpenAsync();
        var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
        await using var context = new AppDbContext(options);

        await context.Database.MigrateAsync();

        await using var tableCommand = connection.CreateCommand();
        tableCommand.CommandText = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='SubscriptionPlans';";
        Convert.ToInt32(await tableCommand.ExecuteScalarAsync()).Should().Be(1);

        var catalog = new SubscriptionPlanCatalog(context);
        await catalog.CreateAsync(CreateRequest("unique"));
        context.SubscriptionPlans.Add(NewPlan(code: "UNIQUE"));
        var duplicateSave = () => context.SaveChangesAsync();
        await duplicateSave.Should().ThrowAsync<DbUpdateException>();
    }

    private static SubscriptionPlan CreateDomainPlan(
        string code,
        string interval = SubscriptionPlan.MonthlyInterval,
        string currency = "USD") =>
        NewPlan(code, interval: interval, currency: currency);

    private static SubscriptionPlan NewPlan(
        string code = "starter",
        decimal price = 9.99m,
        string interval = SubscriptionPlan.MonthlyInterval,
        string currency = "USD",
        int trialDays = 14,
        int graceDays = 7,
        int sortOrder = 10) =>
        new(
            code,
            "Starter",
            "المبتدئة",
            "Starter plan",
            "الخطة المبتدئة",
            interval,
            price,
            currency,
            trialDays,
            graceDays,
            true,
            sortOrder,
            null,
            null,
            DateTime.UtcNow);

    private static SubscriptionPlanCreateRequest CreateRequest(
        string code,
        bool isEnabled = true,
        int sortOrder = 10) =>
        new(
            code,
            $"{code} English",
            $"{code} عربي",
            "English description",
            "وصف عربي",
            SubscriptionPlan.MonthlyInterval,
            10m,
            "USD",
            14,
            7,
            isEnabled,
            sortOrder);

    private sealed class Fixture : IAsyncDisposable
    {
        private Fixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
            Catalog = new SubscriptionPlanCatalog(context);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public SubscriptionPlanCatalog Catalog { get; }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}