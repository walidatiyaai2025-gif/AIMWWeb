using System.Data;
using System.Data.Common;
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
            if (IsSupportedNonSqliteProvider(provider))
                await EnsureNonSqliteCompatibilityAsync(provider, cancellationToken);
        }

        if (!await dbContext.Database.CanConnectAsync(cancellationToken))
            throw new InvalidOperationException($"The configured database ({provider}) could not be opened after initialization.");

        await SeedSettingAsync("Application.Language", "en", cancellationToken);
        await SeedSettingAsync("Application.Theme", "Dark", cancellationToken);
        await SeedSettingAsync("Application.PortableMode", "false", cancellationToken);
        await dbContext.SaveChangesAsync(cancellationToken);

        logger.LogInformation("Database initialization completed successfully using provider {Provider}.", provider);
    }

    private async Task EnsureNonSqliteCompatibilityAsync(string provider, CancellationToken cancellationToken)
    {
        var connection = dbContext.Database.GetDbConnection();
        var closeWhenFinished = connection.State != ConnectionState.Open;
        if (closeWhenFinished)
            await connection.OpenAsync(cancellationToken);

        try
        {
            var existingTables = ReadSchemaNames(connection, "Tables", "TABLE_NAME");
            var createScript = dbContext.Database.GenerateCreateScript();
            var commands = RelationalSchemaUpgradePlanner.SelectMissingTableCommands(createScript, existingTables);

            foreach (var command in commands)
                await dbContext.Database.ExecuteSqlRawAsync(command, cancellationToken);

            if (commands.Count > 0)
            {
                logger.LogInformation(
                    "Applied {CommandCount} provider-native schema compatibility commands using {Provider}.",
                    commands.Count,
                    provider);
            }

            await EnsureNonSqliteEmailOutboxScopeAsync(provider, connection, cancellationToken);
        }
        finally
        {
            if (closeWhenFinished && connection.State == ConnectionState.Open)
                await connection.CloseAsync();
        }
    }

    private async Task EnsureNonSqliteEmailOutboxScopeAsync(
        string provider,
        DbConnection connection,
        CancellationToken cancellationToken)
    {
        var existingTables = ReadSchemaNames(connection, "Tables", "TABLE_NAME");
        if (!existingTables.Contains("EmailOutboxMessages"))
            return;

        var columns = ReadColumnNames(connection, "EmailOutboxMessages");
        if (!columns.Contains("Scope"))
        {
            await dbContext.Database.ExecuteSqlRawAsync(GetAddOutboxScopeSql(provider), cancellationToken);
            logger.LogInformation("Added Scope to EmailOutboxMessages using provider {Provider}.", provider);
        }

        await dbContext.Database.ExecuteSqlRawAsync(GetBackfillOutboxScopeSql(provider), cancellationToken);
    }

    private static HashSet<string> ReadSchemaNames(DbConnection connection, string collectionName, string nameColumn)
    {
        var schema = connection.GetSchema(collectionName);
        var column = schema.Columns
            .Cast<DataColumn>()
            .FirstOrDefault(x => string.Equals(x.ColumnName, nameColumn, StringComparison.OrdinalIgnoreCase));

        if (column is null)
            throw new InvalidOperationException($"Provider schema collection '{collectionName}' does not expose '{nameColumn}'.");

        return schema.Rows
            .Cast<DataRow>()
            .Select(x => Convert.ToString(x[column]))
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Select(x => x!)
            .ToHashSet(StringComparer.OrdinalIgnoreCase);
    }

    private static HashSet<string> ReadColumnNames(DbConnection connection, string tableName)
    {
        var schema = connection.GetSchema("Columns");
        var tableColumn = schema.Columns
            .Cast<DataColumn>()
            .FirstOrDefault(x => string.Equals(x.ColumnName, "TABLE_NAME", StringComparison.OrdinalIgnoreCase));
        var nameColumn = schema.Columns
            .Cast<DataColumn>()
            .FirstOrDefault(x => string.Equals(x.ColumnName, "COLUMN_NAME", StringComparison.OrdinalIgnoreCase));

        if (tableColumn is null || nameColumn is null)
            throw new InvalidOperationException("Provider column schema does not expose TABLE_NAME and COLUMN_NAME.");

        return schema.Rows
            .Cast<DataRow>()
            .Where(x => string.Equals(Convert.ToString(x[tableColumn]), tableName, StringComparison.OrdinalIgnoreCase))
            .Select(x => Convert.ToString(x[nameColumn]))
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Select(x => x!)
            .ToHashSet(StringComparer.OrdinalIgnoreCase);
    }

    private static bool IsSupportedNonSqliteProvider(string provider) =>
        provider.Contains("SqlServer", StringComparison.OrdinalIgnoreCase) ||
        provider.Contains("Npgsql", StringComparison.OrdinalIgnoreCase) ||
        provider.Contains("Postgre", StringComparison.OrdinalIgnoreCase) ||
        provider.Contains("MySql", StringComparison.OrdinalIgnoreCase) ||
        provider.Contains("Maria", StringComparison.OrdinalIgnoreCase);

    private static string GetAddOutboxScopeSql(string provider)
    {
        if (provider.Contains("SqlServer", StringComparison.OrdinalIgnoreCase))
            return "ALTER TABLE [EmailOutboxMessages] ADD [Scope] nvarchar(16) NOT NULL CONSTRAINT [DF_EmailOutboxMessages_Scope] DEFAULT N'Account';";

        if (provider.Contains("Npgsql", StringComparison.OrdinalIgnoreCase) ||
            provider.Contains("Postgre", StringComparison.OrdinalIgnoreCase))
            return "ALTER TABLE \"EmailOutboxMessages\" ADD COLUMN \"Scope\" character varying(16) NOT NULL DEFAULT 'Account';";

        if (provider.Contains("MySql", StringComparison.OrdinalIgnoreCase) ||
            provider.Contains("Maria", StringComparison.OrdinalIgnoreCase))
            return "ALTER TABLE `EmailOutboxMessages` ADD COLUMN `Scope` varchar(16) NOT NULL DEFAULT 'Account';";

        throw new NotSupportedException($"No non-SQLite email schema compatibility SQL is defined for provider '{provider}'.");
    }

    private static string GetBackfillOutboxScopeSql(string provider)
    {
        if (provider.Contains("SqlServer", StringComparison.OrdinalIgnoreCase))
            return "UPDATE [EmailOutboxMessages] SET [Scope] = CASE WHEN [SiteId] IS NULL THEN 'Account' ELSE 'Site' END WHERE [Scope] IS NULL OR [Scope] = '' OR [Scope] = 'Account';";

        if (provider.Contains("Npgsql", StringComparison.OrdinalIgnoreCase) ||
            provider.Contains("Postgre", StringComparison.OrdinalIgnoreCase))
            return "UPDATE \"EmailOutboxMessages\" SET \"Scope\" = CASE WHEN \"SiteId\" IS NULL THEN 'Account' ELSE 'Site' END WHERE \"Scope\" IS NULL OR \"Scope\" = '' OR \"Scope\" = 'Account';";

        if (provider.Contains("MySql", StringComparison.OrdinalIgnoreCase) ||
            provider.Contains("Maria", StringComparison.OrdinalIgnoreCase))
            return "UPDATE `EmailOutboxMessages` SET `Scope` = CASE WHEN `SiteId` IS NULL THEN 'Account' ELSE 'Site' END WHERE `Scope` IS NULL OR `Scope` = '' OR `Scope` = 'Account';";

        throw new NotSupportedException($"No non-SQLite email schema compatibility SQL is defined for provider '{provider}'.");
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

            CREATE TABLE IF NOT EXISTS SiteMailProfiles (
                Id TEXT NOT NULL CONSTRAINT PK_SiteMailProfiles PRIMARY KEY,
                SiteId TEXT NOT NULL,
                OwnerUserId TEXT NOT NULL,
                UseAccountProfile INTEGER NOT NULL,
                Host TEXT NOT NULL,
                Port INTEGER NOT NULL,
                UserName TEXT NOT NULL,
                ProtectedPassword TEXT NULL,
                FromAddress TEXT NOT NULL,
                FromName TEXT NOT NULL,
                ReplyToAddress TEXT NOT NULL,
                EnableSsl INTEGER NOT NULL,
                IsEnabled INTEGER NOT NULL,
                CreatedAtUtc TEXT NOT NULL,
                UpdatedAtUtc TEXT NOT NULL,
                ConcurrencyToken BLOB NOT NULL,
                CONSTRAINT FK_SiteMailProfiles_Sites_SiteId FOREIGN KEY (SiteId) REFERENCES Sites (Id) ON DELETE CASCADE
            );
            CREATE UNIQUE INDEX IF NOT EXISTS IX_SiteMailProfiles_SiteId ON SiteMailProfiles (SiteId);
            CREATE INDEX IF NOT EXISTS IX_SiteMailProfiles_OwnerUserId_SiteId ON SiteMailProfiles (OwnerUserId, SiteId);

            CREATE TABLE IF NOT EXISTS AccountEmailRecipients (
                Id TEXT NOT NULL CONSTRAINT PK_AccountEmailRecipients PRIMARY KEY,
                OwnerUserId TEXT NOT NULL,
                EmailAddress TEXT NOT NULL,
                NormalizedEmailAddress TEXT NOT NULL,
                DisplayName TEXT NULL,
                IsEnabled INTEGER NOT NULL,
                CreatedAtUtc TEXT NOT NULL,
                UpdatedAtUtc TEXT NOT NULL,
                ConcurrencyToken BLOB NOT NULL,
                CONSTRAINT FK_AccountEmailRecipients_AuthUsers_OwnerUserId FOREIGN KEY (OwnerUserId) REFERENCES AuthUsers (Id) ON DELETE CASCADE
            );
            CREATE UNIQUE INDEX IF NOT EXISTS IX_AccountEmailRecipients_OwnerUserId_NormalizedEmailAddress ON AccountEmailRecipients (OwnerUserId, NormalizedEmailAddress);

            CREATE TABLE IF NOT EXISTS AccountMailProfiles (
                Id TEXT NOT NULL CONSTRAINT PK_AccountMailProfiles PRIMARY KEY,
                OwnerUserId TEXT NOT NULL,
                Host TEXT NOT NULL,
                Port INTEGER NOT NULL,
                UserName TEXT NOT NULL,
                ProtectedPassword TEXT NULL,
                FromAddress TEXT NOT NULL,
                FromName TEXT NOT NULL,
                ReplyToAddress TEXT NOT NULL,
                EnableSsl INTEGER NOT NULL,
                IsEnabled INTEGER NOT NULL,
                CreatedAtUtc TEXT NOT NULL,
                UpdatedAtUtc TEXT NOT NULL,
                ConcurrencyToken BLOB NOT NULL,
                CONSTRAINT FK_AccountMailProfiles_AuthUsers_OwnerUserId FOREIGN KEY (OwnerUserId) REFERENCES AuthUsers (Id) ON DELETE CASCADE
            );
            CREATE UNIQUE INDEX IF NOT EXISTS IX_AccountMailProfiles_OwnerUserId ON AccountMailProfiles (OwnerUserId);

            CREATE TABLE IF NOT EXISTS EmailOutboxMessages (
                Id TEXT NOT NULL CONSTRAINT PK_EmailOutboxMessages PRIMARY KEY,
                OwnerUserId TEXT NOT NULL,
                SiteId TEXT NULL,
                Scope TEXT NOT NULL,
                ScheduleId TEXT NULL,
                TemplateKey TEXT NOT NULL,
                Subject TEXT NOT NULL,
                HtmlBody TEXT NOT NULL,
                TextBody TEXT NOT NULL,
                RecipientsJson TEXT NOT NULL,
                IdempotencyKey TEXT NOT NULL,
                CorrelationId TEXT NOT NULL,
                Status TEXT NOT NULL,
                AttemptCount INTEGER NOT NULL,
                MaxAttempts INTEGER NOT NULL,
                NextAttemptAtUtc TEXT NOT NULL,
                ClaimedAtUtc TEXT NULL,
                ClaimToken TEXT NULL,
                SentAtUtc TEXT NULL,
                LastError TEXT NULL,
                CreatedAtUtc TEXT NOT NULL,
                UpdatedAtUtc TEXT NOT NULL,
                ConcurrencyToken BLOB NOT NULL,
                CONSTRAINT FK_EmailOutboxMessages_AuthUsers_OwnerUserId FOREIGN KEY (OwnerUserId) REFERENCES AuthUsers (Id) ON DELETE CASCADE,
                CONSTRAINT FK_EmailOutboxMessages_Sites_SiteId FOREIGN KEY (SiteId) REFERENCES Sites (Id) ON DELETE SET NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS IX_EmailOutboxMessages_OwnerUserId_IdempotencyKey ON EmailOutboxMessages (OwnerUserId, IdempotencyKey);
            CREATE INDEX IF NOT EXISTS IX_EmailOutboxMessages_Status_NextAttemptAtUtc ON EmailOutboxMessages (Status, NextAttemptAtUtc);
            CREATE INDEX IF NOT EXISTS IX_EmailOutboxMessages_CorrelationId ON EmailOutboxMessages (CorrelationId);
            CREATE INDEX IF NOT EXISTS IX_EmailOutboxMessages_OwnerUserId_CreatedAtUtc ON EmailOutboxMessages (OwnerUserId, CreatedAtUtc);

            CREATE TABLE IF NOT EXISTS EmailDeliveryAttempts (
                Id TEXT NOT NULL CONSTRAINT PK_EmailDeliveryAttempts PRIMARY KEY,
                OutboxMessageId TEXT NOT NULL,
                AttemptNumber INTEGER NOT NULL,
                Status TEXT NOT NULL,
                StartedAtUtc TEXT NOT NULL,
                FinishedAtUtc TEXT NULL,
                ProviderSummary TEXT NULL,
                ErrorCategory TEXT NULL,
                SanitizedError TEXT NULL,
                CreatedAtUtc TEXT NOT NULL,
                UpdatedAtUtc TEXT NOT NULL,
                ConcurrencyToken BLOB NOT NULL,
                CONSTRAINT FK_EmailDeliveryAttempts_EmailOutboxMessages_OutboxMessageId FOREIGN KEY (OutboxMessageId) REFERENCES EmailOutboxMessages (Id) ON DELETE CASCADE
            );
            CREATE UNIQUE INDEX IF NOT EXISTS IX_EmailDeliveryAttempts_OutboxMessageId_AttemptNumber ON EmailDeliveryAttempts (OutboxMessageId, AttemptNumber);
            """,
            cancellationToken);

        await EnsureSqliteSiteOwnerColumnAsync(cancellationToken);
        await EnsureSqliteEmailOutboxScopeAsync(cancellationToken);
    }

    private async Task EnsureSqliteSiteOwnerColumnAsync(CancellationToken cancellationToken)
    {
        var connection = (SqliteConnection)dbContext.Database.GetDbConnection();
        if (connection.State != ConnectionState.Open)
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
            """
            CREATE INDEX IF NOT EXISTS IX_Sites_OwnerUserId ON Sites (OwnerUserId);
            CREATE INDEX IF NOT EXISTS IX_Sites_OwnerUserId_SiteUrl ON Sites (OwnerUserId, SiteUrl);
            DROP INDEX IF EXISTS IX_Sites_SiteUrl;
            """,
            cancellationToken);
    }

    private async Task EnsureSqliteEmailOutboxScopeAsync(CancellationToken cancellationToken)
    {
        var connection = (SqliteConnection)dbContext.Database.GetDbConnection();
        if (connection.State != ConnectionState.Open)
            await connection.OpenAsync(cancellationToken);

        var tableExists = false;
        await using (var tableCommand = connection.CreateCommand())
        {
            tableCommand.CommandText = "SELECT 1 FROM sqlite_master WHERE type='table' AND name='EmailOutboxMessages' LIMIT 1;";
            tableExists = await tableCommand.ExecuteScalarAsync(cancellationToken) is not null;
        }
        if (!tableExists) return;

        var hasScope = false;
        await using (var command = connection.CreateCommand())
        {
            command.CommandText = "PRAGMA table_info('EmailOutboxMessages');";
            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            while (await reader.ReadAsync(cancellationToken))
            {
                if (string.Equals(reader.GetString(1), "Scope", StringComparison.OrdinalIgnoreCase))
                {
                    hasScope = true;
                    break;
                }
            }
        }

        if (!hasScope)
        {
            await dbContext.Database.ExecuteSqlRawAsync(
                "ALTER TABLE EmailOutboxMessages ADD COLUMN Scope TEXT NOT NULL DEFAULT 'Account';",
                cancellationToken);
            logger.LogInformation("Added Scope to EmailOutboxMessages.");
        }

        await dbContext.Database.ExecuteSqlRawAsync(
            "UPDATE EmailOutboxMessages SET Scope = CASE WHEN SiteId IS NULL THEN 'Account' ELSE 'Site' END WHERE Scope IS NULL OR Scope = '' OR Scope = 'Account';",
            cancellationToken);
    }

    private async Task SeedSettingAsync(string key, string value, CancellationToken cancellationToken)
    {
        if (!await dbContext.ApplicationSettings.IgnoreQueryFilters().AnyAsync(x => x.Key == key, cancellationToken))
            dbContext.ApplicationSettings.Add(new ApplicationSetting(key, value, clock.UtcNow));
    }
}
