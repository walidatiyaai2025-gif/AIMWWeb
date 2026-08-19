namespace AIWordPressManager.Application.Abstractions.Billing;

public interface IAccountEntitlementEnforcementService
{
    Task RequireBooleanCapabilityAsync(
        Guid ownerUserId,
        string entitlementKey,
        CancellationToken cancellationToken = default);

    Task RequireAdditionalUsageAsync(
        Guid ownerUserId,
        string entitlementKey,
        long currentUsage,
        long requestedAdditional = 1,
        CancellationToken cancellationToken = default);
}

public sealed class AccountEntitlementDeniedException : InvalidOperationException
{
    public AccountEntitlementDeniedException(
        string code,
        string entitlementKey,
        string message,
        long? limit = null,
        long? currentUsage = null,
        long? requestedAdditional = null)
        : base(message)
    {
        Code = string.IsNullOrWhiteSpace(code) ? "subscription_entitlement_denied" : code.Trim();
        EntitlementKey = entitlementKey ?? string.Empty;
        Limit = limit;
        CurrentUsage = currentUsage;
        RequestedAdditional = requestedAdditional;
    }

    public string Code { get; }
    public string EntitlementKey { get; }
    public long? Limit { get; }
    public long? CurrentUsage { get; }
    public long? RequestedAdditional { get; }
}
