using System.Net;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// The one authoritative originating runtime for tracked Content Planner publish jobs.
/// Queue authorization happens in ContentPlannerService; execution rebinds only the persisted
/// server-owned owner/site/item identity and never accepts client-supplied ownership.
/// </summary>
public sealed class PlannerPublishWorker(
    IServiceScopeFactory scopeFactory,
    ExecutionCenterService executionCenter,
    ExecutionOperationTracker executionTracker,
    ILogger<PlannerPublishWorker> logger) : BackgroundService
{
    public const string JobType = "Planner Publish";
    public const int TotalSteps = 4;
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(2);

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var recovered = executionTracker.RecoverInterruptedTracked(JobType);
        if (recovered > 0)
            logger.LogWarning("Recovered {Count} interrupted Planner Publish jobs for idempotent reconciliation.", recovered);

        using var timer = new PeriodicTimer(PollInterval);
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                await ProcessPendingOnceAsync(stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                logger.LogError(ex, "Planner Publish worker polling cycle failed.");
            }

            try
            {
                if (!await timer.WaitForNextTickAsync(stoppingToken)) break;
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
        }
    }

    public async Task<int> ProcessPendingOnceAsync(CancellationToken cancellationToken = default)
    {
        var pending = executionCenter.GetJobs()
            .Where(job =>
                string.Equals(job.ExecutionMode, ExecutionCenterService.TrackedExecutionMode, StringComparison.Ordinal) &&
                string.Equals(job.Type, JobType, StringComparison.Ordinal) &&
                string.Equals(job.Status, "Waiting", StringComparison.Ordinal))
            .OrderBy(job => job.CreatedAtUtc)
            .Take(20)
            .ToArray();

        var processed = 0;
        foreach (var job in pending)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (!job.OwnerUserId.HasValue || job.OwnerUserId.Value == Guid.Empty ||
                !job.SiteId.HasValue || job.SiteId.Value == Guid.Empty ||
                !Guid.TryParse(job.CorrelationId, out var plannerItemId) ||
                string.IsNullOrWhiteSpace(job.IdempotencyKey))
            {
                executionTracker.Fail(job.Id, "Planner Publish job is missing its server-owned owner/site/item execution identity.");
                processed++;
                continue;
            }

            var ownerUserId = job.OwnerUserId.Value;
            if (!executionTracker.TryStartTracked(
                    job.Id,
                    ownerUserId,
                    JobType,
                    "Planner Publish worker claimed the queued revision."))
            {
                continue;
            }

            processed++;
            await ProcessClaimedAsync(job, ownerUserId, job.SiteId.Value, plannerItemId, cancellationToken);
        }

        return processed;
    }

    private async Task ProcessClaimedAsync(
        ExecutionJob job,
        Guid ownerUserId,
        Guid siteId,
        Guid plannerItemId,
        CancellationToken cancellationToken)
    {
        try
        {
            await using var scope = scopeFactory.CreateAsyncScope();
            using var ownerLease = BackgroundExecutionIdentity.Push(ownerUserId);
            using var mutationLease = BackgroundContentMutationAuthorization.Push();

            var planner = scope.ServiceProvider.GetRequiredService<ContentPlannerService>();
            var apiClient = scope.ServiceProvider.GetRequiredService<IWordPressApiClient>();
            var sync = scope.ServiceProvider.GetRequiredService<WordPressSyncWebService>();
            var securityAudit = scope.ServiceProvider.GetRequiredService<ApplicationSecurityAuditService>();

            var item = await planner.GetForExecutionAsync(
                ownerUserId,
                plannerItemId,
                siteId,
                job.IdempotencyKey!,
                cancellationToken);
            executionTracker.ReportTracked(
                job.Id, ownerUserId, JobType, 1, TotalSteps,
                "Validated the owned planner revision and WordPress site.");

            var desiredStatus = DesiredWordPressStatus(item);
            var remote = await EnsureRemotePostAsync(apiClient, siteId, item, desiredStatus, cancellationToken);
            executionTracker.ReportTracked(
                job.Id, ownerUserId, JobType, 2, TotalSteps,
                remote.Mutated
                    ? $"WordPress accepted post #{remote.PostId} with status '{remote.Status}'."
                    : $"WordPress post #{remote.PostId} already matches the queued revision; duplicate mutation skipped.");

            await sync.SynchronizeAsync(siteId, cancellationToken, forceFullRefresh: true);
            executionTracker.ReportTracked(
                job.Id, ownerUserId, JobType, 3, TotalSteps,
                "Forced WordPress synchronization reconciled the local content cache.");

            var plannerStatus = string.Equals(desiredStatus, "future", StringComparison.Ordinal)
                ? PlannerItemStatus.Scheduled
                : PlannerItemStatus.Published;
            var reconciled = await planner.ReconcilePublishAsync(
                ownerUserId,
                plannerItemId,
                siteId,
                remote.PostId,
                plannerStatus,
                cancellationToken);

            await securityAudit.RecordCurrentAsync(
                "ContentPlanner",
                "Publish",
                "Succeeded",
                "PlannerItem",
                reconciled.Id.ToString("D"),
                reconciled.Title,
                new Dictionary<string, string>(StringComparer.Ordinal)
                {
                    ["executionJobId"] = job.Id.ToString("D"),
                    ["siteId"] = siteId.ToString("D"),
                    ["wordPressPostId"] = remote.PostId.ToString(System.Globalization.CultureInfo.InvariantCulture),
                    ["wordPressStatus"] = remote.Status,
                    ["remoteMutationPerformed"] = remote.Mutated ? "true" : "false",
                    ["reconciled"] = "true"
                },
                cancellationToken);

            executionTracker.ReportTracked(
                job.Id, ownerUserId, JobType, 4, TotalSteps,
                "Planner state and security audit were durably reconciled.");
            executionTracker.CompleteTracked(
                job.Id,
                ownerUserId,
                JobType,
                TotalSteps,
                TotalSteps,
                plannerStatus == PlannerItemStatus.Scheduled
                    ? $"Planner post #{remote.PostId} is scheduled in WordPress and reconciled locally."
                    : $"Planner post #{remote.PostId} is published in WordPress and reconciled locally.");
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            TryFail(job.Id, ownerUserId, "Planner Publish execution was interrupted before terminal reconciliation.");
            throw;
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Planner Publish job {JobId} failed for owner {OwnerUserId}, site {SiteId}.", job.Id, ownerUserId, siteId);
            TryFail(job.Id, ownerUserId, SafeFailureMessage(ex));
        }
    }

    private void TryFail(Guid jobId, Guid ownerUserId, string message)
    {
        try
        {
            executionTracker.FailTracked(jobId, ownerUserId, JobType, message);
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Could not persist Planner Publish failure for job {JobId}.", jobId);
        }
    }

    private static async Task<RemotePublishResult> EnsureRemotePostAsync(
        IWordPressApiClient apiClient,
        Guid siteId,
        PlannerItem item,
        string desiredStatus,
        CancellationToken cancellationToken)
    {
        RemotePost? remote = null;
        if (item.WordPressPostId is > 0)
            remote = await GetPostAsync(apiClient, siteId, item.WordPressPostId.Value, cancellationToken, allowNotFound: true);

        if (remote is null)
            remote = await FindPlannerPostBySlugAsync(apiClient, siteId, PlannerSlug(item.Id), cancellationToken);

        if (remote is null)
            return await CreatePostAsync(apiClient, siteId, item, desiredStatus, cancellationToken);

        if (MatchesQueuedRevision(remote, item, desiredStatus))
            return new RemotePublishResult(remote.Id, remote.Status, false);

        var payload = BuildPayload(item, desiredStatus, includeSlug: false);
        var response = await apiClient.SendAsync(
            siteId,
            HttpMethod.Post,
            $"/wp-json/wp/v2/posts/{remote.Id}",
            payload,
            cancellationToken);
        if (!response.IsSuccess || response.Value is null)
            throw new InvalidOperationException(WordPressFailure("update", response));

        using var json = response.Value;
        var id = GetInt(json.RootElement, "id");
        if (id <= 0) throw new InvalidOperationException("WordPress update succeeded without a valid post ID.");
        return new RemotePublishResult(id, GetString(json.RootElement, "status"), true);
    }

    private static async Task<RemotePublishResult> CreatePostAsync(
        IWordPressApiClient apiClient,
        Guid siteId,
        PlannerItem item,
        string desiredStatus,
        CancellationToken cancellationToken)
    {
        var response = await apiClient.SendAsync(
            siteId,
            HttpMethod.Post,
            "/wp-json/wp/v2/posts",
            BuildPayload(item, desiredStatus, includeSlug: true),
            cancellationToken);
        if (!response.IsSuccess || response.Value is null)
            throw new InvalidOperationException(WordPressFailure("create", response));

        using var json = response.Value;
        var id = GetInt(json.RootElement, "id");
        if (id <= 0) throw new InvalidOperationException("WordPress create succeeded without a valid post ID.");
        return new RemotePublishResult(id, GetString(json.RootElement, "status"), true);
    }

    private static async Task<RemotePost?> FindPlannerPostBySlugAsync(
        IWordPressApiClient apiClient,
        Guid siteId,
        string slug,
        CancellationToken cancellationToken)
    {
        var escaped = Uri.EscapeDataString(slug);
        var endpoint = $"/wp-json/wp/v2/posts?context=edit&slug={escaped}&status[]=publish&status[]=future&status[]=draft&status[]=pending&status[]=private&per_page=1";
        var response = await apiClient.GetAsync(siteId, endpoint, cancellationToken);
        if (!response.IsSuccess || response.Value is null)
            throw new InvalidOperationException(WordPressFailure("lookup", response));

        using var json = response.Value;
        if (json.RootElement.ValueKind != JsonValueKind.Array)
            throw new InvalidOperationException("WordPress planner lookup returned an unexpected response shape.");
        var first = json.RootElement.EnumerateArray().FirstOrDefault();
        return first.ValueKind == JsonValueKind.Undefined ? null : ReadRemote(first);
    }

    private static async Task<RemotePost?> GetPostAsync(
        IWordPressApiClient apiClient,
        Guid siteId,
        int postId,
        CancellationToken cancellationToken,
        bool allowNotFound)
    {
        var response = await apiClient.GetAsync(siteId, $"/wp-json/wp/v2/posts/{postId}?context=edit", cancellationToken);
        if (!response.IsSuccess || response.Value is null)
        {
            if (allowNotFound && response.StatusCode == HttpStatusCode.NotFound) return null;
            throw new InvalidOperationException(WordPressFailure("read", response));
        }

        using var json = response.Value;
        return ReadRemote(json.RootElement);
    }

    private static Dictionary<string, object?> BuildPayload(PlannerItem item, string desiredStatus, bool includeSlug)
    {
        var payload = new Dictionary<string, object?>
        {
            ["title"] = item.Title.Trim(),
            ["content"] = item.DraftContent ?? string.Empty,
            ["status"] = desiredStatus
        };
        if (includeSlug) payload["slug"] = PlannerSlug(item.Id);
        if (string.Equals(desiredStatus, "future", StringComparison.Ordinal) && item.ScheduledAtUtc.HasValue)
            payload["date_gmt"] = item.ScheduledAtUtc.Value.ToUniversalTime().ToString("O");
        return payload;
    }

    private static bool MatchesQueuedRevision(RemotePost remote, PlannerItem item, string desiredStatus)
    {
        if (!string.Equals(remote.Title, item.Title.Trim(), StringComparison.Ordinal) ||
            !string.Equals(remote.Content, item.DraftContent ?? string.Empty, StringComparison.Ordinal) ||
            !string.Equals(remote.Status, desiredStatus, StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        if (!string.Equals(desiredStatus, "future", StringComparison.Ordinal)) return true;
        if (!item.ScheduledAtUtc.HasValue || !remote.DateGmt.HasValue) return false;
        return Math.Abs((remote.DateGmt.Value.UtcDateTime - item.ScheduledAtUtc.Value.ToUniversalTime()).TotalSeconds) < 1;
    }

    private static string DesiredWordPressStatus(PlannerItem item) =>
        item.ScheduledAtUtc.HasValue && item.ScheduledAtUtc.Value.ToUniversalTime() > DateTime.UtcNow
            ? "future"
            : "publish";

    private static string PlannerSlug(Guid itemId) => $"aiwm-planner-{itemId:N}";

    private static RemotePost ReadRemote(JsonElement item) => new(
        GetInt(item, "id"),
        GetRaw(item, "title"),
        GetRaw(item, "content"),
        GetString(item, "status"),
        GetDate(item, "date_gmt"));

    private static int GetInt(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.TryGetInt32(out var parsed) ? parsed : 0;

    private static string GetString(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String
            ? value.GetString() ?? string.Empty
            : string.Empty;

    private static string GetRaw(JsonElement item, string name) =>
        item.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.Object &&
        value.TryGetProperty("raw", out var raw)
            ? raw.GetString() ?? string.Empty
            : string.Empty;

    private static DateTimeOffset? GetDate(JsonElement item, string name) =>
        DateTimeOffset.TryParse(GetString(item, name), out var parsed) ? parsed : null;

    private static string WordPressFailure(string operation, WordPressApiResponse<JsonDocument> response) =>
        string.IsNullOrWhiteSpace(response.ErrorMessage)
            ? $"WordPress {operation} failed with HTTP {(int)response.StatusCode}."
            : response.ErrorMessage;

    private static string SafeFailureMessage(Exception ex)
    {
        var message = ex is UnauthorizedAccessException
            ? "Planner Publish authorization or ownership validation failed."
            : ex.Message;
        if (string.IsNullOrWhiteSpace(message)) message = "Planner Publish execution failed.";
        message = message.Replace('\r', ' ').Replace('\n', ' ').Trim();
        return message.Length <= 600 ? message : message[..600];
    }

    private sealed record RemotePost(int Id, string Title, string Content, string Status, DateTimeOffset? DateGmt);
    private sealed record RemotePublishResult(int PostId, string Status, bool Mutated);
}