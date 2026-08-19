using System.Security.Claims;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class AdminBillingSupportServiceTests
{
    [Fact]
    public async Task Search_Requires_SettingsManage_And_Masks_Provider_Reference()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("billing-owner@example.com");
        var plan = await fixture.AddPlanAsync("support-basic", "P-SUPPORT-BASIC");
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, plan.Id, AccountSubscriptionStatus.Active, "I-SUPPORT-SECRET-123456789");

        var denied = fixture.CreateService(await fixture.AddUserAsync("ordinary-user", "User"), "User");
        var deniedSearch = () => denied.SearchAsync(owner.UserName);
        await deniedSearch.Should().ThrowAsync<UnauthorizedAccessException>();

        var admin = await fixture.AddUserAsync("billing-admin", "Administrator");
        var service = fixture.CreateService(admin, "Administrator");

        var byName = await service.SearchAsync("billing-owner@example.com");
        var byOwnerId = await service.SearchAsync(owner.Id.ToString("D"));
        var byProvider = await service.SearchAsync("SECRET-1234");

        byName.Should().ContainSingle(x => x.SubscriptionId == subscription.Id);
        byOwnerId.Should().ContainSingle(x => x.SubscriptionId == subscription.Id);
        byProvider.Should().ContainSingle(x => x.SubscriptionId == subscription.Id);
        byName[0].MaskedProviderSubscriptionReference.Should().Be("I-S…6789");
        byName[0].MaskedProviderSubscriptionReference.Should().NotContain("SECRET");
    }

    [Fact]
    public async Task Search_Is_Bounded_And_Rejects_Overlong_Query()
    {
        await using var fixture = await Fixture.CreateAsync();
        var plan = await fixture.AddPlanAsync("support-search", "P-SUPPORT-SEARCH");
        for (var index = 0; index < 5; index++)
        {
            var owner = await fixture.AddUserAsync($"search-owner-{index}");
            await fixture.AddSubscriptionAsync(owner.Id, plan.Id, AccountSubscriptionStatus.Active);
        }

        var admin = await fixture.AddUserAsync("search-admin", "Administrator");
        var service = fixture.CreateService(admin, "Administrator");

        var bounded = await service.SearchAsync("search-owner", 2);
        bounded.Should().HaveCount(2);

        var invalid = () => service.SearchAsync(new string('x', 201), 50);
        await invalid.Should().ThrowAsync<ArgumentException>()
            .WithMessage("*at most 200 characters*");
    }

    [Fact]
    public async Task Grace_Grant_And_Extension_Require_Reason_And_Are_Security_Audited()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("grace-owner");
        var admin = await fixture.AddUserAsync("grace-admin", "Administrator");
        var plan = await fixture.AddPlanAsync("support-grace", "P-SUPPORT-GRACE");
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, plan.Id, AccountSubscriptionStatus.Active);
        var service = fixture.CreateService(admin, "Administrator");

        var missingReason = () => service.GrantOrExtendGraceAsync(
            subscription.Id,
            DateTime.UtcNow.AddDays(3),
            "no");
        await missingReason.Should().ThrowAsync<ArgumentException>()
            .WithMessage("*between 5 and 500 characters*");

        var firstEnd = DateTime.UtcNow.AddDays(3);
        var granted = await service.GrantOrExtendGraceAsync(
            subscription.Id,
            firstEnd,
            "Customer support approved temporary access while the billing case is reviewed.");

        granted.Subscription.Status.Should().Be(AccountSubscriptionStatus.Grace);
        granted.Subscription.GraceUntilUtc.Should().BeCloseTo(firstEnd, TimeSpan.FromSeconds(1));
        var transition = await fixture.Context.AccountSubscriptionTransitions.AsNoTracking()
            .SingleAsync(x => x.SubscriptionId == subscription.Id);
        transition.Source.Should().Be(SubscriptionTransitionSource.Administration);
        transition.ToStatus.Should().Be(AccountSubscriptionStatus.Grace);

        var secondEnd = firstEnd.AddDays(2);
        var extended = await service.GrantOrExtendGraceAsync(
            subscription.Id,
            secondEnd,
            "Support lead approved a short extension for the same open billing case.");
        extended.Subscription.GraceUntilUtc.Should().BeCloseTo(secondEnd, TimeSpan.FromSeconds(1));
        (await fixture.Context.AccountSubscriptionTransitions.CountAsync(x => x.SubscriptionId == subscription.Id))
            .Should().Be(1, "same-state grace extension is recorded in the support security audit rather than as a fake status transition");

        var audits = await fixture.ReadBillingSupportAuditAsync();
        audits.Select(x => x.Action).Should().Contain(["Billing.GraceGranted", "Billing.GraceExtended"]);
        audits.Should().OnlyContain(x => x.ActorUserId == admin.Id);
        audits.Should().OnlyContain(x => x.Metadata.ContainsKey("supportReason"));
    }

    [Fact]
    public async Task Suspend_And_Unbound_Reactivate_Use_Administration_Transitions_And_Audit()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("suspend-owner");
        var admin = await fixture.AddUserAsync("suspend-admin", "Administrator");
        var plan = await fixture.AddPlanAsync("support-suspend", "P-SUPPORT-SUSPEND");
        var subscription = await fixture.AddSubscriptionAsync(owner.Id, plan.Id, AccountSubscriptionStatus.Active);
        var service = fixture.CreateService(admin, "Administrator");

        var suspended = await service.SuspendAsync(
            subscription.Id,
            "Support investigation requires a temporary access suspension.");
        suspended.Subscription.Status.Should().Be(AccountSubscriptionStatus.Suspended);

        var reactivated = await service.ReactivateAsync(
            subscription.Id,
            "Support investigation completed and temporary access can be restored.");
        reactivated.Subscription.Status.Should().Be(AccountSubscriptionStatus.Active);

        var transitions = await fixture.Context.AccountSubscriptionTransitions.AsNoTracking()
            .Where(x => x.SubscriptionId == subscription.Id)
            .OrderBy(x => x.OccurredAtUtc)
            .ToListAsync();
        transitions.Should().HaveCount(2);
        transitions.Should().OnlyContain(x => x.Source == SubscriptionTransitionSource.Administration);
        transitions.Select(x => x.ToStatus).Should().Equal(
            AccountSubscriptionStatus.Suspended,
            AccountSubscriptionStatus.Active);

        var audits = await fixture.ReadBillingSupportAuditAsync();
        audits.Select(x => x.Action).Should().Contain(["Billing.Suspended", "Billing.Reactivated"]);
    }

    [Fact]
    public async Task Provider_Bound_Suspended_Subscription_Cannot_Be_Manually_Reactivated()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("provider-suspended-owner");
        var admin = await fixture.AddUserAsync("provider-suspended-admin", "Administrator");
        var plan = await fixture.AddPlanAsync("support-provider", "P-SUPPORT-PROVIDER");
        var subscription = await fixture.AddSubscriptionAsync(
            owner.Id,
            plan.Id,
            AccountSubscriptionStatus.Suspended,
            "I-PROVIDER-SUSPENDED-12345");
        var service = fixture.CreateService(admin, "Administrator");

        var action = () => service.ReactivateAsync(
            subscription.Id,
            "Administrator requested access restoration for support investigation.");

        await action.Should().ThrowAsync<InvalidOperationException>()
            .WithMessage("*authoritative provider reconciliation*");
        var reloaded = await fixture.Context.AccountSubscriptions.AsNoTracking()
            .SingleAsync(x => x.Id == subscription.Id);
        reloaded.Status.Should().Be(AccountSubscriptionStatus.Suspended);
        (await fixture.Context.AccountSubscriptionTransitions.CountAsync(x => x.SubscriptionId == subscription.Id)).Should().Be(0);
    }

    [Fact]
    public async Task PayPal_Reconciliation_Changes_Status_And_Plan_Only_From_Fresh_Authoritative_Snapshot()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("reconcile-owner");
        var admin = await fixture.AddUserAsync("reconcile-admin", "Administrator");
        var starter = await fixture.AddPlanAsync("support-starter", "P-SUPPORT-STARTER");
        var premium = await fixture.AddPlanAsync("support-premium", "P-SUPPORT-PREMIUM", sortOrder: 20);
        const string providerReference = "I-RECONCILE-PRIVATE-99887766";
        var subscription = await fixture.AddSubscriptionAsync(
            owner.Id,
            starter.Id,
            AccountSubscriptionStatus.Active,
            providerReference);
        var observedAt = DateTime.UtcNow.AddMinutes(1);
        var periodStart = observedAt.AddDays(-2);
        var gateway = new LookupGateway(new GatewaySubscriptionSnapshot(
            providerReference,
            GatewaySubscriptionState.Suspended,
            observedAt,
            periodStart,
            periodStart.AddMonths(1),
            providerPlanReference: premium.GatewayPlanId));
        var service = fixture.CreateService(admin, "Administrator", gateway);

        var result = await service.ReconcilePayPalAsync(
            subscription.Id,
            "Support requested an immediate authoritative PayPal reconciliation after a customer report.");

        result.Changed.Should().BeTrue();
        result.LocalStatus.Should().Be(AccountSubscriptionStatus.Suspended);
        result.PlanNameEn.Should().Be(premium.NameEn);
        result.ProviderObservedAtUtc.Should().Be(observedAt);
        gateway.LookupCount.Should().Be(1);

        var reloaded = await fixture.Context.AccountSubscriptions.AsNoTracking()
            .SingleAsync(x => x.Id == subscription.Id);
        reloaded.Status.Should().Be(AccountSubscriptionStatus.Suspended);
        reloaded.PlanId.Should().Be(premium.Id);
        reloaded.LastProviderEventAtUtc.Should().Be(observedAt);
        (await fixture.Context.AccountSubscriptionPlanChanges.CountAsync(x => x.SubscriptionId == subscription.Id)).Should().Be(1);
        (await fixture.Context.AccountSubscriptionTransitions.CountAsync(x => x.SubscriptionId == subscription.Id)).Should().Be(1);

        var audit = (await fixture.ReadBillingSupportAuditAsync()).Single(x => x.Action == "Billing.Reconciled");
        audit.Metadata["providerReference"].Should().Be("I-R…7766");
        audit.Metadata.Values.Should().OnlyContain(value => !value.Contains(providerReference, StringComparison.Ordinal));
        audit.Metadata["changed"].Should().Be(bool.TrueString);
    }

    [Fact]
    public async Task PayPal_Reconciliation_Rejects_Unmapped_Plan_Without_Local_Mutation()
    {
        await using var fixture = await Fixture.CreateAsync();
        var owner = await fixture.AddUserAsync("unmapped-support-owner");
        var admin = await fixture.AddUserAsync("unmapped-support-admin", "Administrator");
        var plan = await fixture.AddPlanAsync("support-mapped", "P-SUPPORT-MAPPED");
        var subscription = await fixture.AddSubscriptionAsync(
            owner.Id,
            plan.Id,
            AccountSubscriptionStatus.Active,
            "I-SUPPORT-UNMAPPED");
        var gateway = new LookupGateway(new GatewaySubscriptionSnapshot(
            "I-SUPPORT-UNMAPPED",
            GatewaySubscriptionState.Suspended,
            DateTime.UtcNow.AddMinutes(1),
            providerPlanReference: "P-NOT-MAPPED"));
        var service = fixture.CreateService(admin, "Administrator", gateway);

        var action = () => service.ReconcilePayPalAsync(
            subscription.Id,
            "Support requested provider reconciliation to diagnose a plan mapping mismatch.");

        await action.Should().ThrowAsync<InvalidOperationException>()
            .WithMessage("*unmapped local plan*");
        var reloaded = await fixture.Context.AccountSubscriptions.AsNoTracking()
            .SingleAsync(x => x.Id == subscription.Id);
        reloaded.PlanId.Should().Be(plan.Id);
        reloaded.Status.Should().Be(AccountSubscriptionStatus.Active);
        reloaded.LastProviderEventAtUtc.Should().BeNull();
        (await fixture.Context.AccountSubscriptionPlanChanges.CountAsync()).Should().Be(0);
        (await fixture.Context.AccountSubscriptionTransitions.CountAsync()).Should().Be(0);
    }

    [Fact]
    public void Support_Boundary_Exposes_No_Manual_Payment_Success_Operation()
    {
        var publicMethods = typeof(AdminBillingSupportService)
            .GetMethods()
            .Where(x => x.DeclaringType == typeof(AdminBillingSupportService))
            .Select(x => x.Name)
            .ToArray();

        publicMethods.Should().NotContain(x =>
            x.Contains("MarkPaid", StringComparison.OrdinalIgnoreCase) ||
            x.Contains("PaymentSuccess", StringComparison.OrdinalIgnoreCase) ||
            x.Contains("RecordPayment", StringComparison.OrdinalIgnoreCase));
    }

    private sealed class LookupGateway(params GatewaySubscriptionSnapshot[] snapshots) : IPaymentGateway
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
            SubscriptionService = new AccountSubscriptionService(context);
            PlanCatalog = new SubscriptionPlanCatalog(context);
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        private AccountSubscriptionService SubscriptionService { get; }
        private SubscriptionPlanCatalog PlanCatalog { get; }

        public static async Task<Fixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new Fixture(connection, context);
        }

        public async Task<AuthUser> AddUserAsync(string userName, string role = "User")
        {
            var user = new AuthUser(userName, "test-password-hash", DateTime.UtcNow, role);
            Context.AuthUsers.Add(user);
            await Context.SaveChangesAsync();
            return user;
        }

        public Task<SubscriptionPlanItem> AddPlanAsync(
            string code,
            string gatewayPlanId,
            int sortOrder = 10) =>
            PlanCatalog.CreateAsync(new SubscriptionPlanCreateRequest(
                code,
                code,
                $"{code} عربي",
                null,
                null,
                SubscriptionPlan.MonthlyInterval,
                10m,
                "USD",
                0,
                7,
                true,
                sortOrder,
                GatewayProductId: null,
                GatewayPlanId: gatewayPlanId));

        public async Task<AccountSubscriptionItem> AddSubscriptionAsync(
            Guid ownerUserId,
            Guid planId,
            AccountSubscriptionStatus status,
            string? providerReference = null)
        {
            var subscription = await SubscriptionService.CreateAsync(new(
                ownerUserId,
                planId,
                status,
                CurrentPeriodStartUtc: DateTime.UtcNow.AddDays(-2),
                CurrentPeriodEndsAtUtc: DateTime.UtcNow.AddDays(28)));
            if (!string.IsNullOrWhiteSpace(providerReference))
                subscription = await SubscriptionService.BindProviderReferenceAsync(
                    subscription.Id,
                    "paypal",
                    providerReference);
            return subscription;
        }

        public AdminBillingSupportService CreateService(
            AuthUser actor,
            string role,
            IPaymentGateway? gateway = null)
        {
            var httpContext = new DefaultHttpContext();
            httpContext.User = new ClaimsPrincipal(new ClaimsIdentity(
            [
                new Claim(ClaimTypes.NameIdentifier, actor.Id.ToString("D")),
                new Claim(ClaimTypes.Name, actor.UserName),
                new Claim(ClaimTypes.Role, role)
            ], "test"));
            var accessor = new HttpContextAccessor { HttpContext = httpContext };
            var currentUser = new CurrentUserContext(accessor);
            var registry = new PaymentGatewayRegistry(gateway is null ? [] : [gateway]);
            return new AdminBillingSupportService(
                Context,
                currentUser,
                SubscriptionService,
                registry,
                accessor);
        }

        public Task<IReadOnlyList<SecurityAuditRecord>> ReadBillingSupportAuditAsync() =>
            new ApplicationSecurityAuditStore(Context).ListAsync(new SecurityAuditQuery(
                Category: "BillingSupport",
                Take: 100));

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
