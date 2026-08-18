namespace AIWordPressManager.Application.Abstractions.Billing;

public interface IPayPalSubscriptionCheckoutService
{
    Task<GatewayCheckoutSession> CreateAsync(
        Guid ownerUserId,
        Guid accountSubscriptionId,
        Uri returnUri,
        Uri cancelUri,
        string correlationId,
        CancellationToken cancellationToken = default);
}
