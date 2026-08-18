using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.Billing;

namespace AIWordPressManager.Infrastructure.Billing;

public sealed class PayPalPaymentGateway(
    HttpClient httpClient,
    IPayPalRuntimeConfigurationProvider runtimeConfigurationProvider) : IPaymentGateway
{
    private const string CreateSubscriptionPath = "v1/billing/subscriptions";

    public PaymentGatewayDescriptor Descriptor { get; } = new(
        "paypal",
        "PayPal",
        PaymentGatewayCapability.SubscriptionCheckout);

    public async Task<GatewayCheckoutSession> CreateSubscriptionCheckoutAsync(
        GatewayCheckoutRequest request,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        var access = await GetAccessTokenAsync(cancellationToken);
        var endpoint = new Uri(PayPalApiEndpoints.GetApiBaseUri(access.Environment), CreateSubscriptionPath);

        using var httpRequest = new HttpRequestMessage(HttpMethod.Post, endpoint);
        httpRequest.Headers.Authorization = new AuthenticationHeaderValue("Bearer", access.Token);
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

        HttpResponseMessage response;
        try
        {
            response = await httpClient.SendAsync(httpRequest, cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (TaskCanceledException ex)
        {
            throw new InvalidOperationException("PayPal subscription checkout timed out.", ex);
        }
        catch (HttpRequestException ex)
        {
            throw new InvalidOperationException("PayPal subscription checkout could not reach the configured environment.", ex);
        }

        using (response)
        {
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
    }

    public Task<GatewayWebhookVerificationResult> VerifyWebhookAsync(
        GatewayWebhookEnvelope envelope,
        CancellationToken cancellationToken = default) =>
        throw Unsupported(PaymentGatewayCapability.WebhookVerification);

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

    private async Task<AccessTokenResult> GetAccessTokenAsync(CancellationToken cancellationToken)
    {
        var runtime = await runtimeConfigurationProvider.GetRequiredAsync(cancellationToken);
        var endpoint = new Uri(PayPalApiEndpoints.GetApiBaseUri(runtime.Environment), PayPalApiEndpoints.OAuthTokenPath);
        using var request = new HttpRequestMessage(HttpMethod.Post, endpoint);
        var basicPayload = Convert.ToBase64String(Encoding.UTF8.GetBytes($"{runtime.ClientId}:{runtime.ClientSecret}"));
        request.Headers.Authorization = new AuthenticationHeaderValue("Basic", basicPayload);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        request.Content = new FormUrlEncodedContent(new Dictionary<string, string>
        {
            ["grant_type"] = "client_credentials"
        });

        HttpResponseMessage response;
        try
        {
            response = await httpClient.SendAsync(request, cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (TaskCanceledException ex)
        {
            throw new InvalidOperationException("PayPal OAuth authentication timed out.", ex);
        }
        catch (HttpRequestException ex)
        {
            throw new InvalidOperationException("PayPal OAuth authentication could not reach the configured environment.", ex);
        }

        using (response)
        {
            if (!response.IsSuccessStatusCode)
                throw new InvalidOperationException($"PayPal OAuth authentication was rejected (HTTP {(int)response.StatusCode}).");

            try
            {
                await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
                using var document = await JsonDocument.ParseAsync(stream, cancellationToken: cancellationToken);
                var token = ReadRequiredString(
                    document.RootElement,
                    "access_token",
                    8192,
                    "PayPal OAuth response did not include an access token.");
                return new(token, runtime.Environment);
            }
            catch (JsonException ex)
            {
                throw new InvalidOperationException("PayPal OAuth returned an unreadable success response.", ex);
            }
        }
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

    private sealed record AccessTokenResult(string Token, PayPalEnvironment Environment);
}
