using AIWordPressManager.Domain.Entities;

namespace AIWordPressManager.Application.Abstractions.Billing;

public interface IPlanEntitlementCatalog
{
    IReadOnlyList<EntitlementDefinitionItem> ListDefinitions();

    Task<IReadOnlyList<PlanEntitlementItem>> ListAsync(
        Guid planId,
        CancellationToken cancellationToken = default);

    Task<PlanEntitlementItem> SetAsync(
        Guid planId,
        string key,
        string? rawValue,
        CancellationToken cancellationToken = default);

    Task<bool> RemoveAsync(
        Guid planId,
        string key,
        CancellationToken cancellationToken = default);
}

public interface IPlanEntitlementResolver
{
    Task<PlanEntitlementResolution> ResolveAsync(
        Guid planId,
        string key,
        CancellationToken cancellationToken = default);

    Task<BooleanEntitlementCheck> CheckBooleanCapabilityAsync(
        Guid planId,
        string key,
        CancellationToken cancellationToken = default);

    Task<IntegerEntitlementLimitCheck> CheckIntegerLimitAsync(
        Guid planId,
        string key,
        long currentUsage,
        long requestedAdditional = 1,
        CancellationToken cancellationToken = default);
}

public sealed record EntitlementDefinitionItem(
    string Key,
    EntitlementValueType ValueType,
    bool RequiresNonNegativeNumber);

public sealed record PlanEntitlementItem(
    Guid Id,
    Guid PlanId,
    string Key,
    EntitlementValueType ValueType,
    string CanonicalValue,
    DateTime CreatedAtUtc,
    DateTime UpdatedAtUtc);

public sealed record PlanEntitlementResolution(
    bool IsConfigured,
    Guid PlanId,
    string Key,
    EntitlementValueType ValueType,
    string? CanonicalValue,
    bool? BooleanValue,
    long? IntegerValue,
    decimal? DecimalValue,
    string? StringValue)
{
    public static PlanEntitlementResolution Missing(Guid planId, EntitlementDefinition definition) =>
        new(
            false,
            planId,
            definition.Key,
            definition.ValueType,
            null,
            null,
            null,
            null,
            null);
}

public sealed record BooleanEntitlementCheck(
    bool IsConfigured,
    string Key,
    bool IsEnabled);

public sealed record IntegerEntitlementLimitCheck(
    bool IsConfigured,
    string Key,
    long? Limit,
    long CurrentUsage,
    long RequestedAdditional,
    bool IsAllowed);
