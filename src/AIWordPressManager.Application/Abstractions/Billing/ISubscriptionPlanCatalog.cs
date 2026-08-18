namespace AIWordPressManager.Application.Abstractions.Billing;

public interface ISubscriptionPlanCatalog
{
    Task<IReadOnlyList<SubscriptionPlanItem>> ListAsync(
        bool includeDisabled = false,
        CancellationToken cancellationToken = default);

    Task<SubscriptionPlanItem?> GetByCodeAsync(
        string code,
        bool includeDisabled = false,
        CancellationToken cancellationToken = default);

    Task<SubscriptionPlanItem> CreateAsync(
        SubscriptionPlanCreateRequest request,
        CancellationToken cancellationToken = default);

    Task<SubscriptionPlanItem> UpdateAsync(
        Guid planId,
        SubscriptionPlanUpdateRequest request,
        CancellationToken cancellationToken = default);

    Task<SubscriptionPlanItem> SetEnabledAsync(
        Guid planId,
        bool enabled,
        CancellationToken cancellationToken = default);
}

public sealed record SubscriptionPlanItem(
    Guid Id,
    string Code,
    string NameEn,
    string NameAr,
    string DescriptionEn,
    string DescriptionAr,
    string BillingInterval,
    decimal Price,
    string Currency,
    int TrialDays,
    int GracePeriodDays,
    bool IsEnabled,
    int SortOrder,
    string? GatewayProductId,
    string? GatewayPlanId,
    DateTime CreatedAtUtc,
    DateTime UpdatedAtUtc);

public sealed record SubscriptionPlanCreateRequest(
    string Code,
    string NameEn,
    string NameAr,
    string? DescriptionEn,
    string? DescriptionAr,
    string BillingInterval,
    decimal Price,
    string Currency,
    int TrialDays,
    int GracePeriodDays,
    bool IsEnabled,
    int SortOrder,
    string? GatewayProductId = null,
    string? GatewayPlanId = null);

public sealed record SubscriptionPlanUpdateRequest(
    string NameEn,
    string NameAr,
    string? DescriptionEn,
    string? DescriptionAr,
    string BillingInterval,
    decimal Price,
    string Currency,
    int TrialDays,
    int GracePeriodDays,
    bool IsEnabled,
    int SortOrder,
    string? GatewayProductId = null,
    string? GatewayPlanId = null);
