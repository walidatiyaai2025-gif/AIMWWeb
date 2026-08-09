using System.Security.Claims;
using System.Text.Json;
using AIWordPressManager.Persistence;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public enum ApprovalRiskLevel
{
    Low,
    Medium,
    High,
    Critical
}

public enum ApprovalStatus
{
    Pending,
    Approved,
    Rejected,
    Executed,
    Cancelled
}

public sealed class ApprovalWorkflowService
{
    private readonly object _sync = new();
    private readonly string _connectionString;
    private readonly ExecutionCenterService _executionCenter;
    private readonly NotificationInboxService? _notifications;
    private readonly IServiceScopeFactory? _scopeFactory;
    private readonly IHttpContextAccessor? _httpContextAccessor;
    private readonly Func<Guid, Guid?>? _siteOwnerResolver;

    // Isolated-test and explicit database-path constructor. Runtime DI uses the constructor below.
    public ApprovalWorkflowService(
        ExecutionCenterService executionCenter,
        string? databasePath = null,
        Func<Guid, Guid?>? siteOwnerResolver = null)
        : this(executionCenter, null, null, null, siteOwnerResolver, databasePath)
    {
    }

    // Runtime DI constructor. HTTP identity is used only to identify the caller; site ownership
    // continues to come from the authoritative application database.
    public ApprovalWorkflowService(
        ExecutionCenterService executionCenter,
        NotificationInboxService notifications,
        IServiceScopeFactory scopeFactory,
        IHttpContextAccessor httpContextAccessor)
        : this(executionCenter, notifications, scopeFactory, httpContextAccessor, null, null)
    {
    }

    private ApprovalWorkflowService(
        ExecutionCenterService executionCenter,
        NotificationInboxService? notifications,
        IServiceScopeFactory? scopeFactory,
        IHttpContextAccessor? httpContextAccessor,
        Func<Guid, Guid?>? siteOwnerResolver,
        string? databasePath)
    {
        _executionCenter = executionCenter;
        _notifications = notifications;
        _scopeFactory = scopeFactory;
        _httpContextAccessor = httpContextAccessor;
        _siteOwnerResolver = siteOwnerResolver;

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
    }

    // Compatibility for existing HTTP endpoints. The owner is never accepted from the request.
    public IReadOnlyList<ApprovalItem> GetItems(ApprovalStatus? status = null, int take = 200) =>
        GetItems(RequireRuntimeOwnerUserId(), status, take);

    public IReadOnlyList<ApprovalItem> GetItems(Guid ownerUserId, ApprovalStatus? status = null, int take = 200)
    {
        RequireOwner(ownerUserId);
        var limit = Math.Clamp(take, 1, 1000);
        List<ApprovalItem> owned;
        List<ApprovalItem> legacy;

        lock (_sync)
        {
            using var connection = OpenConnection();
            owned = QueryItems(
                connection,
                "OwnerUserId=$ownerUserId AND ($status IS NULL OR Status=$status)",
                limit,
                command =>
                {
                    command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
                    command.Parameters.AddWithValue("$status", status?.ToString() ?? (object)DBNull.Value);
                });

            // Legacy approval rows had no owner. Preserve them, but never guess ownership. They are
            // visible only while their SiteId currently resolves to this owner.
            legacy = QueryItems(
                connection,
                "OwnerUserId IS NULL AND ($status IS NULL OR Status=$status)",
                1000,
                command => command.Parameters.AddWithValue("$status", status?.ToString() ?? (object)DBNull.Value));
        }

        return owned
            .Concat(legacy.Where(x => IsLegacyVisibleTo(ownerUserId, x)))
            .OrderByDescending(x => x.RequestedAtUtc)
            .Take(limit)
            .ToArray();
    }

    public IReadOnlyList<ApprovalAuditEntry> GetAudit(Guid approvalId, int take = 200) =>
        GetAudit(RequireRuntimeOwnerUserId(), approvalId, take);

