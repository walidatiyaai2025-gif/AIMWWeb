using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence.Backups;
using AIWordPressManager.Persistence.Billing;
using AIWordPressManager.Persistence.Email;
using AIWordPressManager.Persistence.Initialization;
using AIWordPressManager.Persistence.Sites;
using AIWordPressManager.Persistence.WordPress;
using AIWordPressManager.Persistence.Jobs;
using AIWordPressManager.Persistence.Audits;
using AIWordPressManager.Application.ContentAudit;
using AIWordPressManager.Application.SeoAudit;
using AIWordPressManager.Application.BrokenLinks;
using AIWordPressManager.Application.Sites;
using AIWordPressManager.Application.Planning;
using AIWordPressManager.Persistence.Planning;
using AIWordPressManager.Application.Changes;
using AIWordPressManager.Persistence.Changes;
using AIWordPressManager.Application.Settings;
using AIWordPressManager.Application.SiteBrain;
using AIWordPressManager.Persistence.SiteBrain;
using AIWordPressManager.Persistence.ThemeIntelligence;
using AIWordPressManager.Persistence.Settings;
using AIWordPressManager.Application.Deletion;
using AIWordPressManager.Persistence.Deletion;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;

namespace AIWordPressManager.Persistence;

public static class DependencyInjection
{
    public static IServiceCollection AddPersistence(this IServiceCollection services)
    {
        services.AddDbContext<AppDbContext>((provider, options) =>
        {
            var configuration = provider.GetRequiredService<IConfiguration>();
            var paths = provider.GetRequiredService<IApplicationPathService>();
            var providerName = (configuration["Database:Provider"] ?? "SQLite").Trim();
            var configuredConnectionString = ResolveConnectionString(provider, configuration);

            if (providerName.Equals("SqlServer", StringComparison.OrdinalIgnoreCase))
            {
                EnsureConnectionString(configuredConnectionString, providerName);
                options.UseSqlServer(configuredConnectionString);
                return;
            }

            if (providerName.Equals("PostgreSQL", StringComparison.OrdinalIgnoreCase) ||
                providerName.Equals("Postgres", StringComparison.OrdinalIgnoreCase))
            {
                EnsureConnectionString(configuredConnectionString, providerName);
                options.UseNpgsql(configuredConnectionString);
                return;
            }

            if (providerName.Equals("MySQL", StringComparison.OrdinalIgnoreCase))
            {
                EnsureConnectionString(configuredConnectionString, providerName);
                options.UseMySql(configuredConnectionString, new MySqlServerVersion(new Version(8, 0, 0)));
                return;
            }

            if (providerName.Equals("MariaDB", StringComparison.OrdinalIgnoreCase))
            {
                EnsureConnectionString(configuredConnectionString, providerName);
                options.UseMySql(configuredConnectionString, new MariaDbServerVersion(new Version(10, 6, 0)));
                return;
            }

            var sqliteConnectionString = string.IsNullOrWhiteSpace(configuredConnectionString)
                ? new SqliteConnectionStringBuilder
                {
                    DataSource = paths.GetDatabasePath(),
                    ForeignKeys = true,
                    Pooling = true
                }.ToString()
                : configuredConnectionString;

            options.UseSqlite(sqliteConnectionString);
        });

        services.AddScoped<IDatabaseInitializationService, DatabaseInitializationService>();
        services.AddScoped<IDatabaseBackupService, DatabaseBackupService>();
        services.AddScoped<ISiteManagementService, SiteManagementService>();
        services.AddScoped<IWordPressContentStore, WordPressContentStore>();
        services.AddScoped<IExecutionJobStore, ExecutionJobStore>();
        services.AddScoped<IJobFailureGate, JobFailureGate>();
        services.AddScoped<IContentAuditService, ContentAuditService>();
        services.AddScoped<ISeoAuditService, SeoAuditService>();
        services.AddScoped<IBrokenLinkScanService, BrokenLinkScanService>();
        services.AddScoped<IOfflineSnapshotService, OfflineSnapshotService>();
        services.AddScoped<ICategoryPlannerService, CategoryPlannerService>();
        services.AddScoped<IInternalLinkSuggestionService, InternalLinkSuggestionService>();
        services.AddScoped<IEmailOutbox, EmailOutboxService>();
        services.AddScoped<OperationalEmailAlertService>();
        services.AddScoped<SiteSyncFailureAlertRelay>();
        services.AddScoped<ExecutionJobFailureAlertRelay>();
        services.AddHostedService<SiteSyncFailureAlertWorker>();
        services.AddHostedService<ExecutionJobFailureAlertWorker>();
        services.AddScoped<ISubscriptionPlanCatalog, SubscriptionPlanCatalog>();
        services.AddScoped<PlanEntitlementService>();
        services.AddScoped<IPlanEntitlementCatalog>(sp => sp.GetRequiredService<PlanEntitlementService>());
        services.AddScoped<IPlanEntitlementResolver>(sp => sp.GetRequiredService<PlanEntitlementService>());
        services.AddScoped<IAccountEntitlementEnforcementService, AccountEntitlementEnforcementService>();
        services.AddScoped<AccountSubscriptionService>();
        services.AddScoped<IAccountSubscriptionService, ProviderBindingAccountSubscriptionService>();
        services.AddScoped<ISubscriptionLifecyclePolicyService, SubscriptionLifecyclePolicyService>();
        services.AddHostedService<SubscriptionLifecyclePolicyWorker>();
        services.AddScoped<PayPalConfigurationService>();
        services.AddScoped<IPayPalConfigurationService>(sp => sp.GetRequiredService<PayPalConfigurationService>());
        services.AddScoped<IPayPalRuntimeConfigurationProvider>(sp => sp.GetRequiredService<PayPalConfigurationService>());
        services.AddScoped<PayPalSubscriptionCheckoutService>();
        services.AddScoped<IPayPalSubscriptionCheckoutService, PayPalBoundSubscriptionCheckoutService>();
        services.AddScoped<IPayPalWebhookInbox, PayPalWebhookInbox>();
        services.AddScoped<IPayPalWebhookIntakeService, PayPalWebhookIntakeService>();
        services.AddScoped<IPayPalSubscriptionSynchronizationService, PayPalSubscriptionSynchronizationService>();
        services.AddHostedService<PayPalSubscriptionSynchronizationWorker>();
        // Deferred until the Web implementation of IAiSuggestionProvider is registered.
        // services.AddScoped<ISuggestedChangeService, SuggestedChangeService>();
        // Deferred until the Web implementation of IWordPressPostEditorService is registered.
        // services.AddScoped<IApprovedChangeExecutionService, ApprovedChangeExecutionService>();
        services.AddScoped<IApplicationSettingsService, ApplicationSettingsService>();
        services.AddScoped<ISiteBrainService, SiteBrainService>();
        services.AddScoped<IThemeIntelligenceStore, ThemeIntelligenceStore>();
        services.AddScoped<IWordPressDeletionImpactStore, WordPressDeletionImpactStore>();
        return services;
    }

    private static string? ResolveConnectionString(IServiceProvider provider, IConfiguration configuration)
    {
        var protectedValue = configuration["Database:ProtectedConnectionString"];
        if (string.IsNullOrWhiteSpace(protectedValue))
            return configuration["Database:ConnectionString"];

        try
        {
            var protection = provider.GetRequiredService<ISecretProtectionService>();
            return protection.UnprotectAsync(protectedValue).GetAwaiter().GetResult();
        }
        catch (Exception ex)
        {
            throw new InvalidOperationException(
                "The stored database credentials could not be decrypted. Re-open the database setup page and enter the connection details again.",
                ex);
        }
    }

    private static void EnsureConnectionString(string? connectionString, string provider)
    {
        if (string.IsNullOrWhiteSpace(connectionString))
            throw new InvalidOperationException($"Database connection string is required for provider '{provider}'. Run the first-run setup wizard.");
    }
}
