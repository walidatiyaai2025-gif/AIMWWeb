using AIWordPressManager.Application.Abstractions.AI;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Web.Services;

public enum PlannerItemStatus { Idea, Brief, Draft, Review, Scheduled, Published, Cancelled }
public enum NotificationSeverity { Information, Success, Warning, Error }

public sealed class ContentPlannerService
{
    private readonly object _sync = new();
    private readonly string _connectionString;
    private readonly IAIOrchestrator _ai;
    private readonly IAIPromptRegistry _prompts;
    private readonly ExecutionCenterService _execution;
    private readonly NotificationInboxService _notifications;

    public ContentPlannerService(
        IAIOrchestrator ai,
        IAIPromptRegistry prompts,
        ExecutionCenterService execution,
        NotificationInboxService notifications)
    {
        _ai = ai;
        _prompts = prompts;
        _execution = execution;
        _notifications = notifications;

        var directory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager", "Data");
        Directory.CreateDirectory(directory);
        _connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = Path.Combine(directory, "content-planner.db"),
            Mode = SqliteOpenMode.ReadWriteCreate,
            Cache = SqliteCacheMode.Shared
        }.ToString();
        Initialize();
    }

    public IReadOnlyList<PlannerItem> GetItems(Guid? siteId = null, PlannerItemStatus? status = null, DateTime? fromUtc = null, DateTime? toUtc = null)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                SELECT Id, SiteId, SiteName, Title, Status, Idea, Brief, DraftContent,
                       ScheduledAtUtc, WordPressPostId, CreatedAtUtc, UpdatedAtUtc, CreatedBy
                FROM PlannerItems
                WHERE ($siteId IS NULL OR SiteId=$siteId)
                  AND ($status IS NULL OR Status=$status)
                  AND ($fromUtc IS NULL OR ScheduledAtUtc >= $fromUtc)
                  AND ($toUtc IS NULL OR ScheduledAtUtc <= $toUtc)
                ORDER BY COALESCE(ScheduledAtUtc, UpdatedAtUtc), UpdatedAtUtc DESC;
                """;
            command.Parameters.AddWithValue("$siteId", Db(siteId?.ToString()));
            command.Parameters.AddWithValue("$status", Db(status?.ToString()));
            command.Parameters.AddWithValue("$fromUtc", Db(fromUtc?.ToUniversalTime().ToString("O")));
            command.Parameters.AddWithValue("$toUtc", Db(toUtc?.ToUniversalTime().ToString("O")));
            using var reader = command.ExecuteReader();
            var result = new List<PlannerItem>();
            while (reader.Read()) result.Add(Read(reader));
            return result;
        }
    }

    public PlannerItem Create(CreatePlannerItem request)
    {
        if (string.IsNullOrWhiteSpace(request.Title)) throw new InvalidOperationException("Title is required.");
        var now = DateTime.UtcNow;
        var item = new PlannerItem(Guid.NewGuid(), request.SiteId, request.SiteName?.Trim() ?? string.Empty,
            request.Title.Trim(), PlannerItemStatus.Idea, request.Idea?.Trim(), null, null,
            request.ScheduledAtUtc?.ToUniversalTime(), null, now, now,
            string.IsNullOrWhiteSpace(request.CreatedBy) ? "System" : request.CreatedBy.Trim());
        Save(item);
        _notifications.Create(item.CreatedBy, "Content planner", $"Idea created: {item.Title}", NotificationSeverity.Success, item.Id);
        return item;
    }

    public PlannerItem Update(Guid id, UpdatePlannerItem request)
    {
        var current = Get(id) ?? throw new InvalidOperationException("Planner item not found.");
        var updated = current with
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
        Save(updated);
        return updated;
    }

    public async Task<PlannerItem> GenerateBriefAsync(Guid id, string culture, string? userId, CancellationToken cancellationToken)
    {
        var item = Get(id) ?? throw new InvalidOperationException("Planner item not found.");
        var prompt = _prompts.Get("content-brief", culture);
        var result = await _ai.ExecuteAsync(new AIRequest(item.Idea ?? item.Title, prompt, null, 0.2, 1800, item.SiteId, userId, "content-brief"), cancellationToken);
        if (!result.IsSuccess) throw new InvalidOperationException(result.Error ?? "AI brief generation failed.");
        return Update(id, new UpdatePlannerItem(null, PlannerItemStatus.Brief, null, result.Content, null, null, null));
    }

    public async Task<PlannerItem> GenerateDraftAsync(Guid id, string culture, string? userId, CancellationToken cancellationToken)
    {
        var item = Get(id) ?? throw new InvalidOperationException("Planner item not found.");
        var input = string.IsNullOrWhiteSpace(item.Brief) ? item.Idea ?? item.Title : item.Brief;
        var prompt = culture.StartsWith("ar", StringComparison.OrdinalIgnoreCase)
            ? "اكتب مسودة مقال كاملة ومنظمة بصيغة HTML اعتمادًا على الملخص التالي. لا تنشر تلقائيًا."
            : "Write a complete, structured HTML article draft from the following brief. Do not publish automatically.";
        var result = await _ai.ExecuteAsync(new AIRequest(input, prompt, null, 0.3, 4000, item.SiteId, userId, "planner-draft"), cancellationToken);
        if (!result.IsSuccess) throw new InvalidOperationException(result.Error ?? "AI draft generation failed.");
        return Update(id, new UpdatePlannerItem(null, PlannerItemStatus.Draft, null, null, result.Content, null, null));
    }

    public PlannerItem QueueForExecution(Guid id)
    {
        var item = Get(id) ?? throw new InvalidOperationException("Planner item not found.");
        if (string.IsNullOrWhiteSpace(item.DraftContent)) throw new InvalidOperationException("A draft is required before queueing.");
        _execution.Enqueue($"Publish planned content: {item.Title}", "Planner Publish", item.SiteName, 1);
        _notifications.Create(item.CreatedBy, "Execution queued", item.Title, NotificationSeverity.Information, item.Id);
        return Update(id, new UpdatePlannerItem(null, PlannerItemStatus.Review, null, null, null, null, null));
    }

    public PlannerItem? Get(Guid id) => GetItems().FirstOrDefault(x => x.Id == id);

    private void Save(PlannerItem item)
    {
        lock (_sync)
        {
            using var connection = Open();
            using var command = connection.CreateCommand();
            command.CommandText = """
                INSERT INTO PlannerItems
                (Id, SiteId, SiteName, Title, Status, Idea, Brief, DraftContent, ScheduledAtUtc,
                 WordPressPostId, CreatedAtUtc, UpdatedAtUtc, CreatedBy)
                VALUES ($id,$siteId,$siteName,$title,$status,$idea,$brief,$draft,$scheduled,$postId,$created,$updated,$createdBy)
                ON CONFLICT(Id) DO UPDATE SET
                  SiteName=excluded.SiteName, Title=excluded.Title, Status=excluded.Status,
                  Idea=excluded.Idea, Brief=excluded.Brief, DraftContent=excluded.DraftContent,
                  ScheduledAtUtc=excluded.ScheduledAtUtc, WordPressPostId=excluded.WordPressPostId,
                  UpdatedAtUtc=excluded.UpdatedAtUtc;
                """;
            command.Parameters.AddWithValue("$id", item.Id.ToString());
            command.Parameters.AddWithValue("$siteId", Db(item.SiteId?.ToString()));
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
            command.ExecuteNonQuery();
        }
    }

    private void Initialize()
    {
        using var connection = Open();
        using var command = connection.CreateCommand();
        command.CommandText = """
            PRAGMA journal_mode=WAL;
            CREATE TABLE IF NOT EXISTS PlannerItems(
              Id TEXT PRIMARY KEY, SiteId TEXT NULL, SiteName TEXT NOT NULL, Title TEXT NOT NULL,
              Status TEXT NOT NULL, Idea TEXT NULL, Brief TEXT NULL, DraftContent TEXT NULL,
              ScheduledAtUtc TEXT NULL, WordPressPostId INTEGER NULL, CreatedAtUtc TEXT NOT NULL,
              UpdatedAtUtc TEXT NOT NULL, CreatedBy TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS IX_PlannerItems_Status_Scheduled ON PlannerItems(Status, ScheduledAtUtc);
            """;
        command.ExecuteNonQuery();
    }

    private SqliteConnection Open() { var connection = new SqliteConnection(_connectionString); connection.Open(); return connection; }
    private static object Db(object? value) => value ?? DBNull.Value;
    private static DateTime ParseDate(string value) => DateTime.Parse(value, null, System.Globalization.DateTimeStyles.RoundtripKind).ToUniversalTime();
    private static PlannerItem Read(SqliteDataReader reader) => new(
        Guid.Parse(reader.GetString(0)), reader.IsDBNull(1) ? null : Guid.Parse(reader.GetString(1)), reader.GetString(2),
        reader.GetString(3), Enum.Parse<PlannerItemStatus>(reader.GetString(4), true), reader.IsDBNull(5) ? null : reader.GetString(5),
        reader.IsDBNull(6) ? null : reader.GetString(6), reader.IsDBNull(7) ? null : reader.GetString(7),
        reader.IsDBNull(8) ? null : ParseDate(reader.GetString(8)), reader.IsDBNull(9) ? null : reader.GetInt32(9),
        ParseDate(reader.GetString(10)), ParseDate(reader.GetString(11)), reader.GetString(12));
}

public sealed class NotificationInboxService
{
    private readonly object _sync = new();
    private readonly string _connectionString;
    public NotificationInboxService()
    {
        var directory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager", "Data");
        Directory.CreateDirectory(directory);
        _connectionString = new SqliteConnectionStringBuilder { DataSource = Path.Combine(directory, "notifications.db"), Mode = SqliteOpenMode.ReadWriteCreate, Cache = SqliteCacheMode.Shared }.ToString();
        using var connection = Open(); using var command = connection.CreateCommand();
        command.CommandText = "CREATE TABLE IF NOT EXISTS Notifications(Id TEXT PRIMARY KEY, UserId TEXT NOT NULL, Title TEXT NOT NULL, Message TEXT NOT NULL, Severity TEXT NOT NULL, RelatedId TEXT NULL, IsRead INTEGER NOT NULL DEFAULT 0, CreatedAtUtc TEXT NOT NULL); CREATE INDEX IF NOT EXISTS IX_Notifications_User_Read ON Notifications(UserId, IsRead, CreatedAtUtc);";
        command.ExecuteNonQuery();
    }
    public NotificationItem Create(string? userId, string title, string message, NotificationSeverity severity, Guid? relatedId = null)
    {
        var item = new NotificationItem(Guid.NewGuid(), string.IsNullOrWhiteSpace(userId) ? "System" : userId.Trim(), title.Trim(), message.Trim(), severity, relatedId, false, DateTime.UtcNow);
        lock (_sync) { using var c = Open(); using var q = c.CreateCommand(); q.CommandText = "INSERT INTO Notifications VALUES($id,$user,$title,$message,$severity,$related,0,$created)"; q.Parameters.AddWithValue("$id", item.Id.ToString()); q.Parameters.AddWithValue("$user", item.UserId); q.Parameters.AddWithValue("$title", item.Title); q.Parameters.AddWithValue("$message", item.Message); q.Parameters.AddWithValue("$severity", item.Severity.ToString()); q.Parameters.AddWithValue("$related", Db(item.RelatedId?.ToString())); q.Parameters.AddWithValue("$created", item.CreatedAtUtc.ToString("O")); q.ExecuteNonQuery(); }
        return item;
    }
    public IReadOnlyList<NotificationItem> Get(string? userId, bool unreadOnly = false, int take = 100)
    {
        lock (_sync) { using var c = Open(); using var q = c.CreateCommand(); q.CommandText = "SELECT Id,UserId,Title,Message,Severity,RelatedId,IsRead,CreatedAtUtc FROM Notifications WHERE UserId=$user AND ($unread=0 OR IsRead=0) ORDER BY CreatedAtUtc DESC LIMIT $take"; q.Parameters.AddWithValue("$user", string.IsNullOrWhiteSpace(userId) ? "System" : userId.Trim()); q.Parameters.AddWithValue("$unread", unreadOnly ? 1 : 0); q.Parameters.AddWithValue("$take", Math.Clamp(take,1,500)); using var r=q.ExecuteReader(); var list=new List<NotificationItem>(); while(r.Read()) list.Add(new NotificationItem(Guid.Parse(r.GetString(0)),r.GetString(1),r.GetString(2),r.GetString(3),Enum.Parse<NotificationSeverity>(r.GetString(4),true),r.IsDBNull(5)?null:Guid.Parse(r.GetString(5)),r.GetInt32(6)==1,DateTime.Parse(r.GetString(7)).ToUniversalTime())); return list; }
    }
    public void MarkRead(Guid id) { lock(_sync){ using var c=Open(); using var q=c.CreateCommand(); q.CommandText="UPDATE Notifications SET IsRead=1 WHERE Id=$id"; q.Parameters.AddWithValue("$id",id.ToString()); q.ExecuteNonQuery(); } }
    private SqliteConnection Open(){var c=new SqliteConnection(_connectionString);c.Open();return c;}
    private static object Db(object? value) => value ?? DBNull.Value;
}

public sealed record PlannerItem(Guid Id, Guid? SiteId, string SiteName, string Title, PlannerItemStatus Status, string? Idea, string? Brief, string? DraftContent, DateTime? ScheduledAtUtc, int? WordPressPostId, DateTime CreatedAtUtc, DateTime UpdatedAtUtc, string CreatedBy);
public sealed record CreatePlannerItem(Guid? SiteId, string? SiteName, string Title, string? Idea, DateTime? ScheduledAtUtc, string? CreatedBy);
public sealed record UpdatePlannerItem(string? Title, PlannerItemStatus? Status, string? Idea, string? Brief, string? DraftContent, DateTime? ScheduledAtUtc, int? WordPressPostId);
public sealed record NotificationItem(Guid Id, string UserId, string Title, string Message, NotificationSeverity Severity, Guid? RelatedId, bool IsRead, DateTime CreatedAtUtc);
