using System.Globalization;
using System.Text.RegularExpressions;
using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public enum EntitlementValueType
{
    Boolean = 1,
    Integer = 2,
    Decimal = 3,
    String = 4
}

public sealed record EntitlementDefinition(
    string Key,
    EntitlementValueType ValueType,
    bool RequiresNonNegativeNumber = false);

public static class EntitlementDefinitionCatalog
{
    public const string SitesMax = "sites.max";
    public const string EmailSiteRecipientsMax = "email.siteRecipients.max";
    public const string EmailSchedulesMax = "email.schedules.max";
    public const string EmailDashboardDigest = "email.dashboardDigest";
    public const string AutomationSchedulesMax = "automation.schedules.max";
    public const string AiEnabled = "ai.enabled";
    public const string AiMonthlyRequestsMax = "ai.monthlyRequests.max";
    public const string BackupRetentionDays = "backup.retentionDays";
    public const string PremiumSeo = "premium.seo";

    private static readonly EntitlementDefinition[] Definitions =
    [
        new(SitesMax, EntitlementValueType.Integer, true),
        new(EmailSiteRecipientsMax, EntitlementValueType.Integer, true),
        new(EmailSchedulesMax, EntitlementValueType.Integer, true),
        new(EmailDashboardDigest, EntitlementValueType.Boolean),
        new(AutomationSchedulesMax, EntitlementValueType.Integer, true),
        new(AiEnabled, EntitlementValueType.Boolean),
        new(AiMonthlyRequestsMax, EntitlementValueType.Integer, true),
        new(BackupRetentionDays, EntitlementValueType.Integer, true),
        new(PremiumSeo, EntitlementValueType.Boolean)
    ];

    private static readonly IReadOnlyDictionary<string, EntitlementDefinition> ByNormalizedKey =
        Definitions.ToDictionary(x => NormalizeKey(x.Key), StringComparer.Ordinal);

    public static IReadOnlyList<EntitlementDefinition> All => Definitions;

    public static bool TryGet(string key, out EntitlementDefinition definition) =>
        ByNormalizedKey.TryGetValue(NormalizeKey(key), out definition!);

    public static EntitlementDefinition GetRequired(string key)
    {
        if (TryGet(key, out var definition)) return definition;
        throw new ArgumentException($"Unknown entitlement key '{key}'.", nameof(key));
    }

    public static string NormalizeKey(string key)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(key);
        return key.Trim().ToUpperInvariant();
    }
}

public static class EntitlementValueCodec
{
    public const int MaximumStringLength = 1000;

    public static string Canonicalize(
        string? rawValue,
        EntitlementValueType valueType,
        bool requiresNonNegativeNumber = false)
    {
        var value = (rawValue ?? string.Empty).Trim();

        return valueType switch
        {
            EntitlementValueType.Boolean => CanonicalizeBoolean(value),
            EntitlementValueType.Integer => CanonicalizeInteger(value, requiresNonNegativeNumber),
            EntitlementValueType.Decimal => CanonicalizeDecimal(value, requiresNonNegativeNumber),
            EntitlementValueType.String => CanonicalizeString(value),
            _ => throw new ArgumentOutOfRangeException(nameof(valueType), valueType, "Unsupported entitlement value type.")
        };
    }

    public static bool ParseBoolean(string canonicalValue) =>
        bool.Parse(canonicalValue);

    public static long ParseInteger(string canonicalValue) =>
        long.Parse(canonicalValue, NumberStyles.Integer, CultureInfo.InvariantCulture);

    public static decimal ParseDecimal(string canonicalValue) =>
        decimal.Parse(canonicalValue, NumberStyles.AllowLeadingSign | NumberStyles.AllowDecimalPoint, CultureInfo.InvariantCulture);

    private static string CanonicalizeBoolean(string value)
    {
        if (!bool.TryParse(value, out var parsed))
            throw new ArgumentException("Boolean entitlement value must be 'true' or 'false'.", nameof(value));
        return parsed ? "true" : "false";
    }

    private static string CanonicalizeInteger(string value, bool requiresNonNegativeNumber)
    {
        if (!long.TryParse(value, NumberStyles.Integer, CultureInfo.InvariantCulture, out var parsed))
            throw new ArgumentException("Integer entitlement value is invalid.", nameof(value));
        if (requiresNonNegativeNumber && parsed < 0)
            throw new ArgumentOutOfRangeException(nameof(value), "This entitlement value cannot be negative.");
        return parsed.ToString(CultureInfo.InvariantCulture);
    }

    private static string CanonicalizeDecimal(string value, bool requiresNonNegativeNumber)
    {
        if (!decimal.TryParse(
                value,
                NumberStyles.AllowLeadingSign | NumberStyles.AllowDecimalPoint,
                CultureInfo.InvariantCulture,
                out var parsed))
            throw new ArgumentException("Decimal entitlement value is invalid.", nameof(value));
        if (requiresNonNegativeNumber && parsed < 0m)
            throw new ArgumentOutOfRangeException(nameof(value), "This entitlement value cannot be negative.");
        return parsed.ToString("0.#############################", CultureInfo.InvariantCulture);
    }

    private static string CanonicalizeString(string value)
    {
        if (value.Length > MaximumStringLength)
            throw new ArgumentException($"String entitlement value must be at most {MaximumStringLength} characters.", nameof(value));
        return value;
    }
}

public sealed class PlanEntitlement : Entity
{
    private static readonly Regex KeyPattern = new(
        "^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    private PlanEntitlement() { }

    public PlanEntitlement(Guid planId, string key, string? rawValue, DateTime utcNow)
    {
        if (planId == Guid.Empty)
            throw new ArgumentException("Plan ID is required.", nameof(planId));

        var definition = EntitlementDefinitionCatalog.GetRequired(key);
        var canonicalKey = definition.Key;
        if (!KeyPattern.IsMatch(canonicalKey))
            throw new ArgumentException("Entitlement key contains unsupported characters.", nameof(key));

        PlanId = planId;
        Key = canonicalKey;
        NormalizedKey = EntitlementDefinitionCatalog.NormalizeKey(canonicalKey);
        ValueType = definition.ValueType;
        Value = EntitlementValueCodec.Canonicalize(rawValue, definition.ValueType, definition.RequiresNonNegativeNumber);
        MarkUpdated(RequireUtc(utcNow));
    }

    public Guid PlanId { get; private set; }
    public string Key { get; private set; } = string.Empty;
    public string NormalizedKey { get; private set; } = string.Empty;
    public EntitlementValueType ValueType { get; private set; }
    public string Value { get; private set; } = string.Empty;

    public void UpdateValue(string? rawValue, DateTime utcNow)
    {
        var definition = EntitlementDefinitionCatalog.GetRequired(Key);
        if (definition.ValueType != ValueType)
            throw new InvalidOperationException($"Stored entitlement type for '{Key}' does not match its canonical definition.");

        var canonical = EntitlementValueCodec.Canonicalize(rawValue, ValueType, definition.RequiresNonNegativeNumber);
        if (string.Equals(Value, canonical, StringComparison.Ordinal)) return;
        Value = canonical;
        MarkUpdated(RequireUtc(utcNow));
    }

    private static DateTime RequireUtc(DateTime value)
    {
        if (value.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Timestamp must be UTC.", nameof(value));
        return value;
    }
}
