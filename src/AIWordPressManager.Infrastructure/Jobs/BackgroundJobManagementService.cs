using System.Text.Json;

namespace AIWordPressManager.Infrastructure.Jobs;

public sealed class BackgroundJobManagementService
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    private readonly IBackgroundJobQueue _queue;

    public BackgroundJobManagementService(IBackgroundJobQueue queue)
    {
        _queue = queue;
    }

    public ValueTask<Guid> EnqueueAsync<TPayload>(
        string type,
        string name,
        TPayload payload,
        Guid? siteId = null,
        string? requestedBy = null,
        int priority = 0,
        int maximumAttempts = 3,
        CancellationToken cancellationToken = default)
    {
        var payloadJson = JsonSerializer.Serialize(payload, JsonOptions);
        var request = new BackgroundJobRequest(
            type,
            name,
            payloadJson,
            siteId,
            requestedBy,
            priority,
            maximumAttempts);

        return _queue.EnqueueAsync(request, cancellationToken);
    }

    public ValueTask<Guid> EnqueueSystemDelayAsync(
        int durationSeconds = 5,
        string? message = null,
        string? requestedBy = null,
        CancellationToken cancellationToken = default) =>
        EnqueueAsync(
            SystemDelayJobHandler.TypeName,
            "Infrastructure engine test",
            new SystemDelayJobPayload(durationSeconds, message),
            requestedBy: requestedBy,
            maximumAttempts: 1,
            cancellationToken: cancellationToken);

    public BackgroundJobSnapshot? Get(Guid jobId) => _queue.Get(jobId);

    public IReadOnlyList<BackgroundJobSnapshot> GetRecent(int take = 100) =>
        _queue.GetRecent(take);

    public bool Cancel(Guid jobId) => _queue.Cancel(jobId);
}
