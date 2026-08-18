using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.Billing;

namespace AIWordPressManager.Infrastructure.Billing;

public sealed class PayPalConfigurationDiagnostics(
    HttpClient httpClient,
    IPayPalConfigurationService configurationService,
    IPayPalRuntimeConfigurationProvider runtimeConfigurationProvider) : IPayPalConfigurationDiagnostics
{
    public async Task<PayPalConfigurationDiagnosticResult> ValidateAsync(CancellationToken cancellationToken = default)
    {
        var view = await configurationService.GetAsync(cancellationToken);
        var endpoint = new Uri(PayPalApiEndpoints.GetApiBaseUri(view.Environment), PayPalApiEndpoints.OAuthTokenPath);
        if (!view.Enabled || string.IsNullOrWhiteSpace(view.ClientId) || !view.HasClientSecret)
        {
            return new(
                false,
                false,
                view.Environment,
                endpoint,
                null,
                "PayPal configuration is incomplete or disabled.");
        }

        PayPalRuntimeConfiguration runtime;
        try
        {
            runtime = await runtimeConfigurationProvider.GetRequiredAsync(cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception)
        {
            return new(
                false,
                true,
                view.Environment,
                endpoint,
                null,
                "PayPal credentials could not be loaded securely. Re-enter the client secret.");
        }

        using var request = new HttpRequestMessage(HttpMethod.Post, endpoint);
        var basicPayload = Convert.ToBase64String(Encoding.UTF8.GetBytes($"{runtime.ClientId}:{runtime.ClientSecret}"));
        request.Headers.Authorization = new AuthenticationHeaderValue("Basic", basicPayload);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        request.Content = new FormUrlEncodedContent(new Dictionary<string, string>
        {
            ["grant_type"] = "client_credentials"
        });

        try
        {
            using var response = await httpClient.SendAsync(request, cancellationToken);
            var statusCode = (int)response.StatusCode;
            if (!response.IsSuccessStatusCode)
            {
                return new(
                    false,
                    true,
                    runtime.Environment,
                    endpoint,
                    statusCode,
                    $"PayPal OAuth authentication was rejected (HTTP {statusCode}).");
            }

            await using var responseStream = await response.Content.ReadAsStreamAsync(cancellationToken);
            using var document = await JsonDocument.ParseAsync(responseStream, cancellationToken: cancellationToken);
            var hasAccessToken = document.RootElement.TryGetProperty("access_token", out var accessToken) &&
                                 accessToken.ValueKind == JsonValueKind.String &&
                                 !string.IsNullOrWhiteSpace(accessToken.GetString());
            if (!hasAccessToken)
            {
                return new(
                    false,
                    true,
                    runtime.Environment,
                    endpoint,
                    statusCode,
                    "PayPal OAuth responded successfully but did not return an access token.");
            }

            return new(
                true,
                true,
                runtime.Environment,
                endpoint,
                statusCode,
                "PayPal OAuth authentication succeeded.");
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (TaskCanceledException)
        {
            return new(false, true, runtime.Environment, endpoint, null, "PayPal OAuth authentication timed out.");
        }
        catch (HttpRequestException ex)
        {
            return new(
                false,
                true,
                runtime.Environment,
                endpoint,
                ex.StatusCode.HasValue ? (int)ex.StatusCode.Value : null,
                "PayPal OAuth authentication could not reach the configured environment.");
        }
        catch (JsonException)
        {
            return new(false, true, runtime.Environment, endpoint, 200, "PayPal OAuth returned an unreadable success response.");
        }
    }
}
