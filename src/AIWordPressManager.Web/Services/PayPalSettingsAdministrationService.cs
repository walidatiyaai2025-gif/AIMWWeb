using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Persistence;

namespace AIWordPressManager.Web.Services;

public sealed class PayPalSettingsAdministrationService(
    IPayPalConfigurationService configurationService,
    IPayPalConfigurationDiagnostics diagnostics,
    CurrentUserContext currentUser,
    AppDbContext? dbContext = null,
    IHttpContextAccessor? httpContextAccessor = null)
{
    private readonly ApplicationSecurityAuditService? _securityAudit = dbContext is null
        ? null
        : new ApplicationSecurityAuditService(dbContext, currentUser, httpContextAccessor);

    public Task<PayPalConfigurationView> GetAsync(CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        return configurationService.GetAsync(cancellationToken);
    }

    public async Task<PayPalConfigurationView> SaveAsync(
        PayPalConfigurationUpdate configuration,
        string? plainClientSecret,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        var rotatedSecret = !string.IsNullOrWhiteSpace(plainClientSecret);
        var saved = await configurationService.SaveAsync(configuration, plainClientSecret, cancellationToken);
        if (_securityAudit is not null)
        {
            await _securityAudit.RecordCurrentAsync(
                "Configuration",
                "PayPal.Updated",
                "Succeeded",
                "PaymentGateway",
                "paypal",
                "PayPal",
                new Dictionary<string, string>
                {
                    ["enabled"] = saved.Enabled.ToString(),
                    ["environment"] = saved.Environment.ToString(),
                    ["clientSecretRotated"] = rotatedSecret.ToString()
                },
                cancellationToken);
        }
        return saved;
    }

    public async Task<PayPalConfigurationView> ClearClientSecretAsync(CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        var result = await configurationService.ClearClientSecretAsync(cancellationToken);
        if (_securityAudit is not null)
        {
            await _securityAudit.RecordCurrentAsync(
                "Configuration",
                "PayPal.CredentialCleared",
                "Succeeded",
                "PaymentGateway",
                "paypal",
                "PayPal",
                new Dictionary<string, string>
                {
                    ["enabledAfterClear"] = result.Enabled.ToString(),
                    ["environment"] = result.Environment.ToString()
                },
                cancellationToken);
        }
        return result;
    }

    public Task<PayPalConfigurationDiagnosticResult> ValidateAsync(CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        return diagnostics.ValidateAsync(cancellationToken);
    }
}
