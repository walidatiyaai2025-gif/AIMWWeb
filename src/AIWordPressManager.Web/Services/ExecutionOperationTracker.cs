using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Web.Services;

public sealed class ExecutionOperationTracker
{
    private const string InterruptedTrackedError = "Execution was interrupted by application restart and cannot be resumed automatically.";
    private readonly ExecutionCenterService _executionCenter;
    private readonly string _connectionString;
    private readonly object _sync = new();

    public ExecutionOperationTracker(ExecutionCenterService executionCenter, string? databasePath = null)
    {
        _executionCenter = executionCenter;
        var resolvedPath = databasePath;
        if (string.IsNullOrWhiteSpace(resolvedPath))
        {
            var dataDirectory = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "AIWordPressManager",
                "Data");
            Directory.CreateDirectory(dataDirectory);
            resolvedPath = Path.Combine(dataDirectory, "execution-center.db");
        }
        else
        {
            resolvedPath = Path.GetFullPath(resolvedPath);
            var directory = Path.GetDirectoryName(resolvedPath);
            if (!string.IsNullOrWhiteSpace(directory)) Directory.CreateDirectory(directory);
        }

        _connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = resolvedPath,
            Mode = SqliteOpenMode.ReadWriteCreate,
            Cache = SqliteCacheMode.Shared
        }.ToString();
    }

    public Guid Start(string title, string type, string siteName, int totalItems) =>
        StartCore(_executionCenter.Enqueue(title, type, siteName, Math.Max(1, totalItems)));

    public Guid Start(Guid ownerUserId, Guid siteId, string title, string type, string siteName, int totalItems) =>
        StartCore(_executionCenter.Enqueue(ownerUserId, siteId, title, type, siteName, Math.Max(1, totalItems)));

    public void BindOwner(Guid jobId, Guid ownerUserId, Guid siteId)
    {
        if (jobId == Guid.Empty) throw new ArgumentException("Execution job ID is required.", nameof(jobId));
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Execution owner user ID is required.", nameof(ownerUserId));
        if (siteId == Guid.Empty) throw new ArgumentException("Execution site ID is required.", nameof(siteId));

        lock (_sync)
        {
            using var connection = new SqliteConnection(_connectionString);
            connection.Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                UPDATE ExecutionCenterJobs
                SET OwnerUserId=$ownerUserId, SiteId=$siteId
                WHERE Id=$id
                  AND (OwnerUserId IS NULL OR OwnerUserId=$ownerUserId)
                  AND (SiteId IS NULL OR SiteId=$siteId);
                """;
            command.Parameters.AddWithValue("$id", jobId.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$siteId", siteId.ToString());
            if (command.ExecuteNonQuery() != 1)
                throw new UnauthorizedAccessException("Execution job ownership could not be bound to the current user and site.");
        }
    }

    public bool TryStartTracked(Guid jobId, Guid ownerUserId, string expectedType, string message)
    {
        RequireTrackedIdentity(jobId, ownerUserId, expectedType);
        lock (_sync)
        {
            using var connection = Open();
            using var transaction = connection.BeginTransaction();
            var now = DateTime.UtcNow.ToString("O");
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = """
                UPDATE ExecutionCenterJobs
                SET Status='Running', StartedAtUtc=$now, CompletedAtUtc=NULL, Error=NULL
                WHERE Id=$id AND OwnerUserId=$ownerUserId
                  AND ExecutionMode=$trackedMode AND Type=$type AND Status='Waiting';
                """;
            command.Parameters.AddWithValue("$now", now);
            command.Parameters.AddWithValue("$id", jobId.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$trackedMode", ExecutionCenterService.TrackedExecutionMode);
            command.Parameters.AddWithValue("$type", expectedType.Trim());
            var changed = command.ExecuteNonQuery() == 1;
            if (changed) InsertActivity(connection, transaction, jobId, "Info", message, now);
            transaction.Commit();
            return changed;
        }
    }

    public void ReportTracked(
        Guid jobId,
        Guid ownerUserId,
        string expectedType,
        int processedItems,
        int totalItems,
        string message)
    {
        RequireTrackedIdentity(jobId, ownerUserId, expectedType);
        var safeTotal = Math.Max(1, totalItems);
        var safeProcessed = Math.Clamp(processedItems, 0, safeTotal);
        var progress = CalculateProgress(safeProcessed, safeTotal);
        UpdateTracked(
            jobId,
            ownerUserId,
            expectedType,
            """
                UPDATE ExecutionCenterJobs
                SET ProcessedItems=$processed, TotalItems=$total, Progress=$progress
                WHERE Id=$id AND OwnerUserId=$ownerUserId
                  AND ExecutionMode=$trackedMode AND Type=$type AND Status='Running';
                """,
            "Info",
            message,
            (command, _) =>
            {
                command.Parameters.AddWithValue("$processed", safeProcessed);
                command.Parameters.AddWithValue("$total", safeTotal);
                command.Parameters.AddWithValue("$progress", progress);
            });
    }

    public void CompleteTracked(
        Guid jobId,
        Guid ownerUserId,
        string expectedType,
        int processedItems,
        int totalItems,
        string message)
    {
        RequireTrackedIdentity(jobId, ownerUserId, expectedType);
        var safeTotal = Math.Max(1, totalItems);
        UpdateTracked(
            jobId,
            ownerUserId,
            expectedType,
            """
                UPDATE ExecutionCenterJobs
                SET Status='Completed', ProcessedItems=$processed, TotalItems=$total, Progress=100,
                    CompletedAtUtc=$now, Error=NULL
                WHERE Id=$id AND OwnerUserId=$ownerUserId
                  AND ExecutionMode=$trackedMode AND Type=$type AND Status='Running';
                """,
            "Success",
            message,
            (command, now) =>
            {
                command.Parameters.AddWithValue("$processed", Math.Clamp(processedItems, 0, safeTotal));
                command.Parameters.AddWithValue("$total", safeTotal);
                command.Parameters.AddWithValue("$now", now);
            },
            requireChange: true);
    }

    public void FailTracked(Guid jobId, Guid ownerUserId, string expectedType, string error)
    {
        RequireTrackedIdentity(jobId, ownerUserId, expectedType);
        var safeError = string.IsNullOrWhiteSpace(error) ? "Tracked execution failed." : error.Trim();
        UpdateTracked(
            jobId,
            ownerUserId,
            expectedType,
            """
                UPDATE ExecutionCenterJobs
                SET Status='Failed', CompletedAtUtc=$now, Error=$error
                WHERE Id=$id AND OwnerUserId=$ownerUserId
                  AND ExecutionMode=$trackedMode AND Type=$type AND Status='Running';
                """,
            "Error",
            safeError,
            (command, now) =>
            {
                command.Parameters.AddWithValue("$now", now);
                command.Parameters.AddWithValue("$error", safeError);
            });
    }

    public bool RetryTracked(Guid jobId, Guid ownerUserId, string expectedType)
    {
        RequireTrackedIdentity(jobId, ownerUserId, expectedType);
        lock (_sync)
        {
            using var connection = Open();
            using var transaction = connection.BeginTransaction();
            var now = DateTime.UtcNow.ToString("O");
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = """
                UPDATE ExecutionCenterJobs
                SET Status='Waiting', Progress=0, ProcessedItems=0,
                    StartedAtUtc=NULL, CompletedAtUtc=NULL, Error=NULL
                WHERE Id=$id AND OwnerUserId=$ownerUserId
                  AND ExecutionMode=$trackedMode AND Type=$type AND Status='Failed';
                """;
            command.Parameters.AddWithValue("$id", jobId.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$trackedMode", ExecutionCenterService.TrackedExecutionMode);
            command.Parameters.AddWithValue("$type", expectedType.Trim());
            var changed = command.ExecuteNonQuery() == 1;
            if (changed) InsertActivity(connection, transaction, jobId, "Info", "Tracked job queued for safe retry.", now);
            transaction.Commit();
            return changed;
        }
    }

    public int RecoverInterruptedTracked(string expectedType)
    {
        if (string.IsNullOrWhiteSpace(expectedType)) throw new ArgumentException("Tracked job type is required.", nameof(expectedType));
        lock (_sync)
        {
            using var connection = Open();
            using var transaction = connection.BeginTransaction();
            var ids = new List<Guid>();
            using (var select = connection.CreateCommand())
            {
                select.Transaction = transaction;
                select.CommandText = """
                    SELECT Id FROM ExecutionCenterJobs
                    WHERE ExecutionMode=$trackedMode AND Type=$type
                      AND Status='Failed' AND Error=$interrupted;
                    """;
                select.Parameters.AddWithValue("$trackedMode", ExecutionCenterService.TrackedExecutionMode);
                select.Parameters.AddWithValue("$type", expectedType.Trim());
                select.Parameters.AddWithValue("$interrupted", InterruptedTrackedError);
                using var reader = select.ExecuteReader();
                while (reader.Read()) ids.Add(Guid.Parse(reader.GetString(0)));
            }

            if (ids.Count == 0)
            {
                transaction.Commit();
                return 0;
            }

            using var recover = connection.CreateCommand();
            recover.Transaction = transaction;
            recover.CommandText = """
                UPDATE ExecutionCenterJobs
                SET Status='Waiting', Progress=0, ProcessedItems=0,
                    StartedAtUtc=NULL, CompletedAtUtc=NULL,
                    Error='Tracked execution was interrupted and is queued for idempotent reconciliation.'
                WHERE ExecutionMode=$trackedMode AND Type=$type
                  AND Status='Failed' AND Error=$interrupted;
                """;
            recover.Parameters.AddWithValue("$trackedMode", ExecutionCenterService.TrackedExecutionMode);
            recover.Parameters.AddWithValue("$type", expectedType.Trim());
            recover.Parameters.AddWithValue("$interrupted", InterruptedTrackedError);
            recover.ExecuteNonQuery();
            var now = DateTime.UtcNow.ToString("O");
            foreach (var id in ids)
                InsertActivity(connection, transaction, id, "Warning", "Tracked execution queued for idempotent recovery after application restart.", now);
            transaction.Commit();
            return ids.Count;
        }
    }

    public void Report(Guid jobId, int processedItems, int totalItems, string message)
    {
        var safeTotal = Math.Max(1, totalItems);
        var safeProcessed = Math.Clamp(processedItems, 0, safeTotal);
        var progress = CalculateProgress(safeProcessed, safeTotal);
        Update(jobId,
            "UPDATE ExecutionCenterJobs SET ProcessedItems=$processed, TotalItems=$total, Progress=$progress WHERE Id=$id;",
            "Info", message,
            (command, _) =>
            {
                command.Parameters.AddWithValue("$processed", safeProcessed);
                command.Parameters.AddWithValue("$total", safeTotal);
                command.Parameters.AddWithValue("$progress", progress);
            });
    }

    public void Complete(Guid jobId, int processedItems, int totalItems, string message)
    {
        var safeTotal = Math.Max(1, totalItems);
        Update(jobId,
            "UPDATE ExecutionCenterJobs SET Status='Completed', ProcessedItems=$processed, TotalItems=$total, Progress=100, CompletedAtUtc=$now, Error=NULL WHERE Id=$id;",
            "Success", message,
            (command, now) =>
            {
                command.Parameters.AddWithValue("$processed", Math.Clamp(processedItems, 0, safeTotal));
                command.Parameters.AddWithValue("$total", safeTotal);
                command.Parameters.AddWithValue("$now", now);
            });
    }

    public void CompleteWithWarnings(Guid jobId, int processedItems, int totalItems, string warning)
    {
        var safeTotal = Math.Max(1, totalItems);
        var safeProcessed = Math.Clamp(processedItems, 0, safeTotal);
        var progress = CalculateProgress(safeProcessed, safeTotal);
        Update(jobId,
            "UPDATE ExecutionCenterJobs SET Status='CompletedWithWarnings', ProcessedItems=$processed, TotalItems=$total, Progress=$progress, CompletedAtUtc=$now, Error=$warning WHERE Id=$id;",
            "Warning", warning,
            (command, now) =>
            {
                command.Parameters.AddWithValue("$processed", safeProcessed);
                command.Parameters.AddWithValue("$total", safeTotal);
                command.Parameters.AddWithValue("$progress", progress);
                command.Parameters.AddWithValue("$now", now);
                command.Parameters.AddWithValue("$warning", warning);
            });
    }

    public void NeedsReconciliation(Guid jobId, int processedItems, int totalItems, string error)
    {
        var safeTotal = Math.Max(1, totalItems);
        var safeProcessed = Math.Clamp(processedItems, 0, safeTotal);
        var progress = CalculateProgress(safeProcessed, safeTotal);
        Update(jobId,
            "UPDATE ExecutionCenterJobs SET Status='NeedsReconciliation', ProcessedItems=$processed, TotalItems=$total, Progress=$progress, CompletedAtUtc=NULL, Error=$error WHERE Id=$id;",
            "Warning", error,
            (command, _) =>
            {
                command.Parameters.AddWithValue("$processed", safeProcessed);
                command.Parameters.AddWithValue("$total", safeTotal);
                command.Parameters.AddWithValue("$progress", progress);
                command.Parameters.AddWithValue("$error", error);
            });
    }

    public void Fail(Guid jobId, string error)
    {
        Update(jobId,
            "UPDATE ExecutionCenterJobs SET Status='Failed', CompletedAtUtc=$now, Error=$error WHERE Id=$id;",
            "Error", error,
            (command, now) =>
            {
                command.Parameters.AddWithValue("$now", now);
                command.Parameters.AddWithValue("$error", error);
            });
    }

    private Guid StartCore(ExecutionJob job)
    {
        Update(job.Id,
            "UPDATE ExecutionCenterJobs SET Status='Running', StartedAtUtc=$now WHERE Id=$id;",
            "Info", "Operation started.",
            (command, now) => command.Parameters.AddWithValue("$now", now));
        return job.Id;
    }

    private static int CalculateProgress(int processedItems, int totalItems) =>
        (int)Math.Round(processedItems * 100d / Math.Max(1, totalItems));

    private void UpdateTracked(
        Guid jobId,
        Guid ownerUserId,
        string expectedType,
        string sql,
        string level,
        string activityMessage,
        Action<SqliteCommand, string> addParameters,
        bool requireChange = false)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var transaction = connection.BeginTransaction();
            var now = DateTime.UtcNow.ToString("O");
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = sql;
            command.Parameters.AddWithValue("$id", jobId.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$trackedMode", ExecutionCenterService.TrackedExecutionMode);
            command.Parameters.AddWithValue("$type", expectedType.Trim());
            addParameters(command, now);
            var changed = command.ExecuteNonQuery();
            if (requireChange && changed != 1)
                throw new InvalidOperationException("Tracked execution job is not running or no longer matches its owner/type contract.");
            if (changed > 0) InsertActivity(connection, transaction, jobId, level, activityMessage, now);
            transaction.Commit();
        }
    }

    private void Update(Guid jobId, string sql, string level, string activityMessage, Action<SqliteCommand, string> addParameters)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var transaction = connection.BeginTransaction();
            var now = DateTime.UtcNow.ToString("O");

            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = sql;
            command.Parameters.AddWithValue("$id", jobId.ToString());
            addParameters(command, now);
            command.ExecuteNonQuery();

            InsertActivity(connection, transaction, jobId, level, activityMessage, now);
            transaction.Commit();
        }
    }

    private SqliteConnection Open()
    {
        var connection = new SqliteConnection(_connectionString);
        connection.Open();
        return connection;
    }

    private static void InsertActivity(
        SqliteConnection connection,
        SqliteTransaction transaction,
        Guid jobId,
        string level,
        string message,
        string now)
    {
        using var activity = connection.CreateCommand();
        activity.Transaction = transaction;
        activity.CommandText = "INSERT INTO ExecutionCenterActivities (Id, JobId, CreatedAtUtc, Level, Message) VALUES ($id, $jobId, $created, $level, $message);";
        activity.Parameters.AddWithValue("$id", Guid.NewGuid().ToString());
        activity.Parameters.AddWithValue("$jobId", jobId.ToString());
        activity.Parameters.AddWithValue("$created", now);
        activity.Parameters.AddWithValue("$level", level);
        activity.Parameters.AddWithValue("$message", message);
        activity.ExecuteNonQuery();
    }

    private static void RequireTrackedIdentity(Guid jobId, Guid ownerUserId, string expectedType)
    {
        if (jobId == Guid.Empty) throw new ArgumentException("Execution job ID is required.", nameof(jobId));
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Execution owner user ID is required.", nameof(ownerUserId));
        if (string.IsNullOrWhiteSpace(expectedType)) throw new ArgumentException("Tracked job type is required.", nameof(expectedType));
    }
}
