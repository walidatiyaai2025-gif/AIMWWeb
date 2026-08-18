using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Billing;

public sealed class PayPalSubscriptionCheckoutService(
    AppDbContext dbContext,
    IPaymentGatewayRegistry paymentGatewayRegistry) : IPayPalSubscriptionCheckoutService
{
    public async Task<GatewayCheckoutSession> CreateAsync(
        Guid ownerUserId,
        Guid accountSubscriptionId,
        Uri returnUri,
        Uri cancelUri,
        string correlationId,
        CancellationToken cancellationToken = default)
    {
        if (ownerUserId == Guid.Empty)
            throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
        if (accountSubscriptionId == Guid.Empty)
            throw new ArgumentException("Account subscription ID is required.", nameof(accountSubscriptionId));

        var checkout = await (
            from subscription in dbContext.AccountSubscriptions.AsNoTracking()
            join plan in dbContext.SubscriptionPlans.AsNoTracking() on subscription.PlanId equals plan.Id
            where subscription.Id == accountSubscriptionId && subscription.OwnerUserId == ownerUserId
            select new CheckoutSource(
                subscription.Id,
                subscription.PlanId,
                subscription.Status,
                plan.IsEnabled,
                plan.GatewayPlanId))
            .SingleOrDefaultAsync(cancellationToken)
            ?? throw new KeyNotFoundException("Account subscription was not found for the current account.");

        if (checkout.Status == AccountSubscriptionStatus.Expired)
            throw new InvalidOperationException("Expired subscriptions cannot start a new checkout on the same subscription record.");
        if (!checkout.PlanEnabled)
            throw new InvalidOperationException("The subscription plan is disabled and cannot start a new checkout.");
        if (string.IsNullOrWhiteSpace(checkout.ProviderPlanReference))
            throw new InvalidOperationException("The subscription plan is not mapped to a PayPal plan.");

        var gateway = paymentGatewayRegistry.GetRequired("paypal", PaymentGatewayCapability.SubscriptionCheckout);
        return await gateway.CreateSubscriptionCheckoutAsync(new GatewayCheckoutRequest(
            checkout.SubscriptionId,
            checkout.PlanId,
            checkout.ProviderPlanReference,
            returnUri,
            cancelUri,
            correlationId), cancellationToken);
    }

    private sealed record CheckoutSource(
        Guid SubscriptionId,
        Guid PlanId,
        AccountSubscriptionStatus Status,
        bool PlanEnabled,
        string? ProviderPlanReference);
}
