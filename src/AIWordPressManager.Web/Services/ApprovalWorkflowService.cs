using System.Text.Json;
using Microsoft.Data.Sqlite;

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

    public ApprovalWorkflowService(ExecutionCenterService executionCenter)
    {
        _executionCenter = executionCenter;
        var dataDirectory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AIWordPressManager",
            "Data");
        Directory.CreateDirectory(dataDirectory);

        _connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = Path.Combine(dataDirectory, "approval-workflow.db"),
            Mode = SqliteOpenMode.ReadWriteCreate,
            Cache = SqliteCacheMode.Shared
        }.ToString();

        InitializeDatabase();
    }

    public IReadOnlyList<ApprovalItem> GetItems(ApprovalStatus? status = null, int take = 200)
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var command = connection.CreateCommand();
            command.CommandText = """
                SELECT Id, SiteId, SiteName, OperationType, Title, RiskLevel, Status,
                       BeforeJson, AfterJson, RequestedBy, RequestedAtUtc,
                       ReviewedBy, ReviewedAtUtc, ReviewerNotes, ExecutionJobId,
                       CorrelationId, IdempotencyKey
                FROM ApprovalItems
                WHERE ($status IS NULL OR Status = $status)
                ORDER BY RequestedAtUtc DESC
                LIMIT $take;
                """;
            command.Parameters.AddWithValue("$status", status?.ToString() ?? (object)DBNull.Value);
            command.Parameters.AddWithValue("$take", Math.Clamp(take, 1, 1000));

            using var reader = command.ExecuteReader();
            var result = new List<ApprovalItem>();
            while (reader.Read()) result.Add(ReadItem(reader));
            return result;
        }
    }

    public IReadOnlyList<ApprovalAuditEntry> GetAudit(Guid approvalId, int take = 200)
    {
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

    public ApprovalItem Submit(ApprovalSubmission request)
    {
        ArgumentNullException.ThrowIfNull(request);
        if (string.IsNullOrWhiteSpace(request.OperationType))
            throw new InvalidOperationException("Operation type is required.");
        if (string.IsNullOrWhiteSpace(request.Title))
            throw new InvalidOperationException("Approval title is required.");

        var beforeJson = NormalizeJson(request.Before);
        var afterJson = NormalizeJson(request.After);
        var idempotencyKey = string.IsNullOrWhiteSpace(request.IdempotencyKey)
            ? BuildIdempotencyKey(request.SiteId, request.OperationType, afterJson)
            : request.IdempotencyKey.Trim();

        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();

            using var existing = connection.CreateCommand();
            existing.Transaction = transaction;
            existing.CommandText = "SELECT Id FROM ApprovalItems WHERE IdempotencyKey=$key AND Status IN ('Pending','Approved','Executed') LIMIT 1;";
            existing.Parameters.AddWithValue("$key", idempotencyKey);
            var existingId = existing.ExecuteScalar() as string;
            if (!string.IsNullOrWhiteSpace(existingId))
            {
                transaction.Rollback();
                return GetById(Guid.Parse(existingId))!;
            }

            var item = new ApprovalItem(
                Guid.NewGuid(),
                request.SiteId,
                request.SiteName?.Trim() ?? string.Empty,
                request.OperationType.Trim(),
                request.Title.Trim(),
                ResolveRisk(request.OperationType, request.RiskLevel),
                ApprovalStatus.Pending,
                beforeJson,
                afterJson,
                string.IsNullOrWhiteSpace(request.RequestedBy) ? "System" : request.RequestedBy.Trim(),
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
            return item;
        }
    }

    public ApprovalItem Approve(Guid id, string reviewer, string? notes, bool executeImmediately)
    {
        var item = Review(id, ApprovalStatus.Approved, reviewer, notes, "Approved");
        if (!executeImmediately) return item;

        var job = _executionCenter.Enqueue(
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
            update.CommandText = "UPDATE ApprovalItems SET Status='Executed', ExecutionJobId=$jobId WHERE Id=$id AND Status='Approved';";
            update.Parameters.AddWithValue("$jobId", job.Id.ToString());
            update.Parameters.AddWithValue("$id", id.ToString());
            update.ExecuteNonQuery();
            InsertAudit(connection, transaction, id, "QueuedForExecution", reviewer, $"Execution job: {job.Id}");
            transaction.Commit();
        }

        return GetById(id)!;
    }

    public ApprovalItem Reject(Guid id, string reviewer, string? notes) =>
        Review(id, ApprovalStatus.Rejected, reviewer, notes, "Rejected");

    public ApprovalItem UpdateProposal(Guid id, object? updatedAfter, string actor, string? notes)
    {
        var afterJson = NormalizeJson(updatedAfter);
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var transaction = connection.BeginTransaction();
            using var command = connection.CreateCommand();
            command.Transaction = transaction;
            command.CommandText = "UPDATE ApprovalItems SET AfterJson=$afterJson WHERE Id=$id AND Status='Pending';";
            command.Parameters.AddWithValue("$afterJson", afterJson);
            command.Parameters.AddWithValue("$id", id.ToString());
            if (command.ExecuteNonQuery() == 0)
                throw new InvalidOperationException("Only pending approval items can be edited.");
            InsertAudit(connection, transaction, id, "Edited", NormalizeActor(actor), notes);
            transaction.Commit();
        }
        return GetById(id)!;
    }

    public ApprovalItem? GetById(Guid id)
    {
        lock (_sync)
        {
            using var connection = OpenConnection();
            using var command = connection.CreateCommand();
            command.CommandText = """
                SELECT Id, SiteId, SiteName, OperationType, Title, RiskLevel, Status,
                       BeforeJson, AfterJson, RequestedBy, RequestedAtUtc,
                       ReviewedBy, ReviewedAtUtc, ReviewerNotes, ExecutionJobId,
                       CorrelationId, IdempotencyKey
                FROM ApprovalItems WHERE Id=$id LIMIT 1;
                """;
            command.Parameters.AddWithValue("$id", id.ToString());
            using var reader = command.ExecuteReader();
            return reader.Read() ? ReadItem(reader) : null;
        }
    }

    private ApprovalItem Review(Guid id, ApprovalStatus status, string reviewer, string? notes, string action)
    {
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
                WHERE Id=$id AND Status='Pending';
                """;
            command.Parameters.AddWithValue("$status", status.ToString());
            command.Parameters.AddWithValue("$reviewer", actor);
            command.Parameters.AddWithValue("$reviewedAt", FormatDate(DateTime.UtcNow));
            command.Parameters.AddWithValue("$notes", string.IsNullOrWhiteSpace(notes) ? DBNull.Value : notes.Trim());
            command.Parameters.AddWithValue("$id", id.ToString());
            if (command.ExecuteNonQuery() == 0)
                throw new InvalidOperationException("Approval item is not pending or does not exist.");
            InsertAudit(connection, transaction, id, action, actor, notes);
            transaction.Commit();
        }
        return GetById(id)!;
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
            using var command = connection.CreateCommand();
            command.CommandText = """
                PRAGMA journal_mode=WAL;
                PRAGMA foreign_keys=ON;

                CREATE TABLE IF NOT EXISTS ApprovalItems (
                    Id TEXT PRIMARY KEY,
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

                CREATE INDEX IF NOT EXISTS IX_ApprovalItems_Status_RequestedAtUtc
                    ON ApprovalItems(Status, RequestedAtUtc);
                CREATE INDEX IF NOT EXISTS IX_ApprovalAudit_ApprovalId_CreatedAtUtc
                    ON ApprovalAudit(ApprovalId, CreatedAtUtc);
                """;
            command.ExecuteNonQuery();
        }
    }

    private static void InsertItem(SqliteConnection connection, SqliteTransaction transaction, ApprovalItem item)
    {
        using var command = connection.CreateCommand();
        command.Transaction = transaction;
        command.CommandText = """
            INSERT INTO ApprovalItems
            (Id, SiteId, SiteName, OperationType, Title, RiskLevel, Status, BeforeJson, AfterJson,
             RequestedBy, RequestedAtUtc, ReviewedBy, ReviewedAtUtc, ReviewerNotes, ExecutionJobId,
             CorrelationId, IdempotencyKey)
            VALUES
            ($id, $siteId, $siteName, $operationType, $title, $riskLevel, $status, $beforeJson, $afterJson,
             $requestedBy, $requestedAtUtc, NULL, NULL, NULL, NULL, $correlationId, $idempotencyKey);
            """;
        command.Parameters.AddWithValue("$id", item.Id.ToString());
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
        reader.GetString(2),
        reader.GetString(3),
        reader.GetString(4),
        Enum.Parse<ApprovalRiskLevel>(reader.GetString(5), true),
        Enum.Parse<ApprovalStatus>(reader.GetString(6), true),
        reader.GetString(7),
        reader.GetString(8),
        reader.GetString(9),
        ParseDate(reader.GetString(10)),
        reader.IsDBNull(11) ? null : reader.GetString(11),
        reader.IsDBNull(12) ? null : ParseDate(reader.GetString(12)),
        reader.IsDBNull(13) ? null : reader.GetString(13),
        reader.IsDBNull(14) ? null : Guid.Parse(reader.GetString(14)),
        reader.GetString(15),
        reader.GetString(16));

    private SqliteConnection OpenConnection()
    {
        var connection = new SqliteConnection(_connectionString);
        connection.Open();
        return connection;
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