    public IReadOnlyList<ApprovalAuditEntry> GetAudit(Guid ownerUserId, Guid approvalId, int take = 200)
    {
        if (GetById(ownerUserId, approvalId) is null) return [];

        lock (_sync)
        {
            using var connection = OpenConnection();
            using var command = connection.CreateCommand();
            command.CommandText = """
                SELECT Id, ApprovalId, Action, Actor, Notes, CreatedAtUtc
                FROM ApprovalAudit
                WHERE ApprovalId = $approvalId
                ORDER BY CreatedAtUtc DESC
                LIMIT $take;
                """;
            command.Parameters.AddWithValue("$approvalId", approvalId.ToString());
            command.Parameters.AddWithValue("$take", Math.Clamp(take, 1, 1000));

            using var reader = command.ExecuteReader();
            var result = new List<ApprovalAuditEntry>();
            while (reader.Read())
            {
                result.Add(new ApprovalAuditEntry(
                    Guid.Parse(reader.GetString(0)),
                    Guid.Parse(reader.GetString(1)),
                    reader.GetString(2),
                    reader.GetString(3),
                    reader.IsDBNull(4) ? null : reader.GetString(4),
                    ParseDate(reader.GetString(5))));
            }
            return result;
        }
    }

    // Compatibility for producers and the existing HTTP endpoint. Request-provided RequestedBy
    // is not authoritative when an authenticated/background identity exists.
    public ApprovalItem Submit(ApprovalSubmission request)
    {
        ArgumentNullException.ThrowIfNull(request);
        var runtimeOwner = TryResolveRuntimeOwnerUserId(out var callerOwner) ? callerOwner : (Guid?)null;
        var ownerUserId = ResolveSubmissionOwner(request.SiteId, runtimeOwner);
        var actor = ResolveRuntimeActor(ownerUserId);
        return Submit(ownerUserId, request, actor);
    }

    public ApprovalItem Submit(Guid ownerUserId, ApprovalSubmission request, string? actor = null)
    {
        RequireOwner(ownerUserId);
        ArgumentNullException.ThrowIfNull(request);
        if (string.IsNullOrWhiteSpace(request.OperationType))
            throw new InvalidOperationException("Operation type is required.");
        if (string.IsNullOrWhiteSpace(request.Title))
            throw new InvalidOperationException("Approval title is required.");

        if (request.SiteId.HasValue && _scopeFactory is not null || request.SiteId.HasValue && _siteOwnerResolver is not null)
        {
            if (ResolveCurrentSiteOwner(request.SiteId) != ownerUserId)
                throw new InvalidOperationException("Selected site is unavailable.");
        }

        var beforeJson = NormalizeJson(request.Before);
        var afterJson = NormalizeJson(request.After);
        var logicalIdempotencyKey = string.IsNullOrWhiteSpace(request.IdempotencyKey)
            ? BuildIdempotencyKey(request.SiteId, request.OperationType, afterJson)
            : request.IdempotencyKey.Trim();
        var idempotencyKey = ScopeIdempotencyKey(ownerUserId, logicalIdempotencyKey);
        var requestedBy = NormalizeActor(actor ?? request.RequestedBy ?? ownerUserId.ToString());

        ApprovalItem item;
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();

            using var existing = connection.CreateCommand();
            existing.Transaction = transaction;
            existing.CommandText = "SELECT Id FROM ApprovalItems WHERE OwnerUserId=$ownerUserId AND IdempotencyKey=$key AND Status IN ('Pending','Approved','Executed') LIMIT 1;";
            existing.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            existing.Parameters.AddWithValue("$key", idempotencyKey);
            var existingId = existing.ExecuteScalar() as string;
            if (!string.IsNullOrWhiteSpace(existingId))
            {
                transaction.Rollback();
                return GetByIdInternal(Guid.Parse(existingId))!;
            }

            item = new ApprovalItem(
                Guid.NewGuid(),
                ownerUserId,
                request.SiteId,
                request.SiteName?.Trim() ?? string.Empty,
                request.OperationType.Trim(),
                request.Title.Trim(),
                ResolveRisk(request.OperationType, request.RiskLevel),
                ApprovalStatus.Pending,
                beforeJson,
                afterJson,
                requestedBy,
                DateTime.UtcNow,
                null,
                null,
                null,
                null,
                string.IsNullOrWhiteSpace(request.CorrelationId) ? Guid.NewGuid().ToString("N") : request.CorrelationId.Trim(),
                idempotencyKey);

            InsertItem(connection, transaction, item);
            InsertAudit(connection, transaction, item.Id, "Submitted", item.RequestedBy, "Submitted for approval.");
            transaction.Commit();
        }

