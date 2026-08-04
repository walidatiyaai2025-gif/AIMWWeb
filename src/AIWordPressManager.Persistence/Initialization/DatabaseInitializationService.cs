using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Initialization;

public sealed class DatabaseInitializationService(
    AppDbContext dbContext,
    IClock clock,
    ILogger<DatabaseInitializationService> logger) : IDatabaseInitializationService
{
    public async Task InitializeAsync(CancellationToken cancellationToken = default)
    {
        logger.LogInformation("Starting database initialization.");

        var pendingBeforeMigration = (await dbContext.Database
            .GetPendingMigrationsAsync(cancellationToken))
            .ToArray();

        logger.LogInformation(
            "Found {PendingMigrationCount} pending database migration(s).",
            pendingBeforeMigration.Length);

        await dbContext.Database.MigrateAsync(cancellationToken);

        // Defensive compatibility for databases created by older application builds.
        // EF migrations remain the source of truth, while this idempotent statement
        // prevents the SEO history screen from failing when an old database missed
        // the snapshot migration.
        await dbContext.Database.ExecuteSqlRawAsync(
            """
            CREATE TABLE IF NOT EXISTS SeoAuditSnapshots (
                Id TEXT NOT NULL CONSTRAINT PK_SeoAuditSnapshots PRIMARY KEY,
                SiteId TEXT NOT NULL,
                Score INTEGER NOT NULL,
                AuditedItems INTEGER NOT NULL,
                HighIssues INTEGER NOT NULL,
                MediumIssues INTEGER NOT NULL,
                LowIssues INTEGER NOT NULL,
                CapturedAtUtc TEXT NOT NULL,
                CreatedAtUtc TEXT NOT NULL,
                UpdatedAtUtc TEXT NOT NULL,
                ConcurrencyToken BLOB NOT NULL,
                CONSTRAINT FK_SeoAuditSnapshots_Sites_SiteId
                    FOREIGN KEY (SiteId) REFERENCES Sites (Id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS IX_SeoAuditSnapshots_SiteId_CapturedAtUtc
                ON SeoAuditSnapshots (SiteId, CapturedAtUtc);
            """,
            cancellationToken);

        var pendingAfterMigration = (await dbContext.Database
            .GetPendingMigrationsAsync(cancellationToken))
            .ToArray();

        if (pendingAfterMigration.Length > 0)
        {
            throw new InvalidOperationException(
                $"Database migration did not complete. Pending migrations: {string.Join(", ", pendingAfterMigration)}");
        }

        if (!await dbContext.Database.CanConnectAsync(cancellationToken))
        {
            throw new InvalidOperationException("The SQLite database could not be opened after migration.");
        }

        // Run seed data only after migrations are confirmed as applied.
        await SeedSettingAsync("Application.Language", "en", cancellationToken);
        await SeedSettingAsync("Application.Theme", "Dark", cancellationToken);
        await SeedSettingAsync("Application.PortableMode", "false", cancellationToken);
        await SeedDefaultSiteAsync(cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);

        logger.LogInformation("Database initialization completed successfully.");
    }

    private async Task SeedDefaultSiteAsync(CancellationToken cancellationToken)
    {
        const string normalizedUrl = "https://notonlybook.com";

        var exists = await dbContext.Sites
            .IgnoreQueryFilters()
            .AnyAsync(x => x.SiteUrl == normalizedUrl, cancellationToken);

        if (exists)
        {
            return;
        }

        var site = new Site("NOB", new Uri(normalizedUrl), clock.UtcNow);
        dbContext.Sites.Add(site);
        logger.LogInformation("Seeded the default site profile {SiteName} at {SiteUrl} without credentials.", site.Name, site.SiteUrl);
    }

    private async Task SeedSettingAsync(
        string key,
        string value,
        CancellationToken cancellationToken)
    {
        var exists = await dbContext.ApplicationSettings
            .IgnoreQueryFilters()
            .AnyAsync(x => x.Key == key, cancellationToken);

        if (!exists)
        {
            dbContext.ApplicationSettings.Add(new ApplicationSetting(key, value, clock.UtcNow));
        }
    }
}
