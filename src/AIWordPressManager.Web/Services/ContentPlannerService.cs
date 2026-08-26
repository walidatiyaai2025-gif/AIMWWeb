using System.Security.Cryptography;
using System.Text;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Persistence;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public enum PlannerItemStatus { Idea, Brief, Draft, Review, Scheduled, Published, Cancelled }

public sealed class ContentPlannerService
{
    private readonly object _sync = new();
    private readonly string _connectionString;
    private readonly IAIOrchestrator _ai;
    private readonly IAIPromptRegistry _prompts;
    private readonly ExecutionCenterService _execution;
    private readonly ExecutionOperationTracker _executionTracker;
    private readonly NotificationInboxService _notifications;
    private readonly CurrentUserContext _currentUser;
    private readonly AppDbContext _dbContext;
    private readonly ApplicationSecurityAuditService _securityAudit;

    public ContentPlannerService(
        IAIOrchestrator ai,
        IAIPromptRegistry prompts,
        ExecutionCenterService execution,
        ExecutionOperationTracker executionTracker,
        NotificationInboxService notifications,
        CurrentUserContext currentUser,
        IApplicationPathService applicationPaths,
        AppDbContext dbContext,
        ApplicationSecurityAuditService securityAudit)
    {
        _ai = ai;
        _prompts = prompts;
        _execution = execution;
        _executionTracker = executionTracker;
        _notifications = notifications;
        _currentUser = currentUser;
        _dbContext = dbContext;
        _securityAudit = securityAudit;

        var directory = applicationPaths.GetApplicationDataDirectory();
        Directory.CreateDirectory(directory);
        _connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = Path.Combine(directory, "content-planner.db"),
            Mode = SqliteOpenMode.ReadWriteCreate,
            Cache = SqliteCacheMode.Shared
        }.ToString();
        Initialize();
    }

    public IReadOnlyList<PlannerItem> GetItems(
        Guid? siteId = null,
        PlannerItemStatus? status = null,
        DateTime? fromUtc = null,
        DateTime? toUtc = null)
    {
        var ownerUserId = _currentUser.RequirePermission(ApplicationPermissionCatalog.ContentView);
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                SELECT Id, SiteId, SiteName, Title, Status, Idea, Brief, DraftContent,
                       ScheduledAtUtc, WordPressPostId, CreatedAtUtc, UpdatedAtUtc, CreatedBy
                FROM PlannerItems
                WHERE OwnerUserId=$ownerUserId
                  AND ($siteId IS NULL OR SiteId=$siteId)
                  AND ($status IS NULL OR Status=$status)
                  AND ($fromUtc IS NULL OR ScheduledAtUtc >= $fromUtc)
                  AND ($toUtc IS NULL OR ScheduledAtUtc <= $toUtc)
                ORDER BY COALESCE(ScheduledAtUtc, UpdatedAtUtc), UpdatedAtUtc DESC;
                """;
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString("D"));
            command.Parameters.AddWithValue("$siteId", Db(siteId?.ToString("D")));
            command.Parameters.AddWithValue("$status", Db(status?.ToString()));
            command.Parameters.AddWithValue("$fromUtc", Db(fromUtc?.ToUniversalTime().ToString("O")));
            command.Parameters.AddWithValue("$toUtc", Db(toUtc?.ToUniversalTime().ToString("O")));
            using var reader = command.ExecuteReader();
            var result = new List<PlannerItem>();
            while (reader.Read()) result.Add(Read(reader));
            return result;
        }
    }

    public PlannerItem? Get(Guid id)
    {
        var ownerUserId = _currentUser.RequirePermission(ApplicationPermissionCatalog.ContentView);
        return GetOwned(id, ownerUserId);
    }

    public async Task<PlannerItem> CreateAsync(CreatePlannerItem request, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(request.Title)) throw new InvalidOperationException("Title is required.");
        var ownerUserId = _currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
        var site = await ResolveOwnedSiteAsync(request.SiteId, ownerUserId, cancellationToken);
        var now = DateTime.UtcNow;
        var actor = string.IsNullOrWhiteSpace(_currentUser.UserName)
            ? ownerUserId.ToString("D")
            : _currentUser.UserName.Trim();
        var item = new PlannerItem(
            Guid.NewGuid(),
            site?.Id,
            site?.Name ?? string.Empty,
            request.Title.Trim(),
            PlannerItemStatus.Idea,
            request.Idea?.Trim(),
            null,
            null,
            request.ScheduledAtUtc?.ToUniversalTime(),
            null,
            now,
            now,
            actor);

        Save(item, ownerUserId);
        await AuditSucceededAsync("Create", item, cancellationToken);
        _notifications.Create(
            ownerUserId,
            "Content planner",
            $"Idea created: {item.Title}",
            NotificationSeverity.Success,
            relatedId: item.Id,
            siteId: item.SiteId,
            source: "ContentPlanner");
        return item;
    }

    public async Task<PlannerItem> UpdateAsync(Guid id, UpdatePlannerItem request, CancellationToken cancellationToken = default)
    {
        var ownerUserId = _currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
        var current = RequireOwned(id, ownerUserId);
        await EnsureItemSiteOwnershipAsync(current, ownerUserId, cancellationToken);
        var updated = ApplyUpdate(current, request);
        Save(updated, ownerUserId);
        await AuditSucceededAsync("Update", updated, cancellationToken);
        return updated;
    }

    public async Task<PlannerItem> GenerateBriefAsync(
        Guid id,
        string culture,
        CancellationToken cancellationToken = default)
    {
        var ownerUserId = _currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
        var item = RequireOwned(id, ownerUserId);
        await EnsureItemSiteOwnershipAsync(item, ownerUserId, cancellationToken);
        var prompt = _prompts.Get("content-brief", culture);
        var result = await _ai.ExecuteAsync(
            new AIRequest(
                item.Idea ?? item.Title,
                prompt,
                null,
                0.2,
                1800,
                item.SiteId,
                ownerUserId.ToString("D"),
                "content-brief"),
            cancellationToken);
        if (!result.IsSuccess) throw new InvalidOperationException(result.Error ?? "AI brief generation failed.");

        var updated = ApplyUpdate(item, new UpdatePlannerItem(null, PlannerItemStatus.Brief, null, result.Content, null, null, null));
        Save(updated, ownerUserId);
        await AuditSucceededAsync("GenerateBrief", updated, cancellationToken);
        return updated;
    }

    public async Task<PlannerItem> GenerateDraftAsync(
        Guid id,
        string culture,
        CancellationToken cancellationToken = default)
    {
        var ownerUserId = _currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
        var item = RequireOwned(id, ownerUserId);
        await EnsureItemSiteOwnershipAsync(item, ownerUserId, cancellationToken);
        var input = string.IsNullOrWhiteSpace(item.Brief) ? item.Idea ?? item.Title : item.Brief;
        var prompt = culture.StartsWith("ar", StringComparison.OrdinalIgnoreCase)
            ? "اكتب مسودة مقال كاملة ومنظمة بصيغة HTML اعتمادًا على الملخص التالي. لا تنشر تلقائيًا."
            : "Write a complete, structured HTML article draft from the following brief. Do not publish automatically.";
        var result = await _ai.ExecuteAsync(
            new AIRequest(
                input,
                prompt,
                null,
                0.3,
                4000,
                item.SiteId,
                ownerUserId.ToString("D"),
                "planner-draft"),
            cancellationToken);
        if (!result.IsSuccess) throw new InvalidOperationException(result.Error ?? "AI draft generation failed.");

        var updated = ApplyUpdate(item, new UpdatePlannerItem(null, PlannerItemStatus.Draft, null, null, result.Content, null, null));
        Save(updated, ownerUserId);
        await AuditSucceededAsync("GenerateDraft", updated, cancellationToken);
        return updated;
    }

    public async Task<PlannerItem> QueueForExecutionAsync(Guid id, CancellationToken cancellationToken = default)
    {
        var ownerUserId = _currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
        var executionOwnerUserId = _currentUser.RequirePermission(ApplicationPermissionCatalog.OperationsExecute);
        if (executionOwnerUserId != ownerUserId)
            throw new UnauthorizedAccessException("Authenticated account identity changed during the planner operation.");

        var item = RequireOwned(id, ownerUserId);
        if (string.IsNullOrWhiteSpace(item.DraftContent)) throw new InvalidOperationException("A draft is required before queueing.");
        if (!item.SiteId.HasValue)
            throw new InvalidOperationException("Select an owned site before queueing planner content for execution.");

        var site = await ResolveOwnedSiteAsync(item.SiteId, ownerUserId, cancellationToken)
            ?? throw new InvalidOperationException("Site is unavailable.");
        var idempotencyKey = CreatePublishIdempotencyKey(item);
        var existingJob = _execution.GetJobs(ownerUserId)
            .FirstOrDefault(x =>
                string.Equals(x.Type, PlannerPublishWorker.JobType, StringComparison.Ordinal) &&
                string.Equals(x.IdempotencyKey, idempotencyKey, StringComparison.Ordinal));

        ExecutionJob executionJob;
        var retried = false;
        if (existingJob is not null)
        {
            if (existingJob.SiteId != site.Id ||
                !string.Equals(existingJob.CorrelationId, item.Id.ToString("D"), StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException("The existing planner execution identity does not match the current owned item/site.");
            }

            if (existingJob.Status == "Failed")
            {
                if (!_executionTracker.RetryTracked(existingJob.Id, ownerUserId, PlannerPublishWorker.JobType))
                    throw new InvalidOperationException("The failed planner publish job could not be queued for safe retry.");
                retried = true;
            }
            else if (existingJob.Status == "Completed")
            {
                if (item.Status is PlannerItemStatus.Published or PlannerItemStatus.Scheduled)
                    return item;
                throw new InvalidOperationException("The planner execution completed but the planner item is not reconciled. Review the execution history before queueing again.");
            }
            else if (existingJob.Status is not ("Waiting" or "Running"))
            {
                throw new InvalidOperationException($"Planner publish job is in unsupported state '{existingJob.Status}'.");
            }

            executionJob = existingJob;
        }
        else
        {
            executionJob = _execution.Enqueue(
                ownerUserId,
                site.Id,
                $"Publish planned content: {item.Title}",
                PlannerPublishWorker.JobType,
                site.Name,
                PlannerPublishWorker.TotalSteps,
                idempotencyKey,
                item.Id.ToString("D"));
        }

        _notifications.Create(
            ownerUserId,
            retried ? "Execution retry queued" : "Execution queued",
            item.Title,
            NotificationSeverity.Information,
            relatedId: item.Id,
            siteId: site.Id,
            executionJobId: executionJob.Id,
            source: "ContentPlanner");

        var updated = ApplyUpdate(item, new UpdatePlannerItem(null, PlannerItemStatus.Review, null, null, null, null, null));
        Save(updated, ownerUserId);
        await AuditSucceededAsync(
            retried ? "RetryPublishExecution" : "QueueForExecution",
            updated,
            cancellationToken,
            new Dictionary<string, string>
            {
                ["executionJobId"] = executionJob.Id.ToString("D"),
                ["publishRevision"] = idempotencyKey
            });
        return updated;
    }

    public async Task<PlannerItem> GetForExecutionAsync(
        Guid ownerUserId,
        Guid itemId,
        Guid siteId,
        string expectedIdempotencyKey,
        CancellationToken cancellationToken = default)
    {
        RequireBackgroundContinuation(ownerUserId);
        var item = RequireOwned(itemId, ownerUserId);
        if (item.SiteId != siteId)
            throw new UnauthorizedAccessException("Planner execution site does not match the queued owned item.");
        await EnsureItemSiteOwnershipAsync(item, ownerUserId, cancellationToken);
        if (!string.Equals(CreatePublishIdempotencyKey(item), expectedIdempotencyKey, StringComparison.Ordinal))
            throw new InvalidOperationException("Planner content changed after this publish job was queued. Queue the current revision as a new execution.");
        if (string.IsNullOrWhiteSpace(item.DraftContent))
            throw new InvalidOperationException("The queued planner item no longer contains a publishable draft.");
        return item;
    }

    public async Task<PlannerItem> ReconcilePublishAsync(
        Guid ownerUserId,
        Guid itemId,
        Guid siteId,
        int wordPressPostId,
        PlannerItemStatus terminalStatus,
        CancellationToken cancellationToken = default)
    {
        RequireBackgroundContinuation(ownerUserId);
        if (wordPressPostId <= 0) throw new ArgumentOutOfRangeException(nameof(wordPressPostId));
        if (terminalStatus is not (PlannerItemStatus.Published or PlannerItemStatus.Scheduled))
            throw new ArgumentOutOfRangeException(nameof(terminalStatus), terminalStatus, "Planner publish reconciliation requires a terminal published/scheduled state.");

        var item = RequireOwned(itemId, ownerUserId);
        if (item.SiteId != siteId)
            throw new UnauthorizedAccessException("Planner execution site does not match the owned planner item.");
        await EnsureItemSiteOwnershipAsync(item, ownerUserId, cancellationToken);
        var updated = ApplyUpdate(
            item,
            new UpdatePlannerItem(null, terminalStatus, null, null, null, null, wordPressPostId));
        Save(updated, ownerUserId);
        return updated;
    }

    public static string CreatePublishIdempotencyKey(PlannerItem item)
    {
        ArgumentNullException.ThrowIfNull(item);
        var payload = string.Join(
            "\n",
            item.Id.ToString("D"),
            item.Title.Trim(),
            item.DraftContent ?? string.Empty,
            item.ScheduledAtUtc?.ToUniversalTime().ToString("O") ?? string.Empty);
        var hash = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(payload))).ToLowerInvariant();
        return $"planner-publish:{item.Id:N}:{hash[..24]}";
    }

    private static void RequireBackgroundContinuation(Guid ownerUserId)
    {
        if (ownerUserId == Guid.Empty ||
            !BackgroundContentMutationAuthorization.IsGranted ||
            !BackgroundExecutionIdentity.TryGetOwnerUserId(out var backgroundOwnerUserId) ||
            backgroundOwnerUserId != ownerUserId)
        {
            throw new UnauthorizedAccessException("Authorized planner background execution identity is required.");
        }
    }

    private PlannerItem RequireOwned(Guid id, Guid ownerUserId) =>
        GetOwned(id, ownerUserId) ?? throw new InvalidOperationException("Planner item not found.");

    private PlannerItem? GetOwned(Guid id, Guid ownerUserId)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                SELECT Id, SiteId, SiteName, Title, Status, Idea, Brief, DraftContent,
                       ScheduledAtUtc, WordPressPostId, CreatedAtUtc, UpdatedAtUtc, CreatedBy
                FROM PlannerItems
                WHERE Id=$id AND OwnerUserId=$ownerUserId
                LIMIT 1;
                """;
            command.Parameters.AddWithValue("$id", id.ToString("D"));
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString("D"));
            using var reader = command.ExecuteReader();
            return reader.Read() ? Read(reader) : null;
        }
    }

    private async Task<AIWordPressManager.Domain.Entities.Site?> ResolveOwnedSiteAsync(
        Guid? siteId,
        Guid ownerUserId,
        CancellationToken cancellationToken)
    {
        if (!siteId.HasValue) return null;
        var site = await _dbContext.Sites.AsNoTracking()
            .SingleOrDefaultAsync(
                x => x.Id == siteId.Value && x.OwnerUserId == ownerUserId,
                cancellationToken);
        if (site is null)
            throw new InvalidOperationException("Site is unavailable.");
        return site;
    }

    private async Task EnsureItemSiteOwnershipAsync(
        PlannerItem item,
        Guid ownerUserId,
        CancellationToken cancellationToken)
    {
        if (item.SiteId.HasValue)
            _ = await ResolveOwnedSiteAsync(item.SiteId, ownerUserId, cancellationToken);
    }

    private async Task AuditSucceededAsync(
        string action,
        PlannerItem item,
        CancellationToken cancellationToken,
        IReadOnlyDictionary<string, string>? extraMetadata = null)
    {
        var metadata = new Dictionary<string, string>(StringComparer.Ordinal)
        {
            ["ownerScoped"] = "true",
            ["siteScope"] = item.SiteId?.ToString("D") ?? "account"
        };
        if (extraMetadata is not null)
        {
            foreach (var pair in extraMetadata) metadata[pair.Key] = pair.Value;
        }

        await _securityAudit.RecordCurrentAsync(
            "ContentPlanner",
            action,
            "Succeeded",
            "PlannerItem",
            item.Id.ToString("D"),
            item.Title,
            metadata,
            cancellationToken);
    }

    private static PlannerItem ApplyUpdate(PlannerItem current, UpdatePlannerItem request) =>
        current with
        {
            Title = string.IsNullOrWhiteSpace(request.Title) ? current.Title : request.Title.Trim(),
            Status = request.Status ?? current.Status,
            Idea = request.Idea ?? current.Idea,
            Brief = request.Brief ?? current.Brief,
            DraftContent = request.DraftContent ?? current.DraftContent,
            ScheduledAtUtc = request.ScheduledAtUtc?.ToUniversalTime() ?? current.ScheduledAtUtc,
            WordPressPostId = request.WordPressPostId ?? current.WordPressPostId,
            UpdatedAtUtc = DateTime.UtcNow
        };

    private void Save(PlannerItem item, Guid ownerUserId)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                INSERT INTO PlannerItems
                (Id, OwnerUserId, SiteId, SiteName, Title, Status, Idea, Brief, DraftContent, ScheduledAtUtc,
                 WordPressPostId, CreatedAtUtc, UpdatedAtUtc, CreatedBy)
                VALUES ($id,$ownerUserId,$siteId,$siteName,$title,$status,$idea,$brief,$draft,$scheduled,$postId,$created,$updated,$createdBy)
                ON CONFLICT(Id) DO UPDATE SET
                  SiteId=excluded.SiteId, SiteName=excluded.SiteName, Title=excluded.Title, Status=excluded.Status,
                  Idea=excluded.Idea, Brief=excluded.Brief, DraftContent=excluded.DraftContent,
                  ScheduledAtUtc=excluded.ScheduledAtUtc, WordPressPostId=excluded.WordPressPostId,
                  UpdatedAtUtc=excluded.UpdatedAtUtc
                WHERE PlannerItems.OwnerUserId=excluded.OwnerUserId;
                """;
            command.Parameters.AddWithValue("$id", item.Id.ToString("D"));
            command.Parameters.AddWithValue("$ownerUserId", ownerUserId.ToString("D"));
            command.Parameters.AddWithValue("$siteId", Db(item.SiteId?.ToString("D")));
            command.Parameters.AddWithValue("$siteName", item.SiteName);
            command.Parameters.AddWithValue("$title", item.Title);
            command.Parameters.AddWithValue("$status", item.Status.ToString());
            command.Parameters.AddWithValue("$idea", Db(item.Idea));
            command.Parameters.AddWithValue("$brief", Db(item.Brief));
            command.Parameters.AddWithValue("$draft", Db(item.DraftContent));
            command.Parameters.AddWithValue("$scheduled", Db(item.ScheduledAtUtc?.ToString("O")));
            command.Parameters.AddWithValue("$postId", Db(item.WordPressPostId));
            command.Parameters.AddWithValue("$created", item.CreatedAtUtc.ToString("O"));
            command.Parameters.AddWithValue("$updated", item.UpdatedAtUtc.ToString("O"));
            command.Parameters.AddWithValue("$createdBy", item.CreatedBy);
            if (command.ExecuteNonQuery() != 1)
                throw new InvalidOperationException("Planner item could not be persisted for the current account.");
        }
    }

    private void Initialize()
    {
        using var connection = Open();
        using (var command = connection.CreateCommand())
        {
            command.CommandText = """
                PRAGMA journal_mode=WAL;
                CREATE TABLE IF NOT EXISTS PlannerItems(
                  Id TEXT PRIMARY KEY, OwnerUserId TEXT NULL, SiteId TEXT NULL, SiteName TEXT NOT NULL, Title TEXT NOT NULL,
                  Status TEXT NOT NULL, Idea TEXT NULL, Brief TEXT NULL, DraftContent TEXT NULL,
                  ScheduledAtUtc TEXT NULL, WordPressPostId INTEGER NULL, CreatedAtUtc TEXT NOT NULL,
                  UpdatedAtUtc TEXT NOT NULL, CreatedBy TEXT NOT NULL);
                """;
            command.ExecuteNonQuery();
        }

        // Existing installations intentionally retain NULL ownership for legacy rows. Normal account
        // queries never return those rows; silently assigning them to whichever user upgrades first
        // would create a cross-account disclosure.
        if (!HasColumn(connection, "PlannerItems", "OwnerUserId"))
        {
            using var migration = connection.CreateCommand();
            migration.CommandText = "ALTER TABLE PlannerItems ADD COLUMN OwnerUserId TEXT NULL;";
            migration.ExecuteNonQuery();
        }

        using var indexes = connection.CreateCommand();
        indexes.CommandText = """
            CREATE INDEX IF NOT EXISTS IX_PlannerItems_Status_Scheduled ON PlannerItems(Status, ScheduledAtUtc);
            CREATE INDEX IF NOT EXISTS IX_PlannerItems_Owner_Status_Scheduled ON PlannerItems(OwnerUserId, Status, ScheduledAtUtc);
            """;
        indexes.ExecuteNonQuery();
    }

    private static bool HasColumn(SqliteConnection connection, string tableName, string columnName)
    {
        using var command = connection.CreateCommand();
        command.CommandText = $"PRAGMA table_info({tableName});";
        using var reader = command.ExecuteReader();
        while (reader.Read())
        {
            if (string.Equals(reader.GetString(1), columnName, StringComparison.OrdinalIgnoreCase))
                return true;
        }
        return false;
    }

    private SqliteConnection Open()
    {
        var connection = new SqliteConnection(_connectionString);
        connection.Open();
        return connection;
    }

    private static object Db(object? value) => value ?? DBNull.Value;
    private static DateTime ParseDate(string value) => DateTime.Parse(value, null, System.Globalization.DateTimeStyles.RoundtripKind).ToUniversalTime();
    private static PlannerItem Read(SqliteDataReader reader) => new(
        Guid.Parse(reader.GetString(0)),
        reader.IsDBNull(1) ? null : Guid.Parse(reader.GetString(1)),
        reader.GetString(2),
        reader.GetString(3),
        Enum.Parse<PlannerItemStatus>(reader.GetString(4), true),
        reader.IsDBNull(5) ? null : reader.GetString(5),
        reader.IsDBNull(6) ? null : reader.GetString(6),
        reader.IsDBNull(7) ? null : reader.GetString(7),
        reader.IsDBNull(8) ? null : ParseDate(reader.GetString(8)),
        reader.IsDBNull(9) ? null : reader.GetInt32(9),
        ParseDate(reader.GetString(10)),
        ParseDate(reader.GetString(11)),
        reader.GetString(12));
}

public sealed record PlannerItem(
    Guid Id,
    Guid? SiteId,
    string SiteName,
    string Title,
    PlannerItemStatus Status,
    string? Idea,
    string? Brief,
    string? DraftContent,
    DateTime? ScheduledAtUtc,
    int? WordPressPostId,
    DateTime CreatedAtUtc,
    DateTime UpdatedAtUtc,
    string CreatedBy);

// SiteName and CreatedBy remain in the input shape for compatibility with existing callers, but the
// service never trusts them: both are resolved from authenticated server state before persistence.
public sealed record CreatePlannerItem(
    Guid? SiteId,
    string? SiteName,
    string Title,
    string? Idea,
    DateTime? ScheduledAtUtc,
    string? CreatedBy);

public sealed record UpdatePlannerItem(
    string? Title,
    PlannerItemStatus? Status,
    string? Idea,
    string? Brief,
    string? DraftContent,
    DateTime? ScheduledAtUtc,
    int? WordPressPostId);