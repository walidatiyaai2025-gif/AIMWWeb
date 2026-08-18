using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Web.Services;

public sealed class ExecutionOperationTracker
{
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

    public void Report(Guid jobId, int processedItems, int totalItems, string message)
    {
        var safeTotal = Math.Max(1, totalItems);
        var safeProcessed = Math.Clamp(processedItems, 0, safeTotal);
        var progress = (int)Math.Round(safeProcessed * 100d / safeTotal);
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

    private void Update(Guid jobId, string sql, string level, string activityMessage, Action<SqliteCommand, string> addParameters)
    {
        lock (_sync)
        {
            using var connection = new SqliteConnection(_connectionString);
            connection.Open();
            using var transaction = connection.BeginTransaction();
            var now = DateTime.UtcNow.ToString("O");

            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = sql;
            command.Parameters.AddWithValue("$id", jobId.ToString());
            addParameters(command, now);
            command.ExecuteNonQuery();

            using var activity = connection.CreateCommand();
            activity.Transaction = transaction;
            activity.CommandText = "INSERT INTO ExecutionCenterActivities (Id, JobId, CreatedAtUtc, Level, Message) VALUES ($id, $jobId, $created, $level, $message);";
            activity.Parameters.AddWithValue("$id", Guid.NewGuid().ToString());
            activity.Parameters.AddWithValue("$jobId", jobId.ToString());
            activity.Parameters.AddWithValue("$created", now);
            activity.Parameters.AddWithValue("$level", level);
            activity.Parameters.AddWithValue("$message", activityMessage);
            activity.ExecuteNonQuery();
            transaction.Commit();
        }
    }
}