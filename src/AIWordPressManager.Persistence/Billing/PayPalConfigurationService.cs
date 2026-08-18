using System.Text.RegularExpressions;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Billing;

public sealed class PayPalConfigurationService(
    AppDbContext dbContext,
    ISecretProtectionService secretProtectionService) :
    IPayPalConfigurationService,
    IPayPalRuntimeConfigurationProvider
{
    internal const string EnabledKey = "Billing.PayPal.Enabled";
    internal const string EnvironmentKey = "Billing.PayPal.Environment";
    internal const string ClientIdKey = "Billing.PayPal.ClientId";
    internal const string ProtectedClientSecretKey = "Billing.PayPal.ProtectedClientSecret";
    internal const string WebhookIdKey = "Billing.PayPal.WebhookId";

    private static readonly string[] Keys = [EnabledKey, EnvironmentKey, ClientIdKey, ProtectedClientSecretKey, WebhookIdKey];
    private static readonly Regex WebhookIdPattern = new("^[A-Za-z0-9]+$", RegexOptions.CultureInvariant | RegexOptions.Compiled);

    public async Task<PayPalConfigurationView> GetAsync(CancellationToken cancellationToken = default)
    {
        var values = await LoadValuesAsync(cancellationToken);
        return ToView(values);
    }

    public async Task<PayPalConfigurationView> SaveAsync(
        PayPalConfigurationUpdate configuration,
        string? plainClientSecret,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(configuration);
        ValidateEnvironment(configuration.Environment);
        var clientId = NormalizeClientId(configuration.ClientId, configuration.Enabled);
        var values = await LoadValuesAsync(cancellationToken);
        var existingProtectedSecret = values.TryGetValue(ProtectedClientSecretKey, out var existing) ? existing : string.Empty;
        var existingWebhookId = values.TryGetValue(WebhookIdKey, out var storedWebhookId) ? storedWebhookId.Trim() : string.Empty;
        var webhookId = configuration.WebhookId is null
            ? existingWebhookId
            : NormalizeWebhookId(configuration.WebhookId);

        string? protectedSecret = null;
        if (!string.IsNullOrWhiteSpace(plainClientSecret))
        {
            var cleanSecret = plainClientSecret.Trim();
            if (cleanSecret.Length > 500)
                throw new ArgumentException("PayPal client secret must be at most 500 characters.", nameof(plainClientSecret));
            protectedSecret = await secretProtectionService.ProtectAsync(cleanSecret, cancellationToken);
        }

        var hasSecretAfterSave = !string.IsNullOrWhiteSpace(protectedSecret) || !string.IsNullOrWhiteSpace(existingProtectedSecret);
        if (configuration.Enabled && !hasSecretAfterSave)
            throw new InvalidOperationException("PayPal cannot be enabled until a client secret has been configured.");

        await UpsertAsync(EnabledKey, configuration.Enabled.ToString(), cancellationToken);
        await UpsertAsync(EnvironmentKey, configuration.Environment.ToString(), cancellationToken);
        await UpsertAsync(ClientIdKey, clientId, cancellationToken);
        if (!string.IsNullOrWhiteSpace(protectedSecret))
            await UpsertAsync(ProtectedClientSecretKey, protectedSecret, cancellationToken);
        if (configuration.WebhookId is not null)
            await UpsertAsync(WebhookIdKey, webhookId, cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);

        return new(configuration.Enabled, configuration.Environment, clientId, hasSecretAfterSave, webhookId);
    }

    public async Task<PayPalConfigurationView> ClearClientSecretAsync(CancellationToken cancellationToken = default)
    {
        var secretRows = await dbContext.ApplicationSettings
            .Where(x => x.Key == ProtectedClientSecretKey)
            .ToListAsync(cancellationToken);
        if (secretRows.Count > 0)
            dbContext.ApplicationSettings.RemoveRange(secretRows);

        // Clearing a credential must not leave an apparently enabled runtime integration.
        await UpsertAsync(EnabledKey, bool.FalseString, cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);
        var values = await LoadValuesAsync(cancellationToken);
        return ToView(values);
    }

    public async Task<PayPalRuntimeConfiguration> GetRequiredAsync(CancellationToken cancellationToken = default)
    {
        var values = await LoadValuesAsync(cancellationToken);
        var view = ToView(values);
        if (!view.Enabled)
            throw new InvalidOperationException("PayPal integration is disabled.");
        if (string.IsNullOrWhiteSpace(view.ClientId) || !view.HasClientSecret)
            throw new InvalidOperationException("PayPal integration is not fully configured.");

        var protectedSecret = values[ProtectedClientSecretKey];
        string secret;
        try
        {
            secret = await secretProtectionService.UnprotectAsync(protectedSecret, cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            throw new InvalidOperationException(
                "The stored PayPal credential could not be decrypted. Re-enter the client secret in PayPal settings.",
                ex);
        }

        if (string.IsNullOrWhiteSpace(secret))
            throw new InvalidOperationException("The stored PayPal credential decrypted to an empty value. Re-enter the client secret.");

        return new(view.Environment, view.ClientId, secret, view.WebhookId);
    }

    private async Task<Dictionary<string, string>> LoadValuesAsync(CancellationToken cancellationToken) =>
        await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => Keys.Contains(x.Key))
            .ToDictionaryAsync(x => x.Key, x => x.Value, cancellationToken);

    private static PayPalConfigurationView ToView(IReadOnlyDictionary<string, string> values)
    {
        var enabled = values.TryGetValue(EnabledKey, out var enabledRaw) && bool.TryParse(enabledRaw, out var parsedEnabled) && parsedEnabled;
        var environment = values.TryGetValue(EnvironmentKey, out var environmentRaw) &&
                          Enum.TryParse<PayPalEnvironment>(environmentRaw, true, out var parsedEnvironment) &&
                          Enum.IsDefined(parsedEnvironment)
            ? parsedEnvironment
            : PayPalEnvironment.Sandbox;
        var clientId = values.TryGetValue(ClientIdKey, out var clientIdRaw) ? clientIdRaw.Trim() : string.Empty;
        var hasSecret = values.TryGetValue(ProtectedClientSecretKey, out var secret) && !string.IsNullOrWhiteSpace(secret);
        var webhookId = values.TryGetValue(WebhookIdKey, out var webhookIdRaw) ? webhookIdRaw.Trim() : string.Empty;
        return new(enabled, environment, clientId, hasSecret, webhookId);
    }

    private async Task UpsertAsync(string key, string value, CancellationToken cancellationToken)
    {
        var row = await dbContext.ApplicationSettings.SingleOrDefaultAsync(x => x.Key == key, cancellationToken);
        if (row is null)
            dbContext.ApplicationSettings.Add(new ApplicationSetting(key, value, DateTime.UtcNow));
        else
            row.SetValue(key, value, DateTime.UtcNow);
    }

    private static string NormalizeClientId(string? clientId, bool enabled)
    {
        var clean = (clientId ?? string.Empty).Trim();
        if (enabled && clean.Length == 0)
            throw new ArgumentException("PayPal client ID is required when the integration is enabled.", nameof(clientId));
        if (clean.Length > 300)
            throw new ArgumentException("PayPal client ID must be at most 300 characters.", nameof(clientId));
        return clean;
    }

    private static string NormalizeWebhookId(string? webhookId)
    {
        var clean = (webhookId ?? string.Empty).Trim();
        if (clean.Length == 0) return string.Empty;
        if (clean.Length > 50 || !WebhookIdPattern.IsMatch(clean))
            throw new ArgumentException("PayPal webhook ID must be alphanumeric and at most 50 characters.", nameof(webhookId));
        return clean;
    }

    private static void ValidateEnvironment(PayPalEnvironment environment)
    {
        if (!Enum.IsDefined(environment))
            throw new ArgumentOutOfRangeException(nameof(environment), environment, "Unsupported PayPal environment.");
    }
}
