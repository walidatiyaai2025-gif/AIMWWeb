namespace AIWordPressManager.Application.Abstractions.Billing;

public enum PayPalEnvironment
{
    Sandbox = 1,
    Live = 2
}

public sealed record PayPalConfigurationView(
    bool Enabled,
    PayPalEnvironment Environment,
    string ClientId,
    bool HasClientSecret);

public sealed record PayPalConfigurationUpdate(
    bool Enabled,
    PayPalEnvironment Environment,
    string ClientId);

public sealed record PayPalRuntimeConfiguration(
    PayPalEnvironment Environment,
    string ClientId,
    string ClientSecret);

public sealed record PayPalConfigurationDiagnosticResult(
    bool Success,
    bool IsConfigured,
    PayPalEnvironment Environment,
    Uri Endpoint,
    int? HttpStatusCode,
    string SanitizedSummary);

public interface IPayPalConfigurationService
{
    Task<PayPalConfigurationView> GetAsync(CancellationToken cancellationToken = default);

    Task<PayPalConfigurationView> SaveAsync(
        PayPalConfigurationUpdate configuration,
        string? plainClientSecret,
        CancellationToken cancellationToken = default);

    Task<PayPalConfigurationView> ClearClientSecretAsync(CancellationToken cancellationToken = default);
}

public interface IPayPalRuntimeConfigurationProvider
{
    Task<PayPalRuntimeConfiguration> GetRequiredAsync(CancellationToken cancellationToken = default);
}

public interface IPayPalConfigurationDiagnostics
{
    Task<PayPalConfigurationDiagnosticResult> ValidateAsync(CancellationToken cancellationToken = default);
}

public static class PayPalApiEndpoints
{
    public static readonly Uri SandboxBaseUri = new("https://api-m.sandbox.paypal.com/");
    public static readonly Uri LiveBaseUri = new("https://api-m.paypal.com/");
    public const string OAuthTokenPath = "v1/oauth2/token";

    public static Uri GetApiBaseUri(PayPalEnvironment environment) => environment switch
    {
        PayPalEnvironment.Sandbox => SandboxBaseUri,
        PayPalEnvironment.Live => LiveBaseUri,
        _ => throw new ArgumentOutOfRangeException(nameof(environment), environment, "Unsupported PayPal environment.")
    };
}
