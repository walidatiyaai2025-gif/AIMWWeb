using AIWordPressManager.Application.Abstractions.Billing;

namespace AIWordPressManager.Persistence.Billing;

public sealed class PayPalBoundSubscriptionCheckoutService(
    PayPalSubscriptionCheckoutService innerService,
    IAccountSubscriptionService subscriptionService) : IPayPalSubscriptionCheckoutService
{
    public async Task<GatewayCheckoutSession> CreateAsync(
        Guid ownerUserId,
        Guid accountSubscriptionId,
        Uri returnUri,
        Uri cancelUri,
        string correlationId,
        CancellationToken cancellationToken = default)
    {
        var current = await subscriptionService.GetCurrentAsync(ownerUserId, cancellationToken);
        if (current is not null && current.Id == accountSubscriptionId &&
            (!string.IsNullOrWhiteSpace(current.ProviderKey) || !string.IsNullOrWhiteSpace(current.ProviderSubscriptionReference)))
        {
            throw new InvalidOperationException("This account subscription is already bound to an external subscription.");
        }

        var session = await innerService.CreateAsync(
            ownerUserId,
            accountSubscriptionId,
            returnUri,
            cancelUri,
            correlationId,
            cancellationToken);

        await subscriptionService.BindProviderReferenceAsync(
            accountSubscriptionId,
            "paypal",
            session.ProviderSessionReference,
            cancellationToken);

        return session;
    }
}
