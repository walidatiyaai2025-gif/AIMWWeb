using AIWordPressManager.Application.Settings;
using AIWordPressManager.Persistence;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Permission-enforced boundary for interactive administration of AI provider settings.
/// Runtime consumers must continue to depend on <see cref="IApplicationSettingsService"/>
/// directly so background and normal AI execution can read provider configuration without
/// acquiring an administrative permission.
/// </summary>
public sealed class AIProviderSettingsAdministrationService(
    IApplicationSettingsService settingsService,
    CurrentUserContext currentUser,
    AppDbContext? dbContext = null,
    IHttpContextAccessor? httpContextAccessor = null)
{
    private readonly ApplicationSecurityAuditService? _securityAudit = dbContext is null
        ? null
        : new ApplicationSecurityAuditService(dbContext, currentUser, httpContextAccessor);

    public Task<AiSettings> GetAiSettingsAsync(CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        return settingsService.GetAiSettingsAsync(cancellationToken);
    }

    public async Task SaveAiSettingsAsync(
        AiSettings settings,
        IReadOnlyDictionary<string, string?> plainApiKeys,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        await settingsService.SaveAiSettingsAsync(settings, plainApiKeys, cancellationToken);
        if (_securityAudit is null) return;

        var providersWithNewKey = plainApiKeys
            .Where(pair => !string.IsNullOrWhiteSpace(pair.Value))
            .Select(pair => pair.Key)
            .OrderBy(value => value, StringComparer.OrdinalIgnoreCase)
            .ToArray();
        await _securityAudit.RecordCurrentAsync(
            "Configuration",
            "AIProviders.Updated",
            "Succeeded",
            "AIProviderSettings",
            "runtime",
            "AI provider runtime settings",
            new Dictionary<string, string>
            {
                ["enabled"] = settings.Enabled.ToString(),
                ["automaticFallback"] = settings.AutomaticFallback.ToString(),
                ["providerCount"] = settings.Providers.Count.ToString(),
                ["providersWithNewKey"] = string.Join(',', providersWithNewKey)
            },
            cancellationToken);
    }

    public async Task ClearAiProviderApiKeyAsync(string provider, CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        await settingsService.ClearAiProviderApiKeyAsync(provider, cancellationToken);
        if (_securityAudit is null) return;

        await _securityAudit.RecordCurrentAsync(
            "Configuration",
            "AIProvider.CredentialCleared",
            "Succeeded",
            "AIProvider",
            provider,
            provider,
            null,
            cancellationToken);
    }

    public Task<AiSettings> GetAsync(CancellationToken cancellationToken = default) => GetAiSettingsAsync(cancellationToken);

    public Task SaveAsync(
        AiSettings settings,
        IReadOnlyDictionary<string, string?> plainApiKeys,
        CancellationToken cancellationToken = default) => SaveAiSettingsAsync(settings, plainApiKeys, cancellationToken);

    public Task ClearApiKeyAsync(string provider, CancellationToken cancellationToken = default) =>
        ClearAiProviderApiKeyAsync(provider, cancellationToken);
}