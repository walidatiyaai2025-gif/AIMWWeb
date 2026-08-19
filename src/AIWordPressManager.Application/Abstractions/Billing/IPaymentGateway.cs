using System.Text.RegularExpressions;

namespace AIWordPressManager.Application.Abstractions.Billing;

[Flags]
public enum PaymentGatewayCapability
{
    None = 0,
    SubscriptionCheckout = 1 << 0,
    WebhookVerification = 1 << 1,
    SubscriptionLookup = 1 << 2,
    CancelSubscription = 1 << 3,
    ReactivateSubscription = 1 << 4,
    ChangeSubscriptionPlan = 1 << 5
}

public enum GatewaySubscriptionState
{
    Unknown = 0,
    Pending = 1,
    Trialing = 2,
    Active = 3,
    PastDue = 4,
    Suspended = 5,
    Cancelled = 6,
    Expired = 7
}

public enum GatewayEvidenceAuthority
{
    NavigationOnly = 0,
    VerifiedWebhook = 1,
    ProviderApiSnapshot = 2
}

public sealed class PaymentGatewayDescriptor
{
    private static readonly Regex KeyPattern = new(
        "^[a-z0-9][a-z0-9._-]{0,63}$",
        RegexOptions.CultureInvariant | RegexOptions.Compiled);

    public PaymentGatewayDescriptor(string key, string displayName, PaymentGatewayCapability capabilities)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(key);
        var normalizedKey = key.Trim().ToLowerInvariant();
        if (!KeyPattern.IsMatch(normalizedKey))
            throw new ArgumentException("Gateway key must contain only lowercase letters, numbers, '.', '_' or '-' and be at most 64 characters.", nameof(key));

        ArgumentException.ThrowIfNullOrWhiteSpace(displayName);
        var cleanName = displayName.Trim();
        if (cleanName.Length > 120)
            throw new ArgumentException("Gateway display name must be at most 120 characters.", nameof(displayName));
        if (capabilities == PaymentGatewayCapability.None)
            throw new ArgumentException("Gateway must advertise at least one supported capability.", nameof(capabilities));

        Key = normalizedKey;
        DisplayName = cleanName;
        Capabilities = capabilities;
    }

    public string Key { get; }
    public string DisplayName { get; }
    public PaymentGatewayCapability Capabilities { get; }

    public bool Supports(PaymentGatewayCapability capability) =>
        capability != PaymentGatewayCapability.None && (Capabilities & capability) == capability;
}

public interface IPaymentGateway
{
    PaymentGatewayDescriptor Descriptor { get; }

    Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(
        GatewayCheckoutRequest request,
        CancellationToken cancellationToken = default);

    Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(
        GatewayWebhookEnvelope envelope,
        CancellationToken cancellationToken = default);

    Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(
        string providerSubscriptionReference,
        CancellationToken cancellationToken = default);

    Task<GatewayCommandResult> CancelSubscriptionAsync(
        GatewaySubscriptionCommandRequest request,
        CancellationToken cancellationToken = default);

    Task<GatewayCommandResult> ReactivateSubscriptionAsync(
        GatewaySubscriptionCommandRequest request,
        CancellationToken cancellationToken = default);

    Task<GatewayCommandResult> ChangeSubscriptionPlanAsync(
        GatewayPlanChangeRequest request,
        CancellationToken cancellationToken = default);
}

public interface IPaymentGatewayRegistry
{
    IReadOnlyList<PaymentGatewayDescriptor> List();
    bool TryResolve(string gatewayKey, out IPaymentGateway gateway);
    IPaymentGateway GetRequired(string gatewayKey);
    IPaymentGateway GetRequired(string gatewayKey, PaymentGatewayCapability requiredCapability);
}

public sealed class GatewayCheckoutRequest
{
    public GatewayCheckoutRequest(
        Guid accountSubscriptionId,
        Guid planId,
        string providerPlanReference,
        Uri returnUri,
        Uri cancelUri,
        string correlationId)
    {
        if (accountSubscriptionId == Guid.Empty) throw new ArgumentException("Account subscription ID is required.", nameof(accountSubscriptionId));
        if (planId == Guid.Empty) throw new ArgumentException("Plan ID is required.", nameof(planId));
        AccountSubscriptionId = accountSubscriptionId;
        PlanId = planId;
        ProviderPlanReference = PaymentGatewayContract.RequireBounded(providerPlanReference, 200, nameof(providerPlanReference));
        ReturnUri = PaymentGatewayContract.RequireNavigationUri(returnUri, nameof(returnUri));
        CancelUri = PaymentGatewayContract.RequireNavigationUri(cancelUri, nameof(cancelUri));
        CorrelationId = PaymentGatewayContract.RequireBounded(correlationId, 100, nameof(correlationId));
    }

    public Guid AccountSubscriptionId { get; }
    public Guid PlanId { get; }
    public string ProviderPlanReference { get; }
    public Uri ReturnUri { get; }
    public Uri CancelUri { get; }
    public string CorrelationId { get; }
}

public sealed class GatewayCheckoutSession
{
    public GatewayCheckoutSession(string providerSessionReference, Uri checkoutUri, DateTime? expiresAtUtc = null)
    {
        ProviderSessionReference = PaymentGatewayContract.RequireBounded(providerSessionReference, 200, nameof(providerSessionReference));
        CheckoutUri = PaymentGatewayContract.RequireNavigationUri(checkoutUri, nameof(checkoutUri));
        if (expiresAtUtc.HasValue) PaymentGatewayContract.RequireUtc(expiresAtUtc.Value, nameof(expiresAtUtc));
        ExpiresAtUtc = expiresAtUtc;
    }

    public string ProviderSessionReference { get; }
    public Uri CheckoutUri { get; }
    public DateTime? ExpiresAtUtc { get; }
    public GatewayEvidenceAuthority Authority => GatewayEvidenceAuthority.NavigationOnly;
}

public sealed class GatewayWebhookEnvelope
{
    public GatewayWebhookEnvelope(
        string body,
        IReadOnlyDictionary<string, string> headers,
        string correlationId)
    {
        Body = body ?? throw new ArgumentNullException(nameof(body));
        if (Body.Length > 1_000_000)
            throw new ArgumentException("Webhook body exceeds the supported one-megabyte boundary.", nameof(body));
        Headers = headers ?? throw new ArgumentNullException(nameof(headers));
        CorrelationId = PaymentGatewayContract.RequireBounded(correlationId, 100, nameof(correlationId));
    }

    public string Body { get; }
    public IReadOnlyDictionary<string, string> Headers { get; }
    public string CorrelationId { get; }
}

public sealed class GatewayWebhookVerificationResult
{
    private GatewayWebhookVerificationResult(bool isAuthentic, GatewayVerifiedEvent? gatewayEvent, string? sanitizedFailure)
    {
        IsAuthentic = isAuthentic;
        Event = gatewayEvent;
        SanitizedFailure = sanitizedFailure;
    }

    public bool IsAuthentic { get; }
    public GatewayVerifiedEvent? Event { get; }
    public string? SanitizedFailure { get; }

    public static GatewayWebhookVerificationResult Verified(GatewayVerifiedEvent gatewayEvent) =>
        new(true, gatewayEvent ?? throw new ArgumentNullException(nameof(gatewayEvent)), null);

    public static GatewayWebhookVerificationResult Rejected(string sanitizedFailure) =>
        new(false, null, PaymentGatewayContract.RequireBounded(sanitizedFailure, 500, nameof(sanitizedFailure)));
}

public sealed class GatewayVerifiedEvent
{
    public GatewayVerifiedEvent(
        string providerEventId,
        string providerSubscriptionReference,
        GatewaySubscriptionState state,
        DateTime occurredAtUtc,
        string eventType)
    {
        ProviderEventId = PaymentGatewayContract.RequireBounded(providerEventId, 200, nameof(providerEventId));
        ProviderSubscriptionReference = PaymentGatewayContract.RequireBounded(providerSubscriptionReference, 200, nameof(providerSubscriptionReference));
        State = state;
        PaymentGatewayContract.RequireUtc(occurredAtUtc, nameof(occurredAtUtc));
        OccurredAtUtc = occurredAtUtc;
        EventType = PaymentGatewayContract.RequireBounded(eventType, 160, nameof(eventType));
    }

    public string ProviderEventId { get; }
    public string ProviderSubscriptionReference { get; }
    public GatewaySubscriptionState State { get; }
    public DateTime OccurredAtUtc { get; }
    public string EventType { get; }
    public GatewayEvidenceAuthority Authority => GatewayEvidenceAuthority.VerifiedWebhook;
}

public sealed class GatewaySubscriptionSnapshot
{
    public GatewaySubscriptionSnapshot(
        string providerSubscriptionReference,
        GatewaySubscriptionState state,
        DateTime observedAtUtc,
        DateTime? currentPeriodStartUtc = null,
        DateTime? currentPeriodEndsAtUtc = null,
        bool cancelAtPeriodEnd = false,
        string? providerPlanReference = null)
    {
        ProviderSubscriptionReference = PaymentGatewayContract.RequireBounded(providerSubscriptionReference, 200, nameof(providerSubscriptionReference));
        State = state;
        PaymentGatewayContract.RequireUtc(observedAtUtc, nameof(observedAtUtc));
        PaymentGatewayContract.ValidateOptionalRange(currentPeriodStartUtc, currentPeriodEndsAtUtc, nameof(currentPeriodStartUtc), nameof(currentPeriodEndsAtUtc));
        ObservedAtUtc = observedAtUtc;
        CurrentPeriodStartUtc = currentPeriodStartUtc;
        CurrentPeriodEndsAtUtc = currentPeriodEndsAtUtc;
        CancelAtPeriodEnd = cancelAtPeriodEnd;
        ProviderPlanReference = PaymentGatewayContract.OptionalBounded(providerPlanReference, 200, nameof(providerPlanReference));
    }

    public string ProviderSubscriptionReference { get; }
    public GatewaySubscriptionState State { get; }
    public DateTime ObservedAtUtc { get; }
    public DateTime? CurrentPeriodStartUtc { get; }
    public DateTime? CurrentPeriodEndsAtUtc { get; }
    public bool CancelAtPeriodEnd { get; }
    public string? ProviderPlanReference { get; }
    public GatewayEvidenceAuthority Authority => GatewayEvidenceAuthority.ProviderApiSnapshot;
}

public sealed class GatewaySubscriptionCommandRequest
{
    public GatewaySubscriptionCommandRequest(string providerSubscriptionReference, string correlationId)
    {
        ProviderSubscriptionReference = PaymentGatewayContract.RequireBounded(providerSubscriptionReference, 200, nameof(providerSubscriptionReference));
        CorrelationId = PaymentGatewayContract.RequireBounded(correlationId, 100, nameof(correlationId));
    }

    public string ProviderSubscriptionReference { get; }
    public string CorrelationId { get; }
}

public sealed class GatewayPlanChangeRequest
{
    public GatewayPlanChangeRequest(
        string providerSubscriptionReference,
        string targetProviderPlanReference,
        string correlationId)
    {
        ProviderSubscriptionReference = PaymentGatewayContract.RequireBounded(providerSubscriptionReference, 200, nameof(providerSubscriptionReference));
        TargetProviderPlanReference = PaymentGatewayContract.RequireBounded(targetProviderPlanReference, 200, nameof(targetProviderPlanReference));
        CorrelationId = PaymentGatewayContract.RequireBounded(correlationId, 100, nameof(correlationId));
    }

    public string ProviderSubscriptionReference { get; }
    public string TargetProviderPlanReference { get; }
    public string CorrelationId { get; }
}

public sealed class GatewayCommandResult
{
    private GatewayCommandResult(bool accepted, string? providerOperationReference, string sanitizedSummary, Uri? approvalUri)
    {
        Accepted = accepted;
        ProviderOperationReference = providerOperationReference;
        SanitizedSummary = sanitizedSummary;
        ApprovalUri = approvalUri;
    }

    public bool Accepted { get; }
    public string? ProviderOperationReference { get; }
    public string SanitizedSummary { get; }
    public Uri? ApprovalUri { get; }
    public bool RequiresUserApproval => ApprovalUri is not null;

    public static GatewayCommandResult AcceptedResult(string? providerOperationReference, string summary, Uri? approvalUri = null) =>
        new(
            true,
            PaymentGatewayContract.OptionalBounded(providerOperationReference, 200, nameof(providerOperationReference)),
            PaymentGatewayContract.RequireBounded(summary, 500, nameof(summary)),
            approvalUri is null ? null : PaymentGatewayContract.RequireNavigationUri(approvalUri, nameof(approvalUri)));

    public static GatewayCommandResult RejectedResult(string summary) =>
        new(false, null, PaymentGatewayContract.RequireBounded(summary, 500, nameof(summary)), null);
}

public static class PaymentGatewayContract
{
    public static string NormalizeGatewayKey(string gatewayKey)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(gatewayKey);
        return gatewayKey.Trim().ToLowerInvariant();
    }

    internal static string RequireBounded(string? value, int maxLength, string parameterName)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value, parameterName);
        var clean = value.Trim();
        if (clean.Length > maxLength)
            throw new ArgumentException($"Value must be at most {maxLength} characters.", parameterName);
        return clean;
    }

    internal static string? OptionalBounded(string? value, int maxLength, string parameterName)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        var clean = value.Trim();
        if (clean.Length > maxLength)
            throw new ArgumentException($"Value must be at most {maxLength} characters.", parameterName);
        return clean;
    }

    internal static Uri RequireNavigationUri(Uri? value, string parameterName)
    {
        if (value is null || !value.IsAbsoluteUri ||
            (value.Scheme != Uri.UriSchemeHttps && value.Scheme != Uri.UriSchemeHttp))
            throw new ArgumentException("Navigation URI must be an absolute HTTP or HTTPS URI.", parameterName);
        return value;
    }

    internal static void RequireUtc(DateTime value, string parameterName)
    {
        if (value.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Timestamp must be UTC.", parameterName);
    }

    internal static void ValidateOptionalRange(DateTime? startUtc, DateTime? endUtc, string startName, string endName)
    {
        if (startUtc.HasValue) RequireUtc(startUtc.Value, startName);
        if (endUtc.HasValue) RequireUtc(endUtc.Value, endName);
        if (startUtc.HasValue != endUtc.HasValue)
            throw new ArgumentException("Subscription period requires both start and end timestamps.");
        if (startUtc.HasValue && endUtc <= startUtc)
            throw new ArgumentException("Subscription period end must be later than start.");
    }
}
