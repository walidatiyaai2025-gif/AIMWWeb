using System.Globalization;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.Billing;

namespace AIWordPressManager.Infrastructure.Billing;

public sealed class PayPalLifecyclePaymentGateway(
    PayPalPaymentGateway innerGateway,
    HttpClient httpClient,
    IPayPalRuntimeConfigurationProvider runtimeConfigurationProvider) : IPaymentGateway
{
    private const string SubscriptionPathPrefix = "v1/billing/subscriptions/";

    public PaymentGatewayDescriptor Descriptor { get; } = new(
        "paypal",
        "PayPal",
        PaymentGatewayCapability.SubscriptionCheckout |
        PaymentGatewayCapability.WebhookVerification |
        PaymentGatewayCapability.SubscriptionLookup);

    public Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(
        GatewayCheckoutRequest request,
        CancellationToken cancellationToken = default) =>
        innerGateway.CreateSubscriptionCheckoutAsync(request, cancellationToken);

    public Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(
        GatewayWebhookEnvelope envelope,
        CancellationToken cancellationToken = default) =>
        innerGateway.VerifyWebhookAsync(envelope, cancellationToken);

    public async Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(
        string providerSubscriptionReference,
        CancellationToken cancellationToken = default)
    {
        var reference = (providerSubscriptionReference ?? string.Empty).Trim();
        if (reference.Length == 0 || reference.Length > 200 || !reference.StartsWith("I-", StringComparison.OrdinalIgnoreCase))
            throw new ArgumentException("PayPal subscription reference is invalid.", nameof(providerSubscriptionReference));

        var runtime = await runtimeConfigurationProvider.GetRequiredAsync(cancellationToken);
        var token = await GetAccessTokenAsync(runtime, cancellationToken);
        var endpoint = new Uri(
            PayPalApiEndpoints.GetApiBaseUri(runtime.Environment),
            SubscriptionPathPrefix + Uri.EscapeDataString(reference));

        using var request = new HttpRequestMessage(HttpMethod.Get, endpoint);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));

        using var response = await SendAsync(request, "PayPal subscription lookup", cancellationToken);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"PayPal subscription lookup was rejected (HTTP {(int)response.StatusCode}).");

        try
        {
            await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            using var document = await JsonDocument.ParseAsync(stream, cancellationToken: cancellationToken);
            var root = document.RootElement;
            var returnedReference = ReadRequiredString(root, "id", 200, "PayPal subscription lookup did not include a valid subscription ID.");
            if (!string.Equals(returnedReference, reference, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("PayPal subscription lookup returned a different subscription ID.");

            _ = ReadRequiredString(root, "plan_id", 200, "PayPal subscription lookup did not include a valid plan ID.");
            var state = ReadState(root);
            var (periodStartUtc, periodEndUtc) = ReadBillingPeriod(root);

            return new GatewaySubscriptionSnapshot(
                returnedReference,
                state,
                DateTime.UtcNow,
                periodStartUtc,
                periodEndUtc,
                cancelAtPeriodEnd: false);
        }
        catch (JsonException ex)
        {
            throw new InvalidOperationException("PayPal subscription lookup returned an unreadable success response.", ex);
        }
    }

    public Task<GatewayCommandResult> CancelSubscriptionAsync(
        GatewaySubscriptionCommandRequest request,
        CancellationToken cancellationToken = default) =>
        innerGateway.CancelSubscriptionAsync(request, cancellationToken);

    public Task<GatewayCommandResult> ReactivateSubscriptionAsync(
        GatewaySubscriptionCommandRequest request,
        CancellationToken cancellationToken = default) =>
        innerGateway.ReactivateSubscriptionAsync(request, cancellationToken);

    public Task<GatewayCommandResult> ChangeSubscriptionPlanAsync(
        GatewayPlanChangeRequest request,
        CancellationToken cancellationToken = default) =>
        innerGateway.ChangeSubscriptionPlanAsync(request, cancellationToken);

    private async Task<string> GetAccessTokenAsync(
        PayPalRuntimeConfiguration runtime,
        CancellationToken cancellationToken)
    {
        var endpoint = new Uri(PayPalApiEndpoints.GetApiBaseUri(runtime.Environment), PayPalApiEndpoints.OAuthTokenPath);
        using var request = new HttpRequestMessage(HttpMethod.Post, endpoint);
        var basicPayload = Convert.ToBase64String(Encoding.UTF8.GetBytes($"{runtime.ClientId}:{runtime.ClientSecret}"));
        request.Headers.Authorization = new AuthenticationHeaderValue("Basic", basicPayload);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        request.Content = new FormUrlEncodedContent(new Dictionary<string, string>
        {
            ["grant_type"] = "client_credentials"
        });

        using var response = await SendAsync(request, "PayPal OAuth authentication", cancellationToken);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"PayPal OAuth authentication was rejected (HTTP {(int)response.StatusCode}).");

        try
        {
            await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            using var document = await JsonDocument.ParseAsync(stream, cancellationToken: cancellationToken);
            return ReadRequiredString(document.RootElement, "access_token", 8192, "PayPal OAuth response did not include an access token.");
        }
        catch (JsonException ex)
        {
            throw new InvalidOperationException("PayPal OAuth returned an unreadable success response.", ex);
        }
    }

    private async Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request,
        string operation,
        CancellationToken cancellationToken)
    {
        try
        {
            return await httpClient.SendAsync(request, cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (TaskCanceledException ex)
        {
            throw new InvalidOperationException($"{operation} timed out.", ex);
        }
        catch (HttpRequestException ex)
        {
            throw new InvalidOperationException($"{operation} could not reach the configured environment.", ex);
        }
    }

    private static GatewaySubscriptionState ReadState(JsonElement root)
    {
        if (!root.TryGetProperty("status", out var status) || status.ValueKind != JsonValueKind.String)
            return GatewaySubscriptionState.Unknown;

        return (status.GetString() ?? string.Empty).Trim().ToUpperInvariant() switch
        {
            "APPROVAL_PENDING" => GatewaySubscriptionState.Pending,
            "APPROVED" => GatewaySubscriptionState.Pending,
            "ACTIVE" => GatewaySubscriptionState.Active,
            "SUSPENDED" => GatewaySubscriptionState.Suspended,
            "CANCELLED" => GatewaySubscriptionState.Cancelled,
            "EXPIRED" => GatewaySubscriptionState.Expired,
            _ => GatewaySubscriptionState.Unknown
        };
    }

    private static (DateTime? StartUtc, DateTime? EndUtc) ReadBillingPeriod(JsonElement root)
    {
        if (!root.TryGetProperty("billing_info", out var billingInfo) || billingInfo.ValueKind != JsonValueKind.Object)
            return (null, null);
        if (!billingInfo.TryGetProperty("last_payment", out var lastPayment) || lastPayment.ValueKind != JsonValueKind.Object)
            return (null, null);
        if (!lastPayment.TryGetProperty("time", out var lastPaymentTime) || lastPaymentTime.ValueKind != JsonValueKind.String)
            return (null, null);
        if (!billingInfo.TryGetProperty("next_billing_time", out var nextBillingTime) || nextBillingTime.ValueKind != JsonValueKind.String)
            return (null, null);

        if (!TryReadUtc(lastPaymentTime.GetString(), out var startUtc) ||
            !TryReadUtc(nextBillingTime.GetString(), out var endUtc) ||
            endUtc <= startUtc)
            return (null, null);

        return (startUtc, endUtc);
    }

    private static bool TryReadUtc(string? value, out DateTime utc)
    {
        utc = default;
        if (!DateTimeOffset.TryParse(
                value,
                CultureInfo.InvariantCulture,
                DateTimeStyles.AllowWhiteSpaces | DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal,
                out var parsed))
            return false;
        utc = parsed.UtcDateTime;
        return true;
    }

    private static string ReadRequiredString(JsonElement element, string property, int maxLength, string failure)
    {
        if (!element.TryGetProperty(property, out var value) || value.ValueKind != JsonValueKind.String)
            throw new InvalidOperationException(failure);
        var clean = (value.GetString() ?? string.Empty).Trim();
        if (clean.Length == 0 || clean.Length > maxLength)
            throw new InvalidOperationException(failure);
        return clean;
    }
}
