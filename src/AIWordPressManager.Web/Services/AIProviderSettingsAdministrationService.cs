using AIWordPressManager.Application.Settings;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Permission-enforced boundary for interactive administration of AI provider settings.
/// Runtime consumers must continue to depend on <see cref="IApplicationSettingsService"/>
/// directly so background and normal AI execution can read provider configuration without
/// acquiring an administrative permission.
/// </summary>
public sealed class AIProviderSettingsAdministrationService(
    IApplicationSettingsService settingsService,
    CurrentUserContext currentUser)
{
    public Task<AiSettings> GetAsync(CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        return settingsService.GetAiSettingsAsync(cancellationToken);
    }

    public Task SaveAsync(
        AiSettings settings,
        IReadOnlyDictionary<string, string?> plainApiKeys,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        return settingsService.SaveAiSettingsAsync(settings, plainApiKeys, cancellationToken);
    }

    public Task ClearApiKeyAsync(string provider, CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        return settingsService.ClearAiProviderApiKeyAsync(provider, cancellationToken);
    }
}