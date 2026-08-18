using System.Globalization;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.Abstractions.Billing;

namespace AIWordPressManager.Infrastructure.Billing;

public sealed class PayPalPaymentGateway(
    HttpClient httpClient,
    IPayPalRuntimeConfigurationProvider runtimeConfigurationProvider) : IPaymentGateway
{
    private const string CreateSubscriptionPath = "v1/billing/subscriptions";
    private static readonly Regex AuthAlgorithmPattern = new("^[A-Za-z0-9]+$", RegexOptions.CultureInvariant | RegexOptions.Compiled);

    public PaymentGatewayDescriptor Descriptor { get; } = new(
        "paypal",
        "PayPal",
        PaymentGatewayCapability.SubscriptionCheckout | PaymentGatewayCapability.WebhookVerification);

    public async Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(
        GatewayCheckoutRequest request,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        var runtime = await runtimeConfigurationProvider.GetRequiredAsync(cancellationToken);
        var accessToken = await GetAccessTokenAsync(runtime, cancellationToken);
        var endpoint = new Uri(PayPalApiEndpoints.GetApiBaseUri(runtime.Environment), CreateSubscriptionPath);

        using var httpRequest = new HttpRequestMessage(HttpMethod.Post, endpoint);
        httpRequest.Headers.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);
        httpRequest.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        httpRequest.Headers.TryAddWithoutValidation("PayPal-Request-Id", BuildRequestId(request.CorrelationId));
        httpRequest.Headers.TryAddWithoutValidation("Prefer", "return=representation");
        httpRequest.Content = new StringContent(
            JsonSerializer.Serialize(new
            {
                plan_id = request.ProviderPlanReference,
                application_context = new
                {
                    user_action = "SUBSCRIBE_NOW",
                    return_url = request.ReturnUri.ToString(),
                    cancel_url = request.CancelUri.ToString()
                }
            }),
            Encoding.UTF8,
            "application/json");

        using var response = await SendAsync(
            httpRequest,
            "PayPal subscription checkout",
            cancellationToken);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"PayPal subscription checkout was rejected (HTTP {(int)response.StatusCode}).");

        try
        {
            await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            using var document = await JsonDocument.ParseAsync(stream, cancellationToken: cancellationToken);
            var root = document.RootElement;
            var subscriptionId = ReadRequiredString(root, "id", 200, "PayPal subscription response did not include a valid subscription ID.");
            if (!subscriptionId.StartsWith("I-", StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("PayPal subscription response returned an unexpected subscription ID format.");

            var approvalUri = FindApprovalUri(root);
            return new GatewayCheckoutSession(subscriptionId, approvalUri);
        }
        catch (JsonException ex)
        {
            throw new InvalidOperationException("PayPal subscription checkout returned an unreadable success response.", ex);
        }
    }

    public async Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(
        GatewayWebhookEnvelope envelope,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(envelope);
        var runtime = await runtimeConfigurationProvider.GetRequiredAsync(cancellationToken);
        if (string.IsNullOrWhiteSpace(runtime.WebhookId))
            throw new InvalidOperationException("PayPal webhook ID is not configured for the selected environment.");

        if (!TryReadVerificationHeaders(envelope.Headers, out var headers, out var headerFailure))
            return GatewayWebhookVerificationResult.Rejected(headerFailure);

        JsonDocument eventDocument;
        try
        {
            eventDocument = JsonDocument.Parse(envelope.Body);
        }
        catch (JsonException)
        {
            return GatewayWebhookVerificationResult.Rejected("PayPal webhook payload is not valid JSON.");
        }

        using (eventDocument)
        {
            if (eventDocument.RootElement.ValueKind != JsonValueKind.Object)
                return GatewayWebhookVerificationResult.Rejected("PayPal webhook payload must be a JSON object.");

            var accessToken = await GetAccessTokenAsync(runtime, cancellationToken);
            var endpoint = new Uri(
                PayPalApiEndpoints.GetApiBaseUri(runtime.Environment),
                PayPalApiEndpoints.VerifyWebhookSignaturePath);
            using var request = new HttpRequestMessage(HttpMethod.Post, endpoint);
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);
            request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
            request.Content = new StringContent(
                JsonSerializer.Serialize(new
                {
                    auth_algo = headers.AuthAlgorithm,
                    cert_url = headers.CertificateUrl.ToString(),
                    transmission_id = headers.TransmissionId,
                    transmission_sig = headers.TransmissionSignature,
                    transmission_time = headers.TransmissionTime,
                    webhook_id = runtime.WebhookId,
                    webhook_event = eventDocument.RootElement
                }),
                Encoding.UTF8,
                "application/json");

            using var response = await SendAsync(request, "PayPal webhook verification", cancellationToken);
            if (!response.IsSuccessStatusCode)
                throw new InvalidOperationException($"PayPal webhook verification was unavailable (HTTP {(int)response.StatusCode}).");

            try
            {
                await using var responseStream = await response.Content.ReadAsStreamAsync(cancellationToken);
                using var verification = await JsonDocument.ParseAsync(responseStream, cancellationToken: cancellationToken);
                var verified = verification.RootElement.TryGetProperty("verification_status", out var status) &&
                               status.ValueKind == JsonValueKind.String &&
                               string.Equals(status.GetString(), "SUCCESS", StringComparison.OrdinalIgnoreCase);
                if (!verified)
                    return GatewayWebhookVerificationResult.Rejected("PayPal webhook signature verification failed.");

                return NormalizeVerifiedSubscriptionEvent(eventDocument.RootElement);
            }
            catch (JsonException ex)
            {
                throw new InvalidOperationException("PayPal webhook verification returned an unreadable response.", ex);
            }
        }
    }

    public Task<GatewaySubscriptionSnapshot> GetSubscriptionAsync(
        string providerSubscriptionReference,
        CancellationToken cancellationToken = default) =>
        throw Unsupported(PaymentGatewayCapability.SubscriptionLookup);

    public Task<GatewayCommandResult> CancelSubscriptionAsync(
        GatewaySubscriptionCommandRequest request,
        CancellationToken cancellationToken = default) =>
        throw Unsupported(PaymentGatewayCapability.CancelSubscription);

    public Task<GatewayCommandResult> ReactivateSubscriptionAsync(
        GatewaySubscriptionCommandRequest request,
        CancellationToken cancellationToken = default) =>
        throw Unsupported(PaymentGatewayCapability.ReactivateSubscription);

    public Task<GatewayCommandResult> ChangeSubscriptionPlanAsync(
        GatewayPlanChangeRequest request,
        CancellationToken cancellationToken = default) =>
        throw Unsupported(PaymentGatewayCapability.ChangeSubscriptionPlan);

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
            return ReadRequiredString(
                document.RootElement,
                "access_token",
                8192,
                "PayPal OAuth response did not include an access token.");
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

    private static bool TryReadVerificationHeaders(
        IReadOnlyDictionary<string, string> values,
        out VerificationHeaders headers,
        out string failure)
    {
        headers = default!;
        failure = string.Empty;

        if (!TryGetHeader(values, "PAYPAL-AUTH-ALGO", 100, out var authAlgorithm) ||
            !AuthAlgorithmPattern.IsMatch(authAlgorithm))
        {
            failure = "PayPal webhook authentication algorithm header is missing or invalid.";
            return false;
        }
        if (!TryGetHeader(values, "PAYPAL-CERT-URL", 500, out var certificateUrlRaw) ||
            !Uri.TryCreate(certificateUrlRaw, UriKind.Absolute, out var certificateUrl) ||
            certificateUrl.Scheme != Uri.UriSchemeHttps ||
            !IsPayPalHost(certificateUrl.Host))
        {
            failure = "PayPal webhook certificate URL header is missing or invalid.";
            return false;
        }
        if (!TryGetHeader(values, "PAYPAL-TRANSMISSION-ID", 50, out var transmissionId))
        {
            failure = "PayPal webhook transmission ID header is missing or invalid.";
            return false;
        }
        if (!TryGetHeader(values, "PAYPAL-TRANSMISSION-SIG", 500, out var transmissionSignature))
        {
            failure = "PayPal webhook transmission signature header is missing or invalid.";
            return false;
        }
        if (!TryGetHeader(values, "PAYPAL-TRANSMISSION-TIME", 100, out var transmissionTime) ||
            !DateTimeOffset.TryParse(
                transmissionTime,
                CultureInfo.InvariantCulture,
                DateTimeStyles.AllowWhiteSpaces | DateTimeStyles.AssumeUniversal,
                out _))
        {
            failure = "PayPal webhook transmission time header is missing or invalid.";
            return false;
        }

        headers = new(
            authAlgorithm,
            certificateUrl,
            transmissionId,
            transmissionSignature,
            transmissionTime);
        return true;
    }

    private static bool TryGetHeader(
        IReadOnlyDictionary<string, string> values,
        string name,
        int maxLength,
        out string value)
    {
        foreach (var pair in values)
        {
            if (!string.Equals(pair.Key, name, StringComparison.OrdinalIgnoreCase)) continue;
            value = (pair.Value ?? string.Empty).Trim();
            return value.Length > 0 && value.Length <= maxLength;
        }
        value = string.Empty;
        return false;
    }

    private static GatewayWebhookVerificationResult NormalizeVerifiedSubscriptionEvent(JsonElement root)
    {
        var providerEventId = ReadRequiredString(
            root,
            "id",
            200,
            "Verified PayPal webhook did not include a valid event ID.");
        var eventType = ReadRequiredString(
            root,
            "event_type",
            160,
            "Verified PayPal webhook did not include a valid event type.");
        var createTimeRaw = ReadRequiredString(
            root,
            "create_time",
            100,
            "Verified PayPal webhook did not include a valid create time.");
        if (!DateTimeOffset.TryParse(
                createTimeRaw,
                CultureInfo.InvariantCulture,
                DateTimeStyles.AllowWhiteSpaces | DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal,
                out var occurredAt))
        {
            return GatewayWebhookVerificationResult.Rejected("Verified PayPal webhook create time is invalid.");
        }

        if (!root.TryGetProperty("resource", out var resource) || resource.ValueKind != JsonValueKind.Object)
            return GatewayWebhookVerificationResult.Rejected("Verified PayPal subscription webhook did not include a resource object.");
        var subscriptionReference = ReadRequiredString(
            resource,
            "id",
            200,
            "Verified PayPal subscription webhook did not include a subscription reference.");
        if (!subscriptionReference.StartsWith("I-", StringComparison.OrdinalIgnoreCase))
            return GatewayWebhookVerificationResult.Rejected("Verified PayPal subscription webhook returned an unexpected subscription reference format.");

        var state = eventType.ToUpperInvariant() switch
        {
            "BILLING.SUBSCRIPTION.CREATED" => MapResourceStatus(resource, GatewaySubscriptionState.Pending),
            "BILLING.SUBSCRIPTION.ACTIVATED" => GatewaySubscriptionState.Active,
            "BILLING.SUBSCRIPTION.RE-ACTIVATED" => GatewaySubscriptionState.Active,
            "BILLING.SUBSCRIPTION.UPDATED" => MapResourceStatus(resource, GatewaySubscriptionState.Unknown),
            "BILLING.SUBSCRIPTION.EXPIRED" => GatewaySubscriptionState.Expired,
            "BILLING.SUBSCRIPTION.CANCELLED" => GatewaySubscriptionState.Cancelled,
            "BILLING.SUBSCRIPTION.SUSPENDED" => GatewaySubscriptionState.Suspended,
            "BILLING.SUBSCRIPTION.PAYMENT.FAILED" => GatewaySubscriptionState.PastDue,
            _ => GatewaySubscriptionState.Unknown
        };

        if (!eventType.StartsWith("BILLING.SUBSCRIPTION.", StringComparison.OrdinalIgnoreCase))
            return GatewayWebhookVerificationResult.Rejected("Verified PayPal webhook event type is outside the supported subscription scope.");

        return GatewayWebhookVerificationResult.Verified(new GatewayVerifiedEvent(
            providerEventId,
            subscriptionReference,
            state,
            occurredAt.UtcDateTime,
            eventType));
    }

    private static GatewaySubscriptionState MapResourceStatus(
        JsonElement resource,
        GatewaySubscriptionState fallback)
    {
        if (!resource.TryGetProperty("status", out var status) || status.ValueKind != JsonValueKind.String)
            return fallback;

        return (status.GetString() ?? string.Empty).Trim().ToUpperInvariant() switch
        {
            "APPROVAL_PENDING" => GatewaySubscriptionState.Pending,
            "APPROVED" => GatewaySubscriptionState.Pending,
            "ACTIVE" => GatewaySubscriptionState.Active,
            "SUSPENDED" => GatewaySubscriptionState.Suspended,
            "CANCELLED" => GatewaySubscriptionState.Cancelled,
            "EXPIRED" => GatewaySubscriptionState.Expired,
            _ => fallback
        };
    }

    private static string BuildRequestId(string correlationId)
    {
        var hash = SHA256.HashData(Encoding.UTF8.GetBytes($"paypal-subscription-checkout:{correlationId}"));
        var hex = Convert.ToHexString(hash.AsSpan(0, 16)).ToLowerInvariant();
        return $"{hex[..8]}-{hex[8..12]}-{hex[12..16]}-{hex[16..20]}-{hex[20..32]}";
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

    private static Uri FindApprovalUri(JsonElement root)
    {
        if (!root.TryGetProperty("links", out var links) || links.ValueKind != JsonValueKind.Array)
            throw new InvalidOperationException("PayPal subscription response did not include an approval link.");

        foreach (var link in links.EnumerateArray())
        {
            if (!link.TryGetProperty("rel", out var rel) ||
                rel.ValueKind != JsonValueKind.String ||
                !string.Equals(rel.GetString(), "approve", StringComparison.OrdinalIgnoreCase))
                continue;
            if (!link.TryGetProperty("href", out var href) || href.ValueKind != JsonValueKind.String)
                continue;
            if (!Uri.TryCreate(href.GetString(), UriKind.Absolute, out var uri) ||
                uri.Scheme != Uri.UriSchemeHttps ||
                !IsPayPalHost(uri.Host))
                throw new InvalidOperationException("PayPal subscription response returned an invalid approval link.");
            return uri;
        }

        throw new InvalidOperationException("PayPal subscription response did not include an approval link.");
    }

    private static bool IsPayPalHost(string host) =>
        string.Equals(host, "paypal.com", StringComparison.OrdinalIgnoreCase) ||
        host.EndsWith(".paypal.com", StringComparison.OrdinalIgnoreCase);

    private static NotSupportedException Unsupported(PaymentGatewayCapability capability) =>
        new($"PayPal gateway capability '{capability}' is not implemented in this release.");

    private sealed record VerificationHeaders(
        string AuthAlgorithm,
        Uri CertificateUrl,
        string TransmissionId,
        string TransmissionSignature,
        string TransmissionTime);
}
