using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Web.Services;

public sealed class ExecutionCenterService : IDisposable
{
    public const string SimulatedExecutionMode = "Simulated";
    public const string ExternalExecutionMode = "External";

    private readonly object _sync = new();
    private readonly string _connectionString;
    private readonly Timer? _timer;
    private bool _ticking;

    public ExecutionCenterService(
        string? databasePath = null,
        bool enableBackgroundWorker = true,
        bool enableSeedData = true)
    {
        databasePath = ResolveDatabasePath(databasePath);
        var directory = Path.GetDirectoryName(databasePath);
        if (!string.IsNullOrWhiteSpace(directory)) Directory.CreateDirectory(directory);

        _connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = databasePath,
            Mode = SqliteOpenMode.ReadWriteCreate,
            Cache = SqliteCacheMode.Shared
        }.ToString();

        InitializeDatabase();
        RecoverInterruptedJobs();
        if (enableSeedData) SeedIfEmpty();
        if (enableBackgroundWorker)
            _timer = new Timer(_ => TickSafely(), null, TimeSpan.FromSeconds(2), TimeSpan.FromSeconds(2));
    }

    public IReadOnlyList<ExecutionJob> GetJobs() => GetJobsCore(null);

    public IReadOnlyList<ExecutionJob> GetJobs(Guid ownerUserId)
    {
        RequireIdentity(ownerUserId, nameof(ownerUserId));
        return GetJobsCore(ownerUserId);
    }

    public IReadOnlyList<ExecutionActivity> GetActivities(int take = 30) => GetActivitiesCore(null, take);

    public IReadOnlyList<ExecutionActivity> GetActivities(Guid ownerUserId, int take = 30)
    {
        RequireIdentity(ownerUserId, nameof(ownerUserId));
        return GetActivitiesCore(ownerUserId, take);
    }

    public ExecutionJob Enqueue(
        string title,
        string type,
        string siteName,
        int totalItems,
        string? idempotencyKey = null,
        string? correlationId = null) =>
        EnqueueCore(null, null, title, type, siteName, totalItems, SimulatedExecutionMode, idempotencyKey, correlationId);

    public ExecutionJob Enqueue(
        Guid ownerUserId,
        Guid siteId,
        string title,
        string type,
        string siteName,
        int totalItems,
        string? idempotencyKey = null,
        string? correlationId = null)
    {
        RequireIdentity(ownerUserId, nameof(ownerUserId));
        RequireIdentity(siteId, nameof(siteId));
        return EnqueueCore(ownerUserId, siteId, title, type, siteName, totalItems, SimulatedExecutionMode, idempotencyKey, correlationId);
    }

    public ExecutionJob EnqueueExternal(
        Guid ownerUserId,
        Guid siteId,
        string title,
        string type,
        string siteName,
        string idempotencyKey,
        string correlationId)
    {
        RequireIdentity(ownerUserId, nameof(ownerUserId));
        RequireIdentity(siteId, nameof(siteId));
        if (string.IsNullOrWhiteSpace(idempotencyKey)) throw new ArgumentException("Idempotency key is required.", nameof(idempotencyKey));
        if (string.IsNullOrWhiteSpace(correlationId)) throw new ArgumentException("Correlation ID is required.", nameof(correlationId));

        lock (_sync)
        {
            using var connection = OpenConnection();
            using var existing = connection.CreateCommand();
            existing.CommandText = """
                SELECT Id, Title, Type, SiteName, Status, Progress, TotalItems, ProcessedItems,
                       CreatedAtUtc, StartedAtUtc, CompletedAtUtc, Error, OwnerUserId, SiteId,
                       ExecutionMode, IdempotencyKey, CorrelationId
                FROM ExecutionCenterJobs
                WHERE OwnerUserId=$ownerUserId
                  AND ExecutionMode=$executionMode
                  AND IdempotencyKey=$idempotencyKey
                ORDER BY CreatedAtUtc DESC
                LIMIT 1;
                """;
            existing.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            existing.Parameters.AddWithValue("$executionMode", ExternalExecutionMode);
            existing.Parameters.AddWithValue("$idempotencyKey", idempotencyKey.Trim());
            using var reader = existing.ExecuteReader();
            if (reader.Read()) return ReadJob(reader);
        }

        return EnqueueCore(
            ownerUserId,
            siteId,
            title,
            type,
            siteName,
            1,
            ExternalExecutionMode,
            idempotencyKey.Trim(),
            correlationId.Trim());
    }

    public IReadOnlyList<ExecutionJob> GetPendingExternalJobs(int take = 20)
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var command = connection.CreateCommand();
            command.CommandText = """
                SELECT Id, Title, Type, SiteName, Status, Progress, TotalItems, ProcessedItems,
                       CreatedAtUtc, StartedAtUtc, CompletedAtUtc, Error, OwnerUserId, SiteId,
                       ExecutionMode, IdempotencyKey, CorrelationId
                FROM ExecutionCenterJobs
                WHERE ExecutionMode=$executionMode AND Status='Waiting'
                  AND OwnerUserId IS NOT NULL AND SiteId IS NOT NULL
                ORDER BY CreatedAtUtc
                LIMIT $take;
                """;
            command.Parameters.AddWithValue("$executionMode", ExternalExecutionMode);
            command.Parameters.AddWithValue("$take", Math.Clamp(take, 1, 100));
            using var reader = command.ExecuteReader();
            var jobs = new List<ExecutionJob>();
            while (reader.Read()) jobs.Add(ReadJob(reader));
            return jobs;
        }
    }

    public bool TryStartExternal(Guid id, Guid ownerUserId)
    {
        RequireIdentity(ownerUserId, nameof(ownerUserId));
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = """
                UPDATE ExecutionCenterJobs
                SET Status='Running', StartedAtUtc=$started, CompletedAtUtc=NULL, Error=NULL
                WHERE Id=$id AND OwnerUserId=$ownerUserId
                  AND ExecutionMode=$executionMode AND Status='Waiting';
                """;
            command.Parameters.AddWithValue("$started", FormatDate(DateTime.UtcNow));
            command.Parameters.AddWithValue("$id", id.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$executionMode", ExternalExecutionMode);
            var changed = command.ExecuteNonQuery() == 1;
            if (changed) InsertActivity(connection, transaction, id, "Info", "Approved change execution started.");
            transaction.Commit();
            return changed;
        }
    }

    public void CompleteExternal(Guid id, Guid ownerUserId, string message)
    {
        RequireIdentity(ownerUserId, nameof(ownerUserId));
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = """
                UPDATE ExecutionCenterJobs
                SET Status='Completed', Progress=100, ProcessedItems=TotalItems,
                    CompletedAtUtc=$completed, Error=NULL
                WHERE Id=$id AND OwnerUserId=$ownerUserId
                  AND ExecutionMode=$executionMode AND Status='Running';
                """;
            command.Parameters.AddWithValue("$completed", FormatDate(DateTime.UtcNow));
            command.Parameters.AddWithValue("$id", id.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$executionMode", ExternalExecutionMode);
            if (command.ExecuteNonQuery() != 1)
                throw new InvalidOperationException("External execution job is not running or does not belong to the owner.");
            InsertActivity(connection, transaction, id, "Success", string.IsNullOrWhiteSpace(message) ? "Approved change completed." : message.Trim());
            transaction.Commit();
        }
    }

    public void FailExternal(Guid id, Guid ownerUserId, string error)
    {
        RequireIdentity(ownerUserId, nameof(ownerUserId));
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = """
                UPDATE ExecutionCenterJobs
                SET Status='Failed', CompletedAtUtc=$completed, Error=$error
                WHERE Id=$id AND OwnerUserId=$ownerUserId
                  AND ExecutionMode=$executionMode AND Status='Running';
                """;
            command.Parameters.AddWithValue("$completed", FormatDate(DateTime.UtcNow));
            command.Parameters.AddWithValue("$error", string.IsNullOrWhiteSpace(error) ? "Approved change execution failed." : error.Trim());
            command.Parameters.AddWithValue("$id", id.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            command.Parameters.AddWithValue("$executionMode", ExternalExecutionMode);
            if (command.ExecuteNonQuery() > 0)
                InsertActivity(connection, transaction, id, "Error", string.IsNullOrWhiteSpace(error) ? "Approved change execution failed." : error.Trim());
            transaction.Commit();
        }
    }

    public void Cancel(Guid id) => ChangeStatus(id, null, new[] { "Waiting", "Running", "Paused" }, "Cancelled", "Warning", "Job cancelled by user.", true);
    public void Pause(Guid id) => ChangeStatus(id, null, new[] { "Running" }, "Paused", "Warning", "Job paused.", false);
    public void Resume(Guid id) => ChangeStatus(id, null, new[] { "Paused" }, "Running", "Info", "Job resumed.", false);
    public void Retry(Guid id) => RetryCore(id, null);

    public void Cancel(Guid id, Guid ownerUserId) => ChangeStatus(id, ownerUserId, new[] { "Waiting", "Running", "Paused" }, "Cancelled", "Warning", "Job cancelled by user.", true);
    public void Pause(Guid id, Guid ownerUserId) => ChangeStatus(id, ownerUserId, new[] { "Running" }, "Paused", "Warning", "Job paused.", false);
    public void Resume(Guid id, Guid ownerUserId) => ChangeStatus(id, ownerUserId, new[] { "Paused" }, "Running", "Info", "Job resumed.", false);
    public void Retry(Guid id, Guid ownerUserId)
    {
        RequireIdentity(ownerUserId, nameof(ownerUserId));
        RetryCore(id, ownerUserId);
    }

    private IReadOnlyList<ExecutionJob> GetJobsCore(Guid? ownerUserId)
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var command = connection.CreateCommand();
            command.CommandText = """
                SELECT Id, Title, Type, SiteName, Status, Progress, TotalItems, ProcessedItems,
                       CreatedAtUtc, StartedAtUtc, CompletedAtUtc, Error, OwnerUserId, SiteId,
                       ExecutionMode, IdempotencyKey, CorrelationId
                FROM ExecutionCenterJobs
                WHERE ($ownerUserId IS NULL OR OwnerUserId=$ownerUserId)
                ORDER BY CreatedAtUtc DESC;
                """;
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.HasValue ? ownerUserId.Value.ToString() : DBNull.Value);
            using var reader = command.ExecuteReader();
            var jobs = new List<ExecutionJob>();
            while (reader.Read()) jobs.Add(ReadJob(reader));
            return jobs;
        }
    }

    private IReadOnlyList<ExecutionActivity> GetActivitiesCore(Guid? ownerUserId, int take)
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var command = connection.CreateCommand();
            command.CommandText = """
                SELECT a.Id, a.JobId, a.CreatedAtUtc, a.Level, a.Message
                FROM ExecutionCenterActivities a
                INNER JOIN ExecutionCenterJobs j ON j.Id=a.JobId
                WHERE ($ownerUserId IS NULL OR j.OwnerUserId=$ownerUserId)
                ORDER BY a.CreatedAtUtc DESC
                LIMIT $take;
                """;
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.HasValue ? ownerUserId.Value.ToString() : DBNull.Value);
            command.Parameters.AddWithValue("$take", Math.Clamp(take, 1, 1000));
            using var reader = command.ExecuteReader();
            var result = new List<ExecutionActivity>();
            while (reader.Read())
            {
                result.Add(new ExecutionActivity(
                    Guid.Parse(reader.GetString(0)),
                    Guid.Parse(reader.GetString(1)),
                    ParseDate(reader.GetString(2)),
                    reader.GetString(3),
                    reader.GetString(4)));
            }
            return result;
        }
    }

    private ExecutionJob EnqueueCore(
        Guid? ownerUserId,
        Guid? siteId,
        string title,
        string type,
        string siteName,
        int totalItems,
        string executionMode,
        string? idempotencyKey,
        string? correlationId)
    {
        if (string.IsNullOrWhiteSpace(title)) throw new ArgumentException("Job title is required.", nameof(title));
        if (string.IsNullOrWhiteSpace(type)) throw new ArgumentException("Job type is required.", nameof(type));
        var now = DateTime.UtcNow;
        var job = new ExecutionJob(
            Guid.NewGuid(),
            title.Trim(),
            type.Trim(),
            siteName?.Trim() ?? string.Empty,
            "Waiting",
            0,
            Math.Max(1, totalItems),
            0,
            now,
            null,
            null,
            null,
            ownerUserId,
            siteId,
            executionMode,
            string.IsNullOrWhiteSpace(idempotencyKey) ? null : idempotencyKey.Trim(),
            string.IsNullOrWhiteSpace(correlationId) ? null : correlationId.Trim());

        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            InsertJob(connection, transaction, job);
            InsertActivity(connection, transaction, job.Id, "Info", $"Queued {job.Type} for {job.SiteName}.");
            transaction.Commit();
        }
        return job;
    }

    private void RetryCore(Guid id, Guid? ownerUserId)
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = """
                UPDATE ExecutionCenterJobs
                SET Status='Waiting', Progress=0, ProcessedItems=0,
                    StartedAtUtc=NULL, CompletedAtUtc=NULL, Error=NULL
                WHERE Id=$id AND Status IN ('Failed','Cancelled')
                  AND ($ownerUserId IS NULL OR OwnerUserId=$ownerUserId);
                """;
            command.Parameters.AddWithValue("$id", id.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.HasValue ? ownerUserId.Value.ToString() : DBNull.Value);
            if (command.ExecuteNonQuery() > 0)
                InsertActivity(connection, transaction, id, "Info", "Job queued for retry.");
            transaction.Commit();
        }
    }

    private void TickSafely()
    {
        if (_ticking) return;
        try
        {
            _ticking = true;
            Tick();
        }
        catch
        {
            // The execution-center simulator must never terminate the process.
        }
        finally
        {
            _ticking = false;
        }
    }

    private void Tick()
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            var running = GetFirstSimulatedJob(connection, transaction, "Running");
            if (running is null)
            {
                var waiting = GetFirstSimulatedJob(connection, transaction, "Waiting");
                if (waiting is null) { transaction.Commit(); return; }

                using var start = connection.CreateCommand();
                start.Transaction = transaction;
                start.CommandText = "UPDATE ExecutionCenterJobs SET Status='Running', StartedAtUtc=$started WHERE Id=$id AND ExecutionMode=$mode;";
                start.Parameters.AddWithValue("$started", FormatDate(DateTime.UtcNow));
                start.Parameters.AddWithValue("$id", waiting.Id.ToString());
                start.Parameters.AddWithValue("$mode", SimulatedExecutionMode);
                start.ExecuteNonQuery();
                InsertActivity(connection, transaction, waiting.Id, "Info", "Job started.");
                transaction.Commit();
                return;
            }

            var increment = Math.Max(1, running.TotalItems / 20);
            var processed = Math.Min(running.TotalItems, running.ProcessedItems + increment);
            var progress = Math.Min(100, (int)Math.Round(processed * 100d / Math.Max(1, running.TotalItems)));
            var completed = processed >= running.TotalItems;

            using var update = connection.CreateCommand();
            update.Transaction = transaction;
            update.CommandText = completed
                ? "UPDATE ExecutionCenterJobs SET ProcessedItems=$processed, Progress=100, Status='Completed', CompletedAtUtc=$completed WHERE Id=$id AND ExecutionMode=$mode;"
                : "UPDATE ExecutionCenterJobs SET ProcessedItems=$processed, Progress=$progress WHERE Id=$id AND ExecutionMode=$mode;";
            update.Parameters.AddWithValue("$processed", processed);
            update.Parameters.AddWithValue("$progress", progress);
            update.Parameters.AddWithValue("$completed", FormatDate(DateTime.UtcNow));
            update.Parameters.AddWithValue("$id", running.Id.ToString());
            update.Parameters.AddWithValue("$mode", SimulatedExecutionMode);
            update.ExecuteNonQuery();
            if (completed) InsertActivity(connection, transaction, running.Id, "Success", "Job completed successfully.");
            transaction.Commit();
        }
    }

    private void InitializeDatabase()
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using (var command = connection.CreateCommand())
            {
                command.CommandText = """
                    PRAGMA journal_mode=WAL;
                    PRAGMA foreign_keys=ON;
                    CREATE TABLE IF NOT EXISTS ExecutionCenterJobs (
                        Id TEXT PRIMARY KEY,
                        Title TEXT NOT NULL,
                        Type TEXT NOT NULL,
                        SiteName TEXT NOT NULL,
                        Status TEXT NOT NULL,
                        Progress INTEGER NOT NULL DEFAULT 0,
                        TotalItems INTEGER NOT NULL,
                        ProcessedItems INTEGER NOT NULL DEFAULT 0,
                        CreatedAtUtc TEXT NOT NULL,
                        StartedAtUtc TEXT NULL,
                        CompletedAtUtc TEXT NULL,
                        Error TEXT NULL,
                        OwnerUserId TEXT NULL,
                        SiteId TEXT NULL,
                        ExecutionMode TEXT NOT NULL DEFAULT 'Simulated',
                        IdempotencyKey TEXT NULL,
                        CorrelationId TEXT NULL
                    );
                    CREATE TABLE IF NOT EXISTS ExecutionCenterActivities (
                        Id TEXT PRIMARY KEY,
                        JobId TEXT NOT NULL,
                        CreatedAtUtc TEXT NOT NULL,
                        Level TEXT NOT NULL,
                        Message TEXT NOT NULL,
                        FOREIGN KEY (JobId) REFERENCES ExecutionCenterJobs(Id) ON DELETE CASCADE
                    );
                    """;
                command.ExecuteNonQuery();
            }

            EnsureColumn(connection, "ExecutionCenterJobs", "OwnerUserId", "TEXT NULL");
            EnsureColumn(connection, "ExecutionCenterJobs", "SiteId", "TEXT NULL");
            EnsureColumn(connection, "ExecutionCenterJobs", "ExecutionMode", "TEXT NOT NULL DEFAULT 'Simulated'");
            EnsureColumn(connection, "ExecutionCenterJobs", "IdempotencyKey", "TEXT NULL");
            EnsureColumn(connection, "ExecutionCenterJobs", "CorrelationId", "TEXT NULL");

            using var normalize = connection.CreateCommand();
            normalize.CommandText = "UPDATE ExecutionCenterJobs SET ExecutionMode='Simulated' WHERE ExecutionMode IS NULL OR ExecutionMode='';";
            normalize.ExecuteNonQuery();

            using var indexes = connection.CreateCommand();
            indexes.CommandText = """
                CREATE INDEX IF NOT EXISTS IX_ExecutionCenterJobs_Status_CreatedAtUtc
                    ON ExecutionCenterJobs(Status, CreatedAtUtc);
                CREATE INDEX IF NOT EXISTS IX_ExecutionCenterJobs_Owner_CreatedAtUtc
                    ON ExecutionCenterJobs(OwnerUserId, CreatedAtUtc);
                CREATE INDEX IF NOT EXISTS IX_ExecutionCenterJobs_Owner_Site_CreatedAtUtc
                    ON ExecutionCenterJobs(OwnerUserId, SiteId, CreatedAtUtc);
                CREATE INDEX IF NOT EXISTS IX_ExecutionCenterJobs_Mode_Status_CreatedAtUtc
                    ON ExecutionCenterJobs(ExecutionMode, Status, CreatedAtUtc);
                CREATE INDEX IF NOT EXISTS IX_ExecutionCenterJobs_Owner_Idempotency
                    ON ExecutionCenterJobs(OwnerUserId, IdempotencyKey);
                CREATE INDEX IF NOT EXISTS IX_ExecutionCenterActivities_JobId_CreatedAtUtc
                    ON ExecutionCenterActivities(JobId, CreatedAtUtc);
                """;
            indexes.ExecuteNonQuery();
        }
    }

    private static void EnsureColumn(SqliteConnection connection, string table, string column, string definition)
    {
        using var info = connection.CreateCommand();
        info.CommandText = $"PRAGMA table_info({table});";
        using var reader = info.ExecuteReader();
        var exists = false;
        while (reader.Read())
        {
            if (string.Equals(reader.GetString(1), column, StringComparison.OrdinalIgnoreCase))
            {
                exists = true;
                break;
            }
        }
        reader.Close();
        if (exists) return;

        using var alter = connection.CreateCommand();
        alter.CommandText = $"ALTER TABLE {table} ADD COLUMN {column} {definition};";
        alter.ExecuteNonQuery();
    }

    private void RecoverInterruptedJobs()
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            using var select = connection.CreateCommand();
            select.Transaction = transaction;
            select.CommandText = "SELECT Id FROM ExecutionCenterJobs WHERE Status='Running';";
            var ids = new List<Guid>();
            using (var reader = select.ExecuteReader()) while (reader.Read()) ids.Add(Guid.Parse(reader.GetString(0)));

            using var update = connection.CreateCommand();
            update.Transaction = transaction;
            update.CommandText = "UPDATE ExecutionCenterJobs SET Status='Waiting', StartedAtUtc=NULL WHERE Status='Running';";
            update.ExecuteNonQuery();
            foreach (var id in ids) InsertActivity(connection, transaction, id, "Warning", "Job recovered after application restart.");
            transaction.Commit();
        }
    }

    private void SeedIfEmpty()
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var count = connection.CreateCommand();
            count.CommandText = "SELECT COUNT(*) FROM ExecutionCenterJobs;";
            if (Convert.ToInt32(count.ExecuteScalar()) > 0) return;
        }

        Enqueue("Publish selected posts", "Bulk Publish", "WALKA Store", 48);
        Enqueue("Synchronize WordPress content", "Synchronization", "Corporate Site", 220);
    }

    private void ChangeStatus(Guid id, Guid? ownerUserId, IReadOnlyCollection<string> allowedStatuses, string newStatus, string level, string message, bool complete)
    {
        if (ownerUserId.HasValue) RequireIdentity(ownerUserId.Value, nameof(ownerUserId));
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            var parameters = allowedStatuses.Select((_, index) => $"$status{index}").ToArray();
            command.CommandText = $"UPDATE ExecutionCenterJobs SET Status=$newStatus{(complete ? ", CompletedAtUtc=$completed" : string.Empty)} WHERE Id=$id AND Status IN ({string.Join(',', parameters)}) AND ($ownerUserId IS NULL OR OwnerUserId=$ownerUserId);";
            command.Parameters.AddWithValue("$newStatus", newStatus);
            command.Parameters.AddWithValue("$id", id.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.HasValue ? ownerUserId.Value.ToString() : DBNull.Value);
            if (complete) command.Parameters.AddWithValue("$completed", FormatDate(DateTime.UtcNow));
            for (var index = 0; index < allowedStatuses.Count; index++) command.Parameters.AddWithValue(parameters[index], allowedStatuses.ElementAt(index));
            if (command.ExecuteNonQuery() > 0) InsertActivity(connection, transaction, id, level, message);
            transaction.Commit();
        }
    }

    private ExecutionJob? GetFirstSimulatedJob(SqliteConnection connection, SqliteTransaction transaction, string status)
    {
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
            SELECT Id, Title, Type, SiteName, Status, Progress, TotalItems, ProcessedItems,
                   CreatedAtUtc, StartedAtUtc, CompletedAtUtc, Error, OwnerUserId, SiteId,
                   ExecutionMode, IdempotencyKey, CorrelationId
            FROM ExecutionCenterJobs
            WHERE Status=$status AND ExecutionMode=$executionMode
            ORDER BY CreatedAtUtc
            LIMIT 1;
            """;
        command.Parameters.AddWithValue("$status", status);
        command.Parameters.AddWithValue("$executionMode", SimulatedExecutionMode);
        using var reader = command.ExecuteReader();
        return reader.Read() ? ReadJob(reader) : null;
    }

    private static ExecutionJob ReadJob(SqliteDataReader reader) => new(
        Guid.Parse(reader.GetString(0)),
        reader.GetString(1),
        reader.GetString(2),
        reader.GetString(3),
        reader.GetString(4),
        reader.GetInt32(5),
        reader.GetInt32(6),
        reader.GetInt32(7),
        ParseDate(reader.GetString(8)),
        reader.IsDBNull(9) ? null : ParseDate(reader.GetString(9)),
        reader.IsDBNull(10) ? null : ParseDate(reader.GetString(10)),
        reader.IsDBNull(11) ? null : reader.GetString(11),
        ReadNullableGuid(reader, 12),
        ReadNullableGuid(reader, 13),
        reader.IsDBNull(14) ? SimulatedExecutionMode : reader.GetString(14),
        reader.IsDBNull(15) ? null : reader.GetString(15),
        reader.IsDBNull(16) ? null : reader.GetString(16));

    private static Guid? ReadNullableGuid(SqliteDataReader reader, int ordinal) =>
        reader.IsDBNull(ordinal) || !Guid.TryParse(reader.GetString(ordinal), out var value) ? null : value;

    private static void InsertJob(SqliteConnection connection, SqliteTransaction transaction, ExecutionJob job)
    {
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
            INSERT INTO ExecutionCenterJobs
            (Id, Title, Type, SiteName, Status, Progress, TotalItems, ProcessedItems, CreatedAtUtc,
             StartedAtUtc, CompletedAtUtc, Error, OwnerUserId, SiteId, ExecutionMode, IdempotencyKey, CorrelationId)
            VALUES
            ($id, $title, $type, $siteName, $status, $progress, $total, $processed, $created,
             $started, $completed, $error, $ownerUserId, $siteId, $executionMode, $idempotencyKey, $correlationId);
            """;
        command.Parameters.AddWithValue("$id", job.Id.ToString());
        command.Parameters.AddWithValue("$title", job.Title);
        command.Parameters.AddWithValue("$type", job.Type);
        command.Parameters.AddWithValue("$siteName", job.SiteName);
        command.Parameters.AddWithValue("$status", job.Status);
        command.Parameters.AddWithValue("$progress", job.Progress);
        command.Parameters.AddWithValue("$total", job.TotalItems);
        command.Parameters.AddWithValue("$processed", job.ProcessedItems);
        command.Parameters.AddWithValue("$created", FormatDate(job.CreatedAtUtc));
        command.Parameters.AddWithValue("$started", job.StartedAtUtc is null ? DBNull.Value : FormatDate(job.StartedAtUtc.Value));
        command.Parameters.AddWithValue("$completed", job.CompletedAtUtc is null ? DBNull.Value : FormatDate(job.CompletedAtUtc.Value));
        command.Parameters.AddWithValue("$error", job.Error is null ? DBNull.Value : job.Error);
        command.Parameters.AddWithValue("$ownerUserId", job.OwnerUserId.HasValue ? job.OwnerUserId.Value.ToString() : DBNull.Value);
        command.Parameters.AddWithValue("$siteId", job.SiteId.HasValue ? job.SiteId.Value.ToString() : DBNull.Value);
        command.Parameters.AddWithValue("$executionMode", job.ExecutionMode);
        command.Parameters.AddWithValue("$idempotencyKey", job.IdempotencyKey is null ? DBNull.Value : job.IdempotencyKey);
        command.Parameters.AddWithValue("$correlationId", job.CorrelationId is null ? DBNull.Value : job.CorrelationId);
        command.ExecuteNonQuery();
    }

    private static void InsertActivity(SqliteConnection connection, SqliteTransaction transaction, Guid jobId, string level, string message)
    {
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = "INSERT INTO ExecutionCenterActivities (Id, JobId, CreatedAtUtc, Level, Message) VALUES ($id, $jobId, $created, $level, $message);";
        command.Parameters.AddWithValue("$id", Guid.NewGuid().ToString());
        command.Parameters.AddWithValue("$jobId", jobId.ToString());
        command.Parameters.AddWithValue("$created", FormatDate(DateTime.UtcNow));
        command.Parameters.AddWithValue("$level", level);
        command.Parameters.AddWithValue("$message", message);
        command.ExecuteNonQuery();
    }

    private SqliteConnection OpenConnection()
    {
        var connection = new SqliteConnection(_connectionString);
        connection.Open();
        return connection;
    }

    private static void RequireIdentity(Guid value, string parameterName)
    {
        if (value == Guid.Empty) throw new ArgumentException("A non-empty identity is required.", parameterName);
    }

    private static string ResolveDatabasePath(string? databasePath)
    {
        if (!string.IsNullOrWhiteSpace(databasePath)) return Path.GetFullPath(databasePath);
        return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager", "Data", "execution-center.db");
    }

    private static string FormatDate(DateTime value) => value.ToUniversalTime().ToString("O");
    private static DateTime ParseDate(string value) => DateTime.Parse(value, null, System.Globalization.DateTimeStyles.RoundtripKind).ToUniversalTime();
    public void Dispose() => _timer?.Dispose();
}

public sealed record ExecutionJob(
    Guid Id,
    string Title,
    string Type,
    string SiteName,
    string Status,
    int Progress,
    int TotalItems,
    int ProcessedItems,
    DateTime CreatedAtUtc,
    DateTime? StartedAtUtc,
    DateTime? CompletedAtUtc,
    string? Error,
    Guid? OwnerUserId = null,
    Guid? SiteId = null,
    string ExecutionMode = ExecutionCenterService.SimulatedExecutionMode,
    string? IdempotencyKey = null,
    string? CorrelationId = null);

public sealed record ExecutionActivity(Guid Id, Guid JobId, DateTime CreatedAtUtc, string Level, string Message);