        NotifyOwner(item, ownerUserId, "Approval required", item.Title, NotificationSeverity.Warning, null);
        return item;
    }

    public ApprovalItem Approve(Guid id, string reviewer, string? notes, bool executeImmediately)
    {
        var ownerUserId = RequireRuntimeOwnerUserId();
        return Approve(ownerUserId, id, ResolveRuntimeActor(ownerUserId), notes, executeImmediately);
    }

    public ApprovalItem Approve(Guid ownerUserId, Guid id, string reviewer, string? notes, bool executeImmediately)
    {
        RequireOwner(ownerUserId);
        var pending = GetRequiredOwnedItem(ownerUserId, id);
        EnsureSiteStillOwned(ownerUserId, pending);
        if (executeImmediately && !pending.SiteId.HasValue)
            throw new InvalidOperationException("Site-scoped execution is required for immediate approval execution.");

        var item = Review(ownerUserId, id, ApprovalStatus.Approved, reviewer, notes, "Approved");
        if (!executeImmediately) return item;

        var siteId = item.SiteId!.Value;
        var job = _executionCenter.Enqueue(
            ownerUserId,
            siteId,
            item.Title,
            item.OperationType,
            item.SiteName,
            1,
            item.IdempotencyKey,
            item.CorrelationId);

        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            using var update = connection.CreateCommand();
            update.Transaction = transaction;
            update.CommandText = """
                UPDATE ApprovalItems
                SET Status='Executed', ExecutionJobId=$jobId
                WHERE Id=$id AND Status='Approved'
                  AND (OwnerUserId=$ownerUserId OR OwnerUserId IS NULL);
                """;
            update.Parameters.AddWithValue("$jobId", job.Id.ToString());
            update.Parameters.AddWithValue("$id", id.ToString());
            update.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            if (update.ExecuteNonQuery() != 1)
                throw new InvalidOperationException("Approval item could not be queued for execution.");
            InsertAudit(connection, transaction, id, "QueuedForExecution", reviewer, $"Execution job: {job.Id}");
            transaction.Commit();
        }

        var executed = GetById(ownerUserId, id)!;
        NotifyOwner(executed, ownerUserId, "Approval queued for execution", executed.Title, NotificationSeverity.Information, job.Id);
        return executed;
    }

    public ApprovalItem Reject(Guid id, string reviewer, string? notes)
    {
        var ownerUserId = RequireRuntimeOwnerUserId();
        return Reject(ownerUserId, id, ResolveRuntimeActor(ownerUserId), notes);
    }

    public ApprovalItem Reject(Guid ownerUserId, Guid id, string reviewer, string? notes) =>
        Review(ownerUserId, id, ApprovalStatus.Rejected, reviewer, notes, "Rejected");

    public ApprovalItem UpdateProposal(Guid id, object? updatedAfter, string actor, string? notes)
    {
        var ownerUserId = RequireRuntimeOwnerUserId();
        return UpdateProposal(ownerUserId, id, updatedAfter, ResolveRuntimeActor(ownerUserId), notes);
    }

    public ApprovalItem UpdateProposal(Guid ownerUserId, Guid id, object? updatedAfter, string actor, string? notes)
    {
        RequireOwner(ownerUserId);
        var item = GetRequiredOwnedItem(ownerUserId, id);
        EnsureSiteStillOwned(ownerUserId, item);
        var afterJson = NormalizeJson(updatedAfter);

        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = """
                UPDATE ApprovalItems
                SET AfterJson=$afterJson
                WHERE Id=$id AND Status='Pending'
                  AND (OwnerUserId=$ownerUserId OR OwnerUserId IS NULL);
                """;
            command.Parameters.AddWithValue("$afterJson", afterJson);
            command.Parameters.AddWithValue("$id", id.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            if (command.ExecuteNonQuery() != 1)
                throw new InvalidOperationException("Only pending approval items can be edited.");
            InsertAudit(connection, transaction, id, "Edited", NormalizeActor(actor), notes);
            transaction.Commit();
        }
        return GetById(ownerUserId, id)!;
    }

    public ApprovalItem? GetById(Guid id) => GetById(RequireRuntimeOwnerUserId(), id);

    public ApprovalItem? GetById(Guid ownerUserId, Guid id)
    {
        RequireOwner(ownerUserId);
        var item = GetByIdInternal(id);
        if (item is null) return null;
        if (item.OwnerUserId == ownerUserId) return item;
        return item.OwnerUserId is null && IsLegacyVisibleTo(ownerUserId, item) ? item : null;
    }

    private ApprovalItem Review(Guid ownerUserId, Guid id, ApprovalStatus status, string reviewer, string? notes, string action)
    {
        RequireOwner(ownerUserId);
        var item = GetRequiredOwnedItem(ownerUserId, id);
        if (status == ApprovalStatus.Approved) EnsureSiteStillOwned(ownerUserId, item);
        var actor = NormalizeActor(reviewer);

        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = """
                UPDATE ApprovalItems
                SET Status=$status, ReviewedBy=$reviewer, ReviewedAtUtc=$reviewedAt, ReviewerNotes=$notes
                WHERE Id=$id AND Status='Pending'
                  AND (OwnerUserId=$ownerUserId OR OwnerUserId IS NULL);
                """;
            command.Parameters.AddWithValue("$status", status.ToString());
            command.Parameters.AddWithValue("$reviewer", actor);
            command.Parameters.AddWithValue("$reviewedAt", FormatDate(DateTime.UtcNow));
            command.Parameters.AddWithValue("$notes", string.IsNullOrWhiteSpace(notes) ? DBNull.Value : notes.Trim());
            command.Parameters.AddWithValue("$id", id.ToString());
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString());
            if (command.ExecuteNonQuery() != 1)
                throw new InvalidOperationException("Approval item is not pending or does not exist.");
            InsertAudit(connection, transaction, id, action, actor, notes);
            transaction.Commit();
        }

        var reviewed = GetById(ownerUserId, id)!;
        var severity = status == ApprovalStatus.Rejected ? NotificationSeverity.Error : NotificationSeverity.Success;
        NotifyOwner(
            reviewed,
            ownerUserId,
            status == ApprovalStatus.Rejected ? "Approval rejected" : "Approval approved",
            string.IsNullOrWhiteSpace(notes) ? reviewed.Title : $"{reviewed.Title} — {notes.Trim()}",
            severity,
            reviewed.ExecutionJobId);
        return reviewed;
    }

    private ApprovalItem GetRequiredOwnedItem(Guid ownerUserId, Guid id) =>
        GetById(ownerUserId, id) ?? throw new InvalidOperationException("Approval item is not pending or does not exist.");

    private ApprovalItem? GetByIdInternal(Guid id)
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var command = connection.CreateCommand();
            command.CommandText = $"{SelectColumns} FROM ApprovalItems WHERE Id=$id LIMIT 1;";
            command.Parameters.AddWithValue("$id", id.ToString());
            using var reader = command.ExecuteReader();
            return reader.Read() ? ReadItem(reader) : null;
        }
    }

    private static List<ApprovalItem> QueryItems(
        SqliteConnection connection,
        string where,
        int take,
        Action<SqliteCommand> addParameters)
    {
        using var command = connection.CreateCommand();
        command.CommandText = $"{SelectColumns} FROM ApprovalItems WHERE {where} ORDER BY RequestedAtUtc DESC LIMIT $take;";
        command.Parameters.AddWithValue("$take", Math.Clamp(take, 1, 1000));
        addParameters(command);
        using var reader = command.ExecuteReader();
        var result = new List<ApprovalItem>();
        while (reader.Read()) result.Add(ReadItem(reader));
        return result;
    }

    private bool IsLegacyVisibleTo(Guid ownerUserId, ApprovalItem item) =>
        item.OwnerUserId is null &&
        item.SiteId.HasValue &&
        ResolveCurrentSiteOwner(item.SiteId) == ownerUserId;

    private void EnsureSiteStillOwned(Guid ownerUserId, ApprovalItem item)
    {
        if (!item.SiteId.HasValue) return;
        if ((_scopeFactory is not null || _siteOwnerResolver is not null) && ResolveCurrentSiteOwner(item.SiteId) != ownerUserId)
            throw new InvalidOperationException("Approval item is not pending or does not exist.");
    }

    private Guid ResolveSubmissionOwner(Guid? siteId, Guid? runtimeOwner)
    {
        if (siteId.HasValue)
        {
            var siteOwner = ResolveCurrentSiteOwner(siteId);
            if (!siteOwner.HasValue || siteOwner.Value == Guid.Empty)
            {
                if (_scopeFactory is null && _siteOwnerResolver is null && runtimeOwner.HasValue)
                    return runtimeOwner.Value;
                throw new InvalidOperationException("Selected site is unavailable.");
            }

            if (runtimeOwner.HasValue && runtimeOwner.Value != siteOwner.Value)
                throw new InvalidOperationException("Selected site is unavailable.");
            return siteOwner.Value;
        }

        return runtimeOwner ?? throw new InvalidOperationException("An authenticated owner is required for this approval.");
    }

    private Guid? ResolveCurrentSiteOwner(Guid? siteId)
    {
        if (!siteId.HasValue || siteId.Value == Guid.Empty) return null;
        if (_siteOwnerResolver is not null) return _siteOwnerResolver(siteId.Value);
        if (_scopeFactory is null) return null;

        try
        {
            using var scope = _scopeFactory.CreateScope();
            var dbContext = scope.ServiceProvider.GetRequiredService<AppDbContext>();
            return dbContext.Sites
                .AsNoTracking()
                .Where(x => x.Id == siteId.Value)
                .Select(x => x.OwnerUserId)
                .SingleOrDefault();
        }
        catch
        {
            return null;
        }
    }

    private void NotifyOwner(
        ApprovalItem item,
        Guid ownerUserId,
        string title,
        string message,
        NotificationSeverity severity,
        Guid? executionJobId)
    {
        if (_notifications is null || ownerUserId == Guid.Empty) return;

        try
        {
            _notifications.Create(
                ownerUserId,
                title,
                message,
                severity,
                relatedId: item.Id,
                siteId: item.SiteId,
                executionJobId: executionJobId,
                source: "ApprovalWorkflow");
        }
        catch
        {
            // Approval state is authoritative. Notification persistence is best-effort and must
            // never roll back a completed review or execution decision.
        }
    }

    private bool TryResolveRuntimeOwnerUserId(out Guid ownerUserId)
    {
        var value = _httpContextAccessor?.HttpContext?.User.FindFirstValue(ClaimTypes.NameIdentifier);
        if (Guid.TryParse(value, out ownerUserId)) return true;
        return BackgroundExecutionIdentity.TryGetOwnerUserId(out ownerUserId);
    }

    private Guid RequireRuntimeOwnerUserId()
    {
        if (TryResolveRuntimeOwnerUserId(out var ownerUserId)) return ownerUserId;
        throw new UnauthorizedAccessException("Authenticated user identity is unavailable.");
    }

    private string ResolveRuntimeActor(Guid ownerUserId)
    {
        var name = _httpContextAccessor?.HttpContext?.User.Identity?.Name;
        if (!string.IsNullOrWhiteSpace(name)) return name.Trim();
        return BackgroundExecutionIdentity.TryGetOwnerUserId(out _) ? "Background worker" : ownerUserId.ToString();
    }

    private static void RequireOwner(Guid ownerUserId)
    {
        if (ownerUserId == Guid.Empty) throw new UnauthorizedAccessException("A valid owner identity is required.");
    }

    private static ApprovalRiskLevel ResolveRisk(string operationType, ApprovalRiskLevel? requested)
    {
        if (requested.HasValue) return requested.Value;
        var value = operationType.ToLowerInvariant();
        if (value.Contains("delete") || value.Contains("trash") || value.Contains("publish") || value.Contains("user"))
            return ApprovalRiskLevel.Critical;
        if (value.Contains("bulk") || value.Contains("seo") || value.Contains("ai") || value.Contains("media"))
            return ApprovalRiskLevel.High;
        if (value.Contains("update") || value.Contains("edit") || value.Contains("sync"))
            return ApprovalRiskLevel.Medium;
        return ApprovalRiskLevel.Low;
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

                    CREATE TABLE IF NOT EXISTS ApprovalItems (
                        Id TEXT PRIMARY KEY,
                        OwnerUserId TEXT NULL,
                        SiteId TEXT NULL,
                        SiteName TEXT NOT NULL,
                        OperationType TEXT NOT NULL,
                        Title TEXT NOT NULL,
                        RiskLevel TEXT NOT NULL,
                        Status TEXT NOT NULL,
                        BeforeJson TEXT NOT NULL,
                        AfterJson TEXT NOT NULL,
                        RequestedBy TEXT NOT NULL,
                        RequestedAtUtc TEXT NOT NULL,
                        ReviewedBy TEXT NULL,
                        ReviewedAtUtc TEXT NULL,
                        ReviewerNotes TEXT NULL,
                        ExecutionJobId TEXT NULL,
                        CorrelationId TEXT NOT NULL,
                        IdempotencyKey TEXT NOT NULL UNIQUE
                    );

                    CREATE TABLE IF NOT EXISTS ApprovalAudit (
                        Id TEXT PRIMARY KEY,
                        ApprovalId TEXT NOT NULL,
                        Action TEXT NOT NULL,
                        Actor TEXT NOT NULL,
                        Notes TEXT NULL,
                        CreatedAtUtc TEXT NOT NULL,
                        FOREIGN KEY (ApprovalId) REFERENCES ApprovalItems(Id) ON DELETE CASCADE
                    );
                    """;
                command.ExecuteNonQuery();
            }

            var columns = GetColumns(connection, "ApprovalItems");
            EnsureColumn(connection, columns, "OwnerUserId", "TEXT NULL");

            using var indexes = connection.CreateCommand();
            indexes.CommandText = """
                CREATE INDEX IF NOT EXISTS IX_ApprovalItems_Status_RequestedAtUtc
                    ON ApprovalItems(Status, RequestedAtUtc);
                CREATE INDEX IF NOT EXISTS IX_ApprovalItems_Owner_Status_RequestedAtUtc
                    ON ApprovalItems(OwnerUserId, Status, RequestedAtUtc DESC);
                CREATE INDEX IF NOT EXISTS IX_ApprovalItems_Owner_Site_RequestedAtUtc
                    ON ApprovalItems(OwnerUserId, SiteId, RequestedAtUtc DESC);
                CREATE INDEX IF NOT EXISTS IX_ApprovalAudit_ApprovalId_CreatedAtUtc
                    ON ApprovalAudit(ApprovalId, CreatedAtUtc);
                """;
            indexes.ExecuteNonQuery();
        }
    }

    private static HashSet<string> GetColumns(SqliteConnection connection, string table)
    {
        using var command = connection.CreateCommand();
        command.CommandText = $"PRAGMA table_info({table});";
        using var reader = command.ExecuteReader();
        var columns = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        while (reader.Read()) columns.Add(reader.GetString(1));
        return columns;
    }

    private static void EnsureColumn(SqliteConnection connection, ISet<string> columns, string name, string declaration)
    {
        if (columns.Contains(name)) return;
        using var command = connection.CreateCommand();
        command.CommandText = $"ALTER TABLE ApprovalItems ADD COLUMN {name} {declaration};";
        command.ExecuteNonQuery();
        columns.Add(name);
    }

    private static void InsertItem(SqliteConnection connection, SqliteTransaction transaction, ApprovalItem item)
    {
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
            INSERT INTO ApprovalItems
            (Id, OwnerUserId, SiteId, SiteName, OperationType, Title, RiskLevel, Status, BeforeJson, AfterJson,
             RequestedBy, RequestedAtUtc, ReviewedBy, ReviewedAtUtc, ReviewerNotes, ExecutionJobId,
             CorrelationId, IdempotencyKey)
            VALUES
            ($id, $ownerUserId, $siteId, $siteName, $operationType, $title, $riskLevel, $status, $beforeJson, $afterJson,
             $requestedBy, $requestedAtUtc, NULL, NULL, NULL, NULL, $correlationId, $idempotencyKey);
            """;
        command.Parameters.AddWithValue("$id", item.Id.ToString());
        command.Parameters.AddWithValue("$ownerUserId", item.OwnerUserId?.ToString() ?? (object)DBNull.Value);
        command.Parameters.AddWithValue("$siteId", item.SiteId?.ToString() ?? (object)DBNull.Value);
        command.Parameters.AddWithValue("$siteName", item.SiteName);
        command.Parameters.AddWithValue("$operationType", item.OperationType);
        command.Parameters.AddWithValue("$title", item.Title);
        command.Parameters.AddWithValue("$riskLevel", item.RiskLevel.ToString());
        command.Parameters.AddWithValue("$status", item.Status.ToString());
        command.Parameters.AddWithValue("$beforeJson", item.BeforeJson);
        command.Parameters.AddWithValue("$afterJson", item.AfterJson);
        command.Parameters.AddWithValue("$requestedBy", item.RequestedBy);
        command.Parameters.AddWithValue("$requestedAtUtc", FormatDate(item.RequestedAtUtc));
        command.Parameters.AddWithValue("$correlationId", item.CorrelationId);
        command.Parameters.AddWithValue("$idempotencyKey", item.IdempotencyKey);
        command.ExecuteNonQuery();
    }

    private static void InsertAudit(SqliteConnection connection, SqliteTransaction transaction, Guid approvalId, string action, string actor, string? notes)
    {
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = "INSERT INTO ApprovalAudit (Id, ApprovalId, Action, Actor, Notes, CreatedAtUtc) VALUES ($id,$approvalId,$action,$actor,$notes,$createdAtUtc);";
        command.Parameters.AddWithValue("$id", Guid.NewGuid().ToString());
        command.Parameters.AddWithValue("$approvalId", approvalId.ToString());
        command.Parameters.AddWithValue("$action", action);
        command.Parameters.AddWithValue("$actor", NormalizeActor(actor));
        command.Parameters.AddWithValue("$notes", string.IsNullOrWhiteSpace(notes) ? DBNull.Value : notes.Trim());
        command.Parameters.AddWithValue("$createdAtUtc", FormatDate(DateTime.UtcNow));
        command.ExecuteNonQuery();
    }

    private static ApprovalItem ReadItem(SqliteDataReader reader) => new(
        Guid.Parse(reader.GetString(0)),
        reader.IsDBNull(1) ? null : Guid.Parse(reader.GetString(1)),
        reader.IsDBNull(2) ? null : Guid.Parse(reader.GetString(2)),
        reader.GetString(3),
        reader.GetString(4),
        reader.GetString(5),
        Enum.Parse<ApprovalRiskLevel>(reader.GetString(6), true),
        Enum.Parse<ApprovalStatus>(reader.GetString(7), true),
        reader.GetString(8),
        reader.GetString(9),
        reader.GetString(10),
        ParseDate(reader.GetString(11)),
        reader.IsDBNull(12) ? null : reader.GetString(12),
        reader.IsDBNull(13) ? null : ParseDate(reader.GetString(13)),
        reader.IsDBNull(14) ? null : reader.GetString(14),
        reader.IsDBNull(15) ? null : Guid.Parse(reader.GetString(15)),
        reader.GetString(16),
        reader.GetString(17));

    private const string SelectColumns = """
        SELECT Id, OwnerUserId, SiteId, SiteName, OperationType, Title, RiskLevel, Status,
               BeforeJson, AfterJson, RequestedBy, RequestedAtUtc,
               ReviewedBy, ReviewedAtUtc, ReviewerNotes, ExecutionJobId,
               CorrelationId, IdempotencyKey
        """;

    private SqliteConnection OpenConnection()
    {
        var connection = new SqliteConnection(_connectionString);
        connection.Open();
        return connection;
    }

    private static string ResolveDatabasePath(string? databasePath)
    {
        if (!string.IsNullOrWhiteSpace(databasePath)) return Path.GetFullPath(databasePath);
        return Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AIWordPressManager",
            "Data",
            "approval-workflow.db");
    }

    private static string NormalizeJson(object? value)
    {
        if (value is null) return "{}";
        if (value is JsonElement element) return element.GetRawText();
        if (value is string text)
        {
            if (string.IsNullOrWhiteSpace(text)) return "{}";
            try { using var _ = JsonDocument.Parse(text); return text; }
            catch { return JsonSerializer.Serialize(text); }
        }
        return JsonSerializer.Serialize(value);
    }

    private static string BuildIdempotencyKey(Guid? siteId, string operationType, string afterJson)
    {
        var input = $"{siteId:N}|{operationType.Trim().ToLowerInvariant()}|{afterJson}";
        var hash = System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(input));
        return Convert.ToHexString(hash);
    }

    private static string ScopeIdempotencyKey(Guid ownerUserId, string logicalKey)
    {
        var input = $"{ownerUserId:N}|{logicalKey}";
        var hash = System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(input));
        return Convert.ToHexString(hash);
    }

    private static string NormalizeActor(string? actor) => string.IsNullOrWhiteSpace(actor) ? "System" : actor.Trim();
    private static string FormatDate(DateTime value) => value.ToUniversalTime().ToString("O");
    private static DateTime ParseDate(string value) => DateTime.Parse(value, null, System.Globalization.DateTimeStyles.RoundtripKind).ToUniversalTime();
}

public sealed record ApprovalSubmission(
    Guid? SiteId,
    string? SiteName,
    string OperationType,
    string Title,
    object? Before,
    object? After,
    string? RequestedBy,
    ApprovalRiskLevel? RiskLevel,
    string? CorrelationId,
    string? IdempotencyKey);

public sealed record ApprovalDecision(string Reviewer, string? Notes, bool ExecuteImmediately = false);
public sealed record ApprovalEditRequest(object? After, string Actor, string? Notes);

public sealed record ApprovalItem(
    Guid Id,
    Guid? OwnerUserId,
    Guid? SiteId,
    string SiteName,
    string OperationType,
    string Title,
    ApprovalRiskLevel RiskLevel,
    ApprovalStatus Status,
    string BeforeJson,
    string AfterJson,
    string RequestedBy,
    DateTime RequestedAtUtc,
    string? ReviewedBy,
    DateTime? ReviewedAtUtc,
    string? ReviewerNotes,
    Guid? ExecutionJobId,
    string CorrelationId,
    string IdempotencyKey);

public sealed record ApprovalAuditEntry(
    Guid Id,
    Guid ApprovalId,
    string Action,
    string Actor,
    string? Notes,
    DateTime CreatedAtUtc);
