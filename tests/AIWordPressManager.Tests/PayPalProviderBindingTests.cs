using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

public sealed class PayPalProviderBindingTests
{
    [Fact]
    public async Task Provider_Binding_Is_Write_Once_Idempotent_And_Cannot_Cross_Accounts()
    {
        await using var fixture = await Fixture.CreateAsync();
        var first = await fixture.CreateSubscriptionAsync("first-owner", "first-plan");
        var second = await fixture.CreateSubscriptionAsync("second-owner", "second-plan");

        var bound = await fixture.SafeSubscriptions.BindProviderReferenceAsync(first.Subscription.Id, "PayPal", "I-WRITE-ONCE");
        bound.ProviderKey.Should().Be("paypal");
        bound.ProviderSubscriptionReference.Should().Be("I-WRITE-ONCE");

        var same = await fixture.SafeSubscriptions.BindProviderReferenceAsync(first.Subscription.Id, "PAYPAL", "i-write-once");
        same.ProviderSubscriptionReference.Should().Be("I-WRITE-ONCE");

        var replace = () => fixture.SafeSubscriptions.BindProviderReferenceAsync(first.Subscription.Id, "paypal", "I-DIFFERENT");
        await replace.Should().ThrowAsync<InvalidOperationException>().WithMessage("*cannot be replaced*");

        var clear = () => fixture.SafeSubscriptions.BindProviderReferenceAsync(first.Subscription.Id, null, null);
        await clear.Should().ThrowAsync<InvalidOperationException>().WithMessage("*cannot be cleared*");

        var collide = () => fixture.SafeSubscriptions.BindProviderReferenceAsync(second.Subscription.Id, "paypal", "I-WRITE-ONCE");
        await collide.Should().ThrowAsync<InvalidOperationException>().WithMessage("*another account subscription*");

        var secondReloaded = await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == second.Subscription.Id);
        secondReloaded.ProviderKey.Should().BeNull();
        secondReloaded.ProviderSubscriptionReference.Should().BeNull();
    }

    [Fact]
    public async Task Production_Checkout_Binds_Provider_Correlation_Without_Changing_Subscription_Status()
    {
        await using var fixture = await Fixture.CreateAsync();
        var source = await fixture.CreateSubscriptionAsync("checkout-owner", "checkout-plan", "P-CHECKOUT-MAPPED");
        var gateway = new CapturingGateway();
        var innerCheckout = new PayPalSubscriptionCheckoutService(
            fixture.Context,
            new PaymentGatewayRegistry(new[] { gateway }));
        var checkout = new PayPalBoundSubscriptionCheckoutService(innerCheckout, fixture.SafeSubscriptions);

        var result = await checkout.CreateAsync(
            source.Owner.Id,
            source.Subscription.Id,
            new Uri("https://app.example.test/billing/return"),
            new Uri("https://app.example.test/billing/cancel"),
            "binding-correlation");

        result.Authority.Should().Be(GatewayEvidenceAuthority.NavigationOnly);
        gateway.CallCount.Should().Be(1);
        var reloaded = await fixture.Context.AccountSubscriptions.AsNoTracking().SingleAsync(x => x.Id == source.Subscription.Id);
        reloaded.Status.Should().Be(AccountSubscriptionStatus.Trialing);
        reloaded.ProviderKey.Should().Be("paypal");
        reloaded.ProviderSubscriptionReference.Should().Be("I-CHECKOUT-BOUND");
        (await fixture.Context.AccountSubscriptionTransitions.CountAsync(x => x.SubscriptionId == source.Subscription.Id)).Should().Be(0);

        var duplicate = () => checkout.CreateAsync(
            source.Owner.Id,
            source.Subscription.Id,
            new Uri("https://app.example.test/billing/return"),
            new Uri("https://app.example.test/billing/cancel"),
            "binding-correlation");
        await duplicate.Should().ThrowAsync<InvalidOperationException>().WithMessage("*already bound*");
        gateway.CallCount.Should().Be(1);
    }

    private sealed class CapturingGateway : IPaymentGateway
    {
        public PaymentGatewayDescriptor Descriptor { get; } = new("paypal", "PayPal", PaymentGatewayCapability.SubscriptionCheckout);
        public int CallCount { get; private set; }

        public Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(GatewayCheckoutRequest request, CancellationToken cancellationToken = default)
        {
            CallCount++;
            return Task.FromResult(new GatewayCheckoutSession(
                "I-CHECKOUT-BOUND",
                new Uri("https://www.paypal.com/webapps/billing/subscriptions?ba_token=BA-BOUND")));
        }

        public Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(GatewayWebhookEnvelope envelope, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(string providerSubscriptionReference, CancellationToken cancellationToken = default) => throw new NotSupportedException();
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
        }

        private SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        private AccountSubscriptionService InnerSubscriptions { get; }
        public ProviderBindingAccountSubscriptionService SafeSubscriptions { get; }
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

        public async Task<(AuthUser Owner, SubscriptionPlanItem Plan, AccountSubscriptionItem Subscription)> CreateSubscriptionAsync(
            string ownerName,
            string planCode,
            string? gatewayPlanId = "P-DEFAULT")
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
                GatewayPlanId: gatewayPlanId));
            var subscription = await SafeSubscriptions.CreateAsync(new(
                owner.Id,
                plan.Id,
                AccountSubscriptionStatus.Trialing));
            return (owner, plan, subscription);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
