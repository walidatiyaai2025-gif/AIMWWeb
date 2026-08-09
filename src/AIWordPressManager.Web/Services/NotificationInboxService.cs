using System.Globalization;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Web.Services;

public enum NotificationSeverity
{
    Information,
    Success,
    Warning,
    Error
}

public sealed class NotificationInboxService
{
    private const int DefaultRetentionDays = 90;
    private const int DefaultMaxItemsPerOwner = 1000;

    private readonly object _sync = new();
    private readonly string _connectionString;

    public NotificationInboxService(string? databasePath = null)
    {
        var path = databasePath;
        if (string.IsNullOrWhiteSpace(path))
        {
            var directory = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "AIWordPressManager",
                "Data");
            Directory.CreateDirectory(directory);
            path = Path.Combine(directory, "notifications.db");
        }
        else
        {
            var directory = Path.GetDirectoryName(path);
            if (!string.IsNullOrWhiteSpace(directory)) Directory.CreateDirectory(directory);
        }

        _connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = path,
            Mode = SqliteOpenMode.ReadWriteCreate,
            Cache = SqliteCacheMode.Shared
        }.ToString();

        Initialize();
    }

    public NotificationItem Create(
        Guid ownerUserId,
        string title,
        string message,
        NotificationSeverity severity,
        Guid? relatedId = null,
        Guid? siteId = null,
        Guid? executionJobId = null,
        string source = "System")
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user id is required.", nameof(ownerUserId));
        if (string.IsNullOrWhiteSpace(title)) throw new ArgumentException("Notification title is required.", nameof(title));
        if (string.IsNullOrWhiteSpace(message)) throw new ArgumentException("Notification message is required.", nameof(message));

        var item = new NotificationItem(
            Guid.NewGuid(),
            ownerUserId,
            siteId,
            executionJobId,
            title.Trim(),
            message.Trim(),
            severity,
            relatedId,
            false,
            null,
            null,
            DateTime.UtcNow,
            string.IsNullOrWhiteSpace(source) ? "System" : source.Trim());

        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                INSERT INTO Notifications
                    (Id, UserId, OwnerUserId, SiteId, ExecutionJobId, Title, Message, Severity,
                     RelatedId, IsRead, ReadAtUtc, DismissedAtUtc, CreatedAtUtc, Source)
                VALUES
                    ($id, $legacyUserId, $ownerUserId, $siteId, $executionJobId, $title, $message, $severity,
                     $relatedId, 0, NULL, NULL, $createdAtUtc, $source);
                """;
            command.Parameters.AddWithValue("$id", item.Id.ToString());
            command.Parameters.AddWithValue("$legacyUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$siteId", Db(siteId?.ToString()));
            command.Parameters.AddWithValue("$executionJobId", Db(executionJobId?.ToString()));
            command.Parameters.AddWithValue("$title", item.Title);
            command.Parameters.AddWithValue("$message", item.Message);
            command.Parameters.AddWithValue("$severity", severity.ToString());
            command.Parameters.AddWithValue("$relatedId", Db(relatedId?.ToString()));
            command.Parameters.AddWithValue("$createdAtUtc", item.CreatedAtUtc.ToString("O"));
            command.Parameters.AddWithValue("$source", item.Source);
            command.ExecuteNonQuery();
        }

        Prune(ownerUserId, TimeSpan.FromDays(DefaultRetentionDays), DefaultMaxItemsPerOwner);
        return item;
    }

    public IReadOnlyList<NotificationItem> Get(Guid ownerUserId, bool unreadOnly = false, int take = 100)
    {
        if (ownerUserId == Guid.Empty) return [];

        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                SELECT Id, OwnerUserId, SiteId, ExecutionJobId, Title, Message, Severity, RelatedId,
                       IsRead, ReadAtUtc, DismissedAtUtc, CreatedAtUtc, Source
                FROM Notifications
                WHERE OwnerUserId=$ownerUserId
                  AND DismissedAtUtc IS NULL
                  AND ($unreadOnly=0 OR IsRead=0)
                ORDER BY CreatedAtUtc DESC
                LIMIT $take;
                """;
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$unreadOnly", unreadOnly ? 1 : 0);
            command.Parameters.AddWithValue("$take", Math.Clamp(take, 1, 500));

            using var reader = command.ExecuteReader();
            var items = new List<NotificationItem>();
            while (reader.Read()) items.Add(Read(reader));
            return items;
        }
    }

    public bool MarkRead(Guid ownerUserId, Guid id)
    {
        if (ownerUserId == Guid.Empty || id == Guid.Empty) return false;

        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                UPDATE Notifications
                SET IsRead=1, ReadAtUtc=COALESCE(ReadAtUtc, $now)
                WHERE Id=$id AND OwnerUserId=$ownerUserId AND DismissedAtUtc IS NULL;
                """;
            command.Parameters.AddWithValue("$id", id.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$now", DateTime.UtcNow.ToString("O"));
            return command.ExecuteNonQuery() == 1;
        }
    }

    public int MarkAllRead(Guid ownerUserId)
    {
        if (ownerUserId == Guid.Empty) return 0;

        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                UPDATE Notifications
                SET IsRead=1, ReadAtUtc=COALESCE(ReadAtUtc, $now)
                WHERE OwnerUserId=$ownerUserId AND DismissedAtUtc IS NULL AND IsRead=0;
                """;
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$now", DateTime.UtcNow.ToString("O"));
            return command.ExecuteNonQuery();
        }
    }

    public bool Dismiss(Guid ownerUserId, Guid id)
    {
        if (ownerUserId == Guid.Empty || id == Guid.Empty) return false;

        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                UPDATE Notifications
                SET IsRead=1,
                    ReadAtUtc=COALESCE(ReadAtUtc, $now),
                    DismissedAtUtc=COALESCE(DismissedAtUtc, $now)
                WHERE Id=$id AND OwnerUserId=$ownerUserId AND DismissedAtUtc IS NULL;
                """;
            command.Parameters.AddWithValue("$id", id.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$now", DateTime.UtcNow.ToString("O"));
            return command.ExecuteNonQuery() == 1;
        }
    }

    public int Prune(Guid ownerUserId, TimeSpan retention, int maxItems = DefaultMaxItemsPerOwner)
    {
        if (ownerUserId == Guid.Empty) return 0;
        if (retention < TimeSpan.Zero) throw new ArgumentOutOfRangeException(nameof(retention));
        maxItems = Math.Max(1, maxItems);

        lock (_sync)
        {
            using var connection = Open();
            using var transaction = connection.BeginTransaction();
            var deleted = 0;

            using (var expired = connection.CreateCommand())
            {
                expired.Transaction = transaction;
                expired.CommandText = """
                    DELETE FROM Notifications
                    WHERE OwnerUserId=$ownerUserId
                      AND (DismissedAtUtc IS NOT NULL OR IsRead=1)
                      AND CreatedAtUtc < $cutoff;
                    """;
                expired.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
                expired.Parameters.AddWithValue("$cutoff", DateTime.UtcNow.Subtract(retention).ToString("O"));
                deleted += expired.ExecuteNonQuery();
            }

            long total;
            using (var count = connection.CreateCommand())
            {
                count.Transaction = transaction;
                count.CommandText = "SELECT COUNT(*) FROM Notifications WHERE OwnerUserId=$ownerUserId;";
                count.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
                total = (long)(count.ExecuteScalar() ?? 0L);
            }

            var excess = Math.Max(0L, total - maxItems);
            if (excess > 0)
            {
                using var cap = connection.CreateCommand();
                cap.Transaction = transaction;
                cap.CommandText = """
                    DELETE FROM Notifications
                    WHERE Id IN (
                        SELECT Id
                        FROM Notifications
                        WHERE OwnerUserId=$ownerUserId
                          AND (DismissedAtUtc IS NOT NULL OR IsRead=1)
                        ORDER BY CreatedAtUtc ASC
                        LIMIT $excess
                    );
                    """;
                cap.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
                cap.Parameters.AddWithValue("$excess", excess);
                deleted += cap.ExecuteNonQuery();
            }

            transaction.Commit();
            return deleted;
        }
    }

    private void Initialize()
    {
        lock (_sync)
        {
            using var connection = Open();
            using (var command = connection.CreateCommand())
            {
                command.CommandText = """
                    PRAGMA journal_mode=WAL;
                    CREATE TABLE IF NOT EXISTS Notifications(
                        Id TEXT PRIMARY KEY,
                        UserId TEXT NOT NULL,
                        Title TEXT NOT NULL,
                        Message TEXT NOT NULL,
                        Severity TEXT NOT NULL,
                        RelatedId TEXT NULL,
                        IsRead INTEGER NOT NULL DEFAULT 0,
                        CreatedAtUtc TEXT NOT NULL
                    );
                    """;
                command.ExecuteNonQuery();
            }

            var columns = GetColumns(connection);
            EnsureColumn(connection, columns, "OwnerUserId", "TEXT NULL");
            EnsureColumn(connection, columns, "SiteId", "TEXT NULL");
            EnsureColumn(connection, columns, "ExecutionJobId", "TEXT NULL");
            EnsureColumn(connection, columns, "ReadAtUtc", "TEXT NULL");
            EnsureColumn(connection, columns, "DismissedAtUtc", "TEXT NULL");
            EnsureColumn(connection, columns, "Source", "TEXT NOT NULL DEFAULT 'Legacy'");

            using var indexes = connection.CreateCommand();
            indexes.CommandText = """
                DROP INDEX IF EXISTS IX_Notifications_User_Read;
                CREATE INDEX IF NOT EXISTS IX_Notifications_Owner_Read_Created
                    ON Notifications(OwnerUserId, DismissedAtUtc, IsRead, CreatedAtUtc DESC);
                CREATE INDEX IF NOT EXISTS IX_Notifications_Owner_Site_Created
                    ON Notifications(OwnerUserId, SiteId, CreatedAtUtc DESC);
                CREATE INDEX IF NOT EXISTS IX_Notifications_ExecutionJob
                    ON Notifications(ExecutionJobId);
                """;
            indexes.ExecuteNonQuery();
        }
    }

    private static HashSet<string> GetColumns(SqliteConnection connection)
    {
        using var command = connection.CreateCommand();
        command.CommandText = "PRAGMA table_info(Notifications);";
        using var reader = command.ExecuteReader();
        var columns = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        while (reader.Read()) columns.Add(reader.GetString(1));
        return columns;
    }

    private static void EnsureColumn(SqliteConnection connection, ISet<string> columns, string name, string declaration)
    {
        if (columns.Contains(name)) return;
        using var command = connection.CreateCommand();
        command.CommandText = $"ALTER TABLE Notifications ADD COLUMN {name} {declaration};";
        command.ExecuteNonQuery();
        columns.Add(name);
    }

    private SqliteConnection Open()
    {
        var connection = new SqliteConnection(_connectionString);
        connection.Open();
        return connection;
    }

    private static NotificationItem Read(SqliteDataReader reader) => new(
        Guid.Parse(reader.GetString(0)),
        Guid.Parse(reader.GetString(1)),
        reader.IsDBNull(2) ? null : Guid.Parse(reader.GetString(2)),
        reader.IsDBNull(3) ? null : Guid.Parse(reader.GetString(3)),
        reader.GetString(4),
        reader.GetString(5),
        Enum.Parse<NotificationSeverity>(reader.GetString(6), true),
        reader.IsDBNull(7) ? null : Guid.Parse(reader.GetString(7)),
        reader.GetInt32(8) == 1,
        reader.IsDBNull(9) ? null : ParseDate(reader.GetString(9)),
        reader.IsDBNull(10) ? null : ParseDate(reader.GetString(10)),
        ParseDate(reader.GetString(11)),
        reader.IsDBNull(12) ? "Legacy" : reader.GetString(12));

    private static DateTime ParseDate(string value) =>
        DateTime.Parse(value, CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind).ToUniversalTime();

    private static object Db(object? value) => value ?? DBNull.Value;
}

public sealed record NotificationItem(
    Guid Id,
    Guid OwnerUserId,
    Guid? SiteId,
    Guid? ExecutionJobId,
    string Title,
    string Message,
    NotificationSeverity Severity,
    Guid? RelatedId,
    bool IsRead,
    DateTime? ReadAtUtc,
    DateTime? DismissedAtUtc,
    DateTime CreatedAtUtc,
    string Source);
