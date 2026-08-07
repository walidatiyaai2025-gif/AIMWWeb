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
        var provider = dbContext.Database.ProviderName ?? "unknown";
        logger.LogInformation("Starting database initialization using provider {Provider}.", provider);

        if (provider.Contains("Sqlite", StringComparison.OrdinalIgnoreCase))
        {
            await dbContext.Database.MigrateAsync(cancellationToken);
            await EnsureSqliteCompatibilityAsync(cancellationToken);
        }
        else
        {
            await dbContext.Database.EnsureCreatedAsync(cancellationToken);
        }

        if (!await dbContext.Database.CanConnectAsync(cancellationToken))
            throw new InvalidOperationException($"The configured database ({provider}) could not be opened after initialization.");

        await SeedSettingAsync("Application.Language", "en", cancellationToken);
        await SeedSettingAsync("Application.Theme", "Dark", cancellationToken);
        await SeedSettingAsync("Application.PortableMode", "false", cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);

        logger.LogInformation("Database initialization completed successfully using provider {Provider}.", provider);
    }

    private async Task EnsureSqliteCompatibilityAsync(CancellationToken cancellationToken)
    {
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

            CREATE TABLE IF NOT EXISTS LoginAudits (
                Id TEXT NOT NULL CONSTRAINT PK_LoginAudits PRIMARY KEY,
                UserName TEXT NOT NULL,
                Succeeded INTEGER NOT NULL,
                Reason TEXT NOT NULL,
                IpAddress TEXT NOT NULL,
                UserAgent TEXT NOT NULL,
                AttemptedAtUtc TEXT NOT NULL,
                CreatedAtUtc TEXT NOT NULL,
                UpdatedAtUtc TEXT NOT NULL,
                ConcurrencyToken BLOB NOT NULL
            );
            CREATE INDEX IF NOT EXISTS IX_LoginAudits_AttemptedAtUtc ON LoginAudits (AttemptedAtUtc);
            CREATE INDEX IF NOT EXISTS IX_LoginAudits_UserName_AttemptedAtUtc ON LoginAudits (UserName, AttemptedAtUtc);

            CREATE TABLE IF NOT EXISTS SiteEmailRecipients (
                Id TEXT NOT NULL CONSTRAINT PK_SiteEmailRecipients PRIMARY KEY,
                SiteId TEXT NOT NULL,
                OwnerUserId TEXT NOT NULL,
                EmailAddress TEXT NOT NULL,
                NormalizedEmailAddress TEXT NOT NULL,
                DisplayName TEXT NULL,
                IsEnabled INTEGER NOT NULL,
                CreatedAtUtc TEXT NOT NULL,
                UpdatedAtUtc TEXT NOT NULL,
                ConcurrencyToken BLOB NOT NULL,
                CONSTRAINT FK_SiteEmailRecipients_Sites_SiteId FOREIGN KEY (SiteId) REFERENCES Sites (Id) ON DELETE CASCADE
            );
            CREATE UNIQUE INDEX IF NOT EXISTS IX_SiteEmailRecipients_SiteId_NormalizedEmailAddress ON SiteEmailRecipients (SiteId, NormalizedEmailAddress);
            CREATE INDEX IF NOT EXISTS IX_SiteEmailRecipients_OwnerUserId_SiteId ON SiteEmailRecipients (OwnerUserId, SiteId);
            """,
            cancellationToken);

        await EnsureSqliteSiteOwnerColumnAsync(cancellationToken);
    }

    private async Task EnsureSqliteSiteOwnerColumnAsync(CancellationToken cancellationToken)
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

    private async Task SeedSettingAsync(string key, string value, CancellationToken cancellationToken)
    {
        if (!await dbContext.ApplicationSettings.IgnoreQueryFilters().AnyAsync(x => x.Key == key, cancellationToken))
            dbContext.ApplicationSettings.Add(new ApplicationSetting(key, value, clock.UtcNow));
    }
}
