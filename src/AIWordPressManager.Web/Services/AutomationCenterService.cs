using AIWordPressManager.Application.Abstractions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Web.Services;

public sealed class AutomationCenterService
{
    private readonly object _sync = new();
    private readonly string _connectionString;

    public AutomationCenterService(IApplicationPathService paths)
    {
        var dataDirectory = paths.GetApplicationDataDirectory();
        var databasePath = Path.Combine(dataDirectory, "automation-center.db");

        // Prior versions always stored this database in the production LocalAppData
        // folder, even when the app was running in Development. Preserve those jobs
        // when the corrected environment-specific path is used for the first time.
        var legacyPath = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AIWordPressManager",
            "Data",
            "automation-center.db");

        if (!Path.GetFullPath(databasePath).Equals(Path.GetFullPath(legacyPath), StringComparison.OrdinalIgnoreCase) &&
            File.Exists(legacyPath) &&
            !File.Exists(databasePath))
        {
            try
            {
                File.Copy(legacyPath, databasePath, overwrite: false);
            }
            catch (IOException)
            {
                // Keep startup resilient if the legacy database is in use. A fresh
                // environment-specific store can still be created safely below.
            }
            catch (UnauthorizedAccessException)
            {
                // A permissions issue in the legacy location must not block startup.
            }
        }

