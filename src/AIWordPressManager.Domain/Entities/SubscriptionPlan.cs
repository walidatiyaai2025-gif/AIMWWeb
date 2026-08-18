using System.Text.RegularExpressions;
using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class SubscriptionPlan : Entity
{
    public const string MonthlyInterval = "Monthly";
    public const string YearlyInterval = "Yearly";
    public const int MaximumTrialDays = 365;
    public const int MaximumGracePeriodDays = 90;
    public const int MaximumSortOrder = 100_000;

    private static readonly Regex PlanCodePattern = new(
        "^[a-z0-9][a-z0-9._-]{0,63}$",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    private static readonly Regex CurrencyPattern = new(
        "^[A-Z]{3}$",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    private SubscriptionPlan() { }

    public SubscriptionPlan(
        string code,
        string nameEn,
        string nameAr,
        string? descriptionEn,
        string? descriptionAr,
        string billingInterval,
        decimal price,
        string currency,
        int trialDays,
        int gracePeriodDays,
        bool isEnabled,
        int sortOrder,
        string? gatewayProductId,
        string? gatewayPlanId,
        DateTime utcNow)
    {
        Code = NormalizeCode(code);
        NormalizedCode = Code.ToUpperInvariant();
        ApplyMutableValues(
            nameEn,
            nameAr,
            descriptionEn,
            descriptionAr,
            billingInterval,
            price,
            currency,
            trialDays,
            gracePeriodDays,
            isEnabled,
            sortOrder,
            gatewayProductId,
            gatewayPlanId,
            utcNow);
    }

    public string Code { get; private set; } = string.Empty;
    public string NormalizedCode { get; private set; } = string.Empty;
    public string NameEn { get; private set; } = string.Empty;
    public string NameAr { get; private set; } = string.Empty;
    public string DescriptionEn { get; private set; } = string.Empty;
    public string DescriptionAr { get; private set; } = string.Empty;
    public string BillingInterval { get; private set; } = MonthlyInterval;
    public decimal Price { get; private set; }
    public string Currency { get; private set; } = "USD";
    public int TrialDays { get; private set; }
    public int GracePeriodDays { get; private set; }
    public bool IsEnabled { get; private set; }
    public int SortOrder { get; private set; }
    public string? GatewayProductId { get; private set; }
    public string? GatewayPlanId { get; private set; }

    public void Update(
        string nameEn,
        string nameAr,
        string? descriptionEn,
        string? descriptionAr,
        string billingInterval,
        decimal price,
        string currency,
        int trialDays,
        int gracePeriodDays,
        bool isEnabled,
        int sortOrder,
        string? gatewayProductId,
        string? gatewayPlanId,
        DateTime utcNow) =>
        ApplyMutableValues(
            nameEn,
            nameAr,
            descriptionEn,
            descriptionAr,
            billingInterval,
            price,
            currency,
            trialDays,
            gracePeriodDays,
            isEnabled,
            sortOrder,
            gatewayProductId,
            gatewayPlanId,
            utcNow);

    public void SetEnabled(bool enabled, DateTime utcNow)
    {
        if (IsEnabled == enabled) return;
        IsEnabled = enabled;
        MarkUpdated(RequireUtc(utcNow));
    }

    private void ApplyMutableValues(
        string nameEn,
        string nameAr,
        string? descriptionEn,
        string? descriptionAr,
        string billingInterval,
        decimal price,
        string currency,
        int trialDays,
        int gracePeriodDays,
        bool isEnabled,
        int sortOrder,
        string? gatewayProductId,
        string? gatewayPlanId,
        DateTime utcNow)
    {
        NameEn = RequiredBounded(nameEn, 160, nameof(nameEn));
        NameAr = RequiredBounded(nameAr, 160, nameof(nameAr));
        DescriptionEn = OptionalBounded(descriptionEn, 1000, nameof(descriptionEn));
        DescriptionAr = OptionalBounded(descriptionAr, 1000, nameof(descriptionAr));
        BillingInterval = NormalizeInterval(billingInterval);
        if (price < 0m || price > 1_000_000m)
            throw new ArgumentOutOfRangeException(nameof(price), "Plan price must be between 0 and 1,000,000.");
        Price = decimal.Round(price, 4, MidpointRounding.AwayFromZero);
        Currency = NormalizeCurrency(currency);
        TrialDays = ValidateDays(trialDays, MaximumTrialDays, nameof(trialDays));
        GracePeriodDays = ValidateDays(gracePeriodDays, MaximumGracePeriodDays, nameof(gracePeriodDays));
        if (sortOrder < 0 || sortOrder > MaximumSortOrder)
            throw new ArgumentOutOfRangeException(nameof(sortOrder), $"Sort order must be between 0 and {MaximumSortOrder}.");
        SortOrder = sortOrder;
        IsEnabled = isEnabled;
        GatewayProductId = NullableBounded(gatewayProductId, 200, nameof(gatewayProductId));
        GatewayPlanId = NullableBounded(gatewayPlanId, 200, nameof(gatewayPlanId));
        MarkUpdated(RequireUtc(utcNow));
    }

    public static string NormalizeCode(string code)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(code);
        var normalized = code.Trim().ToLowerInvariant();
        if (!PlanCodePattern.IsMatch(normalized))
            throw new ArgumentException("Plan code must contain only lowercase letters, numbers, '.', '_' or '-' and be at most 64 characters.", nameof(code));
        return normalized;
    }

    private static string NormalizeInterval(string value)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value);
        if (string.Equals(value.Trim(), MonthlyInterval, StringComparison.OrdinalIgnoreCase)) return MonthlyInterval;
        if (string.Equals(value.Trim(), YearlyInterval, StringComparison.OrdinalIgnoreCase)) return YearlyInterval;
        throw new ArgumentException("Billing interval must be Monthly or Yearly.", nameof(value));
    }

    private static string NormalizeCurrency(string value)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value);
        var normalized = value.Trim().ToUpperInvariant();
        if (!CurrencyPattern.IsMatch(normalized))
            throw new ArgumentException("Currency must be a three-letter ISO-style code.", nameof(value));
        return normalized;
    }

    private static int ValidateDays(int value, int maximum, string parameterName)
    {
        if (value < 0 || value > maximum)
            throw new ArgumentOutOfRangeException(parameterName, $"Value must be between 0 and {maximum} days.");
        return value;
    }

    private static string RequiredBounded(string? value, int maxLength, string parameterName)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value, parameterName);
        var trimmed = value.Trim();
        if (trimmed.Length > maxLength)
            throw new ArgumentException($"Value must be at most {maxLength} characters.", parameterName);
        return trimmed;
    }

    private static string OptionalBounded(string? value, int maxLength, string parameterName)
    {
        var trimmed = (value ?? string.Empty).Trim();
        if (trimmed.Length > maxLength)
            throw new ArgumentException($"Value must be at most {maxLength} characters.", parameterName);
        return trimmed;
    }

    private static string? NullableBounded(string? value, int maxLength, string parameterName)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        var trimmed = value.Trim();
        if (trimmed.Length > maxLength)
            throw new ArgumentException($"Value must be at most {maxLength} characters.", parameterName);
        return trimmed;
    }

    private static DateTime RequireUtc(DateTime value)
    {
        if (value.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Timestamp must be UTC.", nameof(value));
        return value;
    }
}
