using AIWordPressManager.Application.Abstractions.Billing;

namespace AIWordPressManager.Infrastructure.Billing;

public sealed class PaymentGatewayRegistry : IPaymentGatewayRegistry
{
    private readonly IReadOnlyDictionary<string, IPaymentGateway> _gateways;
    private readonly IReadOnlyList<PaymentGatewayDescriptor> _descriptors;

    public PaymentGatewayRegistry(IEnumerable<IPaymentGateway> gateways)
    {
        ArgumentNullException.ThrowIfNull(gateways);
        var byKey = new Dictionary<string, IPaymentGateway>(StringComparer.OrdinalIgnoreCase);

        foreach (var gateway in gateways)
        {
            if (gateway is null)
                throw new InvalidOperationException("Payment gateway registrations cannot contain null entries.");
            var descriptor = gateway.Descriptor ?? throw new InvalidOperationException("Payment gateway descriptor is required.");
            var normalizedKey = PaymentGatewayContract.NormalizeGatewayKey(descriptor.Key);
            if (!byKey.TryAdd(normalizedKey, gateway))
                throw new InvalidOperationException($"Payment gateway key '{descriptor.Key}' is registered more than once.");
        }

        _gateways = byKey;
        _descriptors = byKey.Values
            .Select(x => x.Descriptor)
            .OrderBy(x => x.Key, StringComparer.Ordinal)
            .ToArray();
    }

    public IReadOnlyList<PaymentGatewayDescriptor> List() => _descriptors;

    public bool TryResolve(string gatewayKey, out IPaymentGateway gateway)
    {
        var normalizedKey = PaymentGatewayContract.NormalizeGatewayKey(gatewayKey);
        return _gateways.TryGetValue(normalizedKey, out gateway!);
    }

    public IPaymentGateway GetRequired(string gatewayKey)
    {
        if (TryResolve(gatewayKey, out var gateway)) return gateway;
        throw new KeyNotFoundException($"Payment gateway '{PaymentGatewayContract.NormalizeGatewayKey(gatewayKey)}' is not registered.");
    }

    public IPaymentGateway GetRequired(string gatewayKey, PaymentGatewayCapability requiredCapability)
    {
        if (requiredCapability == PaymentGatewayCapability.None)
            throw new ArgumentException("A required payment gateway capability must be specified.", nameof(requiredCapability));

        var gateway = GetRequired(gatewayKey);
        if (!gateway.Descriptor.Supports(requiredCapability))
            throw new NotSupportedException($"Payment gateway '{gateway.Descriptor.Key}' does not support capability '{requiredCapability}'.");
        return gateway;
    }
}
