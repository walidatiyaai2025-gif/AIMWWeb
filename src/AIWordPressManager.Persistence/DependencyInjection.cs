using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence.Backups;
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
using Microsoft.Extensions.DependencyInjection;

namespace AIWordPressManager.Persistence;

public static class DependencyInjection
{
    public static IServiceCollection AddPersistence(this IServiceCollection services)
    {
        services.AddDbContext<AppDbContext>((provider, options) =>
        {
            var paths = provider.GetRequiredService<IApplicationPathService>();
            var connectionString = new SqliteConnectionStringBuilder
            {
                DataSource = paths.GetDatabasePath(),
                ForeignKeys = true,
                Pooling = true
            }.ToString();
            options.UseSqlite(connectionString);
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
}