        _connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = databasePath,
            Mode = SqliteOpenMode.ReadWriteCreate,
            Cache = SqliteCacheMode.Shared
        }.ToString();
        Initialize();
        RecoverInterruptedJobs();
    }

    public IReadOnlyList<AutomationJob> GetJobs()
    {
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = "SELECT Id,Name,SiteId,SiteName,Type,Frequency,IntervalValue,TimeOfDay,Enabled,RetryCount,LastRunUtc,NextRunUtc,LastStatus,CreatedAtUtc FROM AutomationJobs ORDER BY CreatedAtUtc DESC";
            using var reader = command.ExecuteReader();
            var jobs = new List<AutomationJob>();
            while (reader.Read()) jobs.Add(ReadJob(reader));
            return jobs;
        }
    }

    public IReadOnlyList<AutomationHistoryItem> GetHistory(int take = 50)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = "SELECT Id,JobId,JobName,StartedAtUtc,FinishedAtUtc,Status,Message FROM AutomationHistory ORDER BY StartedAtUtc DESC LIMIT $take";
            command.Parameters.AddWithValue("$take", Math.Clamp(take, 1, 500));
            using var reader = command.ExecuteReader();
            var rows = new List<AutomationHistoryItem>();
            while (reader.Read())
                rows.Add(new AutomationHistoryItem(Guid.Parse(reader.GetString(0)), Guid.Parse(reader.GetString(1)), reader.GetString(2), Parse(reader.GetString(3)), reader.IsDBNull(4) ? null : Parse(reader.GetString(4)), reader.GetString(5), reader.GetString(6)));
            return rows;
        }
    }

    public Guid Save(AutomationJobEditModel model)
    {
        if (string.IsNullOrWhiteSpace(model.Name)) throw new InvalidOperationException("اسم المهمة مطلوب.");
        if (model.SiteId == Guid.Empty) throw new InvalidOperationException("اختر الموقع.");

        var id = model.Id == Guid.Empty ? Guid.NewGuid() : model.Id;
        var now = DateTime.UtcNow;
        var nextRun = CalculateNextRun(now, model.Frequency, model.IntervalValue, model.TimeOfDay);

        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                INSERT INTO AutomationJobs (Id,Name,SiteId,SiteName,Type,Frequency,IntervalValue,TimeOfDay,Enabled,RetryCount,LastRunUtc,NextRunUtc,LastStatus,CreatedAtUtc,UpdatedAtUtc)
                VALUES ($id,$name,$siteId,$siteName,$type,$frequency,$interval,$time,$enabled,$retry,NULL,$next,'Scheduled',$now,$now)
                ON CONFLICT(Id) DO UPDATE SET Name=$name,SiteId=$siteId,SiteName=$siteName,Type=$type,Frequency=$frequency,IntervalValue=$interval,TimeOfDay=$time,Enabled=$enabled,RetryCount=$retry,NextRunUtc=$next,LastStatus='Scheduled',UpdatedAtUtc=$now;
                """;
            command.Parameters.AddWithValue("$id", id.ToString());
            command.Parameters.AddWithValue("$name", model.Name.Trim());
            command.Parameters.AddWithValue("$siteId", model.SiteId.ToString());
            command.Parameters.AddWithValue("$siteName", model.SiteName?.Trim() ?? string.Empty);
            command.Parameters.AddWithValue("$type", model.Type);
            command.Parameters.AddWithValue("$frequency", NormalizeFrequency(model.Frequency));
            command.Parameters.AddWithValue("$interval", Math.Max(1, model.IntervalValue));
            command.Parameters.AddWithValue("$time", model.TimeOfDay ?? "00:00");
            command.Parameters.AddWithValue("$enabled", model.Enabled ? 1 : 0);
            command.Parameters.AddWithValue("$retry", Math.Clamp(model.RetryCount, 0, 10));
            command.Parameters.AddWithValue("$next", Format(nextRun));
            command.Parameters.AddWithValue("$now", Format(now));
            command.ExecuteNonQuery();
        }
        return id;
    }

    public void SetEnabled(Guid id, bool enabled)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = "UPDATE AutomationJobs SET Enabled=$enabled,LastStatus=$status,UpdatedAtUtc=$now WHERE Id=$id";
            command.Parameters.AddWithValue("$enabled", enabled ? 1 : 0);
            command.Parameters.AddWithValue("$status", enabled ? "Scheduled" : "Disabled");
            command.Parameters.AddWithValue("$now", Format(DateTime.UtcNow));
            command.Parameters.AddWithValue("$id", id.ToString());
            command.ExecuteNonQuery();
        }
    }

    public void QueueNow(Guid id)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = "UPDATE AutomationJobs SET Enabled=1,NextRunUtc=$now,LastStatus='Scheduled',UpdatedAtUtc=$now WHERE Id=$id AND LastStatus <> 'Running'";
            command.Parameters.AddWithValue("$now", Format(DateTime.UtcNow));
            command.Parameters.AddWithValue("$id", id.ToString());
            if (command.ExecuteNonQuery() == 0) throw new InvalidOperationException("المهمة غير موجودة أو تعمل حاليًا.");
        }
    }

    public void Delete(Guid id)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = "DELETE FROM AutomationJobs WHERE Id=$id";
            command.Parameters.AddWithValue("$id", id.ToString());
            command.ExecuteNonQuery();
        }
    }

    public IReadOnlyList<AutomationJob> ClaimDueJobs(DateTime utcNow)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var transaction = connection.BeginTransaction();
            using var select = connection.CreateCommand();
            select.Transaction = transaction;
            select.CommandText = "SELECT Id,Name,SiteId,SiteName,Type,Frequency,IntervalValue,TimeOfDay,Enabled,RetryCount,LastRunUtc,NextRunUtc,LastStatus,CreatedAtUtc FROM AutomationJobs WHERE Enabled=1 AND NextRunUtc <= $now AND LastStatus <> 'Running' ORDER BY NextRunUtc";
            select.Parameters.AddWithValue("$now", Format(utcNow));
            var due = new List<AutomationJob>();
            using (var reader = select.ExecuteReader()) while (reader.Read()) due.Add(ReadJob(reader));

            foreach (var job in due)
            {
                using var update = connection.CreateCommand();
                update.Transaction = transaction;
                update.CommandText = "UPDATE AutomationJobs SET LastStatus='Running',LastRunUtc=$now,UpdatedAtUtc=$now WHERE Id=$id";
                update.Parameters.AddWithValue("$now", Format(utcNow));
                update.Parameters.AddWithValue("$id", job.Id.ToString());
                update.ExecuteNonQuery();
            }
            transaction.Commit();
            return due.Select(x => x with { LastRunUtc = utcNow, LastStatus = "Running" }).ToList();
        }
    }

    public void CompleteRun(AutomationJob job, string status, string message)
    {
        var normalizedStatus = status is "Completed" or "Queued" or "Failed" ? status : "Completed";
        var now = DateTime.UtcNow;
        var next = CalculateNextRun(now, job.Frequency, job.IntervalValue, job.TimeOfDay);

        lock (_sync)
        {
            using var connection = Open();
            using var transaction = connection.BeginTransaction();
            using var update = connection.CreateCommand();
            update.Transaction = transaction;
            update.CommandText = "UPDATE AutomationJobs SET LastStatus=$status,NextRunUtc=$next,UpdatedAtUtc=$now WHERE Id=$id";
            update.Parameters.AddWithValue("$status", normalizedStatus);
            update.Parameters.AddWithValue("$next", Format(next));
            update.Parameters.AddWithValue("$now", Format(now));
            update.Parameters.AddWithValue("$id", job.Id.ToString());
            update.ExecuteNonQuery();

            using var history = connection.CreateCommand();
            history.Transaction = transaction;
            history.CommandText = "INSERT INTO AutomationHistory (Id,JobId,JobName,StartedAtUtc,FinishedAtUtc,Status,Message) VALUES ($id,$jobId,$name,$start,$finish,$status,$message)";
            history.Parameters.AddWithValue("$id", Guid.NewGuid().ToString());
            history.Parameters.AddWithValue("$jobId", job.Id.ToString());
            history.Parameters.AddWithValue("$name", job.Name);
            history.Parameters.AddWithValue("$start", Format(job.LastRunUtc ?? now));
            history.Parameters.AddWithValue("$finish", Format(now));
            history.Parameters.AddWithValue("$status", normalizedStatus);
            history.Parameters.AddWithValue("$message", message);
            history.ExecuteNonQuery();
            transaction.Commit();
        }
    }

    private void Initialize()
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = """
            PRAGMA journal_mode=WAL;
            CREATE TABLE IF NOT EXISTS AutomationJobs (
                Id TEXT PRIMARY KEY, Name TEXT NOT NULL, SiteId TEXT NOT NULL, SiteName TEXT NOT NULL,
                Type TEXT NOT NULL, Frequency TEXT NOT NULL, IntervalValue INTEGER NOT NULL,
                TimeOfDay TEXT NOT NULL, Enabled INTEGER NOT NULL, RetryCount INTEGER NOT NULL,
                LastRunUtc TEXT NULL, NextRunUtc TEXT NOT NULL, LastStatus TEXT NOT NULL,
                CreatedAtUtc TEXT NOT NULL, UpdatedAtUtc TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS AutomationHistory (
                Id TEXT PRIMARY KEY, JobId TEXT NOT NULL, JobName TEXT NOT NULL, StartedAtUtc TEXT NOT NULL,
                FinishedAtUtc TEXT NULL, Status TEXT NOT NULL, Message TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS IX_AutomationJobs_NextRun ON AutomationJobs(Enabled,NextRunUtc);
            CREATE INDEX IF NOT EXISTS IX_AutomationHistory_Started ON AutomationHistory(StartedAtUtc);
            """;
        command.ExecuteNonQuery();
    }

    private void RecoverInterruptedJobs()
    {
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = "UPDATE AutomationJobs SET LastStatus='Scheduled',NextRunUtc=$now,UpdatedAtUtc=$now WHERE LastStatus='Running'";
            command.Parameters.AddWithValue("$now", Format(DateTime.UtcNow));
            command.ExecuteNonQuery();
        }
    }

    private SqliteConnection Open() { var connection = new SqliteConnection(_connectionString); connection.Open(); return connection; }
    private static AutomationJob ReadJob(SqliteDataReader r) => new(Guid.Parse(r.GetString(0)), r.GetString(1), Guid.Parse(r.GetString(2)), r.GetString(3), r.GetString(4), r.GetString(5), r.GetInt32(6), r.GetString(7), r.GetInt32(8) == 1, r.GetInt32(9), r.IsDBNull(10) ? null : Parse(r.GetString(10)), Parse(r.GetString(11)), r.GetString(12), Parse(r.GetString(13)));
    private static string NormalizeFrequency(string? value) => value?.ToLowerInvariant() is "hourly" or "weekly" or "monthly" ? value.ToLowerInvariant() : "daily";

    private static DateTime CalculateNextRun(DateTime fromUtc, string frequency, int interval, string? time)
    {
        interval = Math.Max(1, interval);
        if (frequency == "hourly") return fromUtc.AddHours(interval);
        var parts = (time ?? "00:00").Split(':');
        var hour = parts.Length > 0 && int.TryParse(parts[0], out var h) ? Math.Clamp(h, 0, 23) : 0;
        var minute = parts.Length > 1 && int.TryParse(parts[1], out var m) ? Math.Clamp(m, 0, 59) : 0;
        var candidate = new DateTime(fromUtc.Year, fromUtc.Month, fromUtc.Day, hour, minute, 0, DateTimeKind.Utc);
        if (candidate <= fromUtc)
            candidate = frequency switch { "weekly" => candidate.AddDays(7 * interval), "monthly" => candidate.AddMonths(interval), _ => candidate.AddDays(interval) };
        return candidate;
    }

    private static string Format(DateTime value) => value.ToUniversalTime().ToString("O");
    private static DateTime Parse(string value) => DateTime.Parse(value, null, System.Globalization.DateTimeStyles.RoundtripKind).ToUniversalTime();
}

public sealed class AutomationSchedulerService(
    AutomationCenterService automation,
    ExecutionCenterService execution,
    IServiceScopeFactory scopeFactory,
    IConfiguration configuration,
    ILogger<AutomationSchedulerService> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        await ProcessDueJobsAsync(stoppingToken);
        using var timer = new PeriodicTimer(TimeSpan.FromSeconds(5));
        while (await timer.WaitForNextTickAsync(stoppingToken)) await ProcessDueJobsAsync(stoppingToken);
    }

    private async Task ProcessDueJobsAsync(CancellationToken cancellationToken)
    {
        if (!configuration.GetValue<bool>("Database:SetupComplete"))
            return;

        IReadOnlyList<AutomationJob> dueJobs;
        try { dueJobs = automation.ClaimDueJobs(DateTime.UtcNow); }
        catch (Exception ex) { logger.LogError(ex, "Failed to claim due automation jobs"); return; }

        foreach (var job in dueJobs)
        {
            try
            {
                var result = await ExecuteWithRetryAsync(job, cancellationToken);
                automation.CompleteRun(job, result.Status, result.Message);
                logger.LogInformation("Automation {AutomationId} finished with {Status} for site {SiteId}", job.Id, result.Status, job.SiteId);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { return; }
            catch (Exception ex)
            {
                automation.CompleteRun(job, "Failed", ex.Message);
                logger.LogError(ex, "Automation {AutomationId} failed for site {SiteId}", job.Id, job.SiteId);
            }
        }
    }

    private async Task<AutomationExecutionResult> ExecuteWithRetryAsync(AutomationJob job, CancellationToken cancellationToken)
    {
        var maxAttempts = Math.Clamp(job.RetryCount + 1, 1, 11);
        Exception? lastError = null;

        for (var attempt = 1; attempt <= maxAttempts; attempt++)
        {
            try
            {
                if (attempt > 1)
                    logger.LogWarning("Retrying automation {AutomationId}; attempt {Attempt}/{MaxAttempts}", job.Id, attempt, maxAttempts);
                return await ExecuteJobAsync(job, cancellationToken);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { throw; }
            catch (Exception ex)
            {
                lastError = ex;
                if (attempt >= maxAttempts) break;
                await Task.Delay(TimeSpan.FromSeconds(Math.Min(30, attempt * 2)), cancellationToken);
            }
        }

        throw new InvalidOperationException($"فشلت المهمة بعد {maxAttempts} محاولة. {lastError?.Message}", lastError);
    }

    private async Task<AutomationExecutionResult> ExecuteJobAsync(AutomationJob job, CancellationToken cancellationToken)
    {
        using var scope = scopeFactory.CreateScope();

        if (string.Equals(job.Type, "Synchronization", StringComparison.OrdinalIgnoreCase))
        {
            var synchronization = scope.ServiceProvider.GetRequiredService<WordPressSyncWebService>();
            var result = await synchronization.SynchronizeAsync(job.SiteId, cancellationToken);
            return new("Completed", result.Message);
        }

        if (string.Equals(job.Type, "SEO Audit", StringComparison.OrdinalIgnoreCase))
        {
            var seoAudit = scope.ServiceProvider.GetRequiredService<SeoAuditExecutionService>();
            var result = await seoAudit.RunAsync(job.SiteId, cancellationToken);
            return new("Completed", result.Message);
        }

        execution.Enqueue(job.Name, job.Type, job.SiteName, 1);
        return new("Queued", "تمت إضافة المهمة إلى مركز التنفيذ. تنفيذ هذا النوع سيتم عبر الـWorker المختص.");
    }

    private sealed record AutomationExecutionResult(string Status, string Message);
}

public sealed record AutomationJob(Guid Id, string Name, Guid SiteId, string SiteName, string Type, string Frequency, int IntervalValue, string TimeOfDay, bool Enabled, int RetryCount, DateTime? LastRunUtc, DateTime NextRunUtc, string LastStatus, DateTime CreatedAtUtc);
public sealed record AutomationHistoryItem(Guid Id, Guid JobId, string JobName, DateTime StartedAtUtc, DateTime? FinishedAtUtc, string Status, string Message);

public sealed class AutomationJobEditModel
{
    public Guid Id { get; set; }
    public string Name { get; set; } = string.Empty;
    public Guid SiteId { get; set; }
    public string SiteName { get; set; } = string.Empty;
    public string Type { get; set; } = "Synchronization";
    public string Frequency { get; set; } = "daily";
    public int IntervalValue { get; set; } = 1;
    public string TimeOfDay { get; set; } = "08:00";
    public bool Enabled { get; set; } = true;
    public int RetryCount { get; set; } = 3;
}
