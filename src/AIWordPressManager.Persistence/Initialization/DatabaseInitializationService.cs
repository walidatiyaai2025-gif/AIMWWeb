using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Domain.Entities;
using Microsoft.Data.Sqlite;
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
        await dbContext.Database.MigrateAsync(cancellationToken);

        await dbContext.Database.ExecuteSqlRawAsync(
            """
            CREATE TABLE IF NOT EXISTS AuthUsers (
                Id TEXT NOT NULL CONSTRAINT PK_AuthUsers PRIMARY KEY,
                UserName TEXT NOT NULL,
                NormalizedUserName TEXT NOT NULL,
                PasswordHash TEXT NOT NULL,
                Role TEXT NOT NULL,
                IsActive INTEGER NOT NULL,
                FailedAccessCount INTEGER NOT NULL,
                LockedUntilUtc TEXT NULL,
                LastLoginAtUtc TEXT NULL,
                LastPage TEXT NOT NULL,
                CreatedAtUtc TEXT NOT NULL,
                UpdatedAtUtc TEXT NOT NULL,
                ConcurrencyToken BLOB NOT NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS IX_AuthUsers_NormalizedUserName ON AuthUsers (NormalizedUserName);
            """,
            cancellationToken);

        await EnsureSiteOwnerColumnAsync(cancellationToken);

        if (!await dbContext.Database.CanConnectAsync(cancellationToken))
            throw new InvalidOperationException("The SQLite database could not be opened after migration.");

        await SeedSettingAsync("Application.Language", "en", cancellationToken);
        await SeedSettingAsync("Application.Theme", "Dark", cancellationToken);
        await SeedSettingAsync("Application.PortableMode", "false", cancellationToken);
        await SeedDefaultSiteAsync(cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);

        logger.LogInformation("Database initialization completed successfully.");
    }

    private async Task EnsureSiteOwnerColumnAsync(CancellationToken cancellationToken)
    {
        var connection = (SqliteConnection)dbContext.Database.GetDbConnection();
        if (connection.State != System.Data.ConnectionState.Open)
            await connection.OpenAsync(cancellationToken);

        var hasColumn = false;
        await using (var command = connection.CreateCommand())
        {
            command.CommandText = "PRAGMA table_info('Sites');";
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            while (await reader.ReadAsync(cancellationToken))
            {
                if (string.Equals(reader.GetString(1), "OwnerUserId", StringComparison.OrdinalIgnoreCase))
                {
                    hasColumn = true;
                    break;
                }
            }
        }

        if (!hasColumn)
        {
            await dbContext.Database.ExecuteSqlRawAsync("ALTER TABLE Sites ADD COLUMN OwnerUserId TEXT NULL;", cancellationToken);
            logger.LogInformation("Added OwnerUserId to Sites.");
        }

        var adminId = await dbContext.AuthUsers
            .Where(x => x.NormalizedUserName == "ADMIN")
            .Select(x => (Guid?)x.Id)
            .FirstOrDefaultAsync(cancellationToken);

        if (adminId.HasValue)
        {
            await dbContext.Database.ExecuteSqlInterpolatedAsync(
                $"UPDATE Sites SET OwnerUserId = {adminId.Value.ToString()} WHERE OwnerUserId IS NULL OR OwnerUserId = '';",
                cancellationToken);
        }

        await dbContext.Database.ExecuteSqlRawAsync(
            "CREATE INDEX IF NOT EXISTS IX_Sites_OwnerUserId ON Sites (OwnerUserId);",
            cancellationToken);
    }

    private async Task SeedDefaultSiteAsync(CancellationToken cancellationToken)
    {
        const string normalizedUrl = "https://notonlybook.com";
        if (await dbContext.Sites.IgnoreQueryFilters().AnyAsync(x => x.SiteUrl == normalizedUrl, cancellationToken)) return;
        var site = new Site("NOB", new Uri(normalizedUrl), clock.UtcNow);
        dbContext.Sites.Add(site);
    }

    private async Task SeedSettingAsync(string key, string value, CancellationToken cancellationToken)
    {
        if (!await dbContext.ApplicationSettings.IgnoreQueryFilters().AnyAsync(x => x.Key == key, cancellationToken))
            dbContext.ApplicationSettings.Add(new ApplicationSetting(key, value, clock.UtcNow));
    }
}
