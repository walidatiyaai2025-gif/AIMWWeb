using System.Collections.Concurrent;
using System.Text.Json;
using System.Threading.Channels;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Infrastructure.Jobs;

public enum BackgroundJobStatus
{
    Queued,
    Running,
    Succeeded,
    Failed,
    Cancelled
}

public sealed record BackgroundJobRequest(
    string Type,
    string Name,
    string PayloadJson,
    Guid? SiteId = null,
    string? RequestedBy = null,
    int Priority = 0,
    int MaximumAttempts = 3);

public sealed record BackgroundJobSnapshot(
    Guid Id,
    string Type,
    string Name,
    Guid? SiteId,
    string? RequestedBy,
    BackgroundJobStatus Status,
    int Progress,
    string? ProgressMessage,
    int Attempt,
    int MaximumAttempts,
    DateTime CreatedAtUtc,
    DateTime? StartedAtUtc,
    DateTime? CompletedAtUtc,
    string? ErrorMessage);

public sealed class BackgroundJobContext
{
    private readonly Action<int, string?> _reportProgress;

    internal BackgroundJobContext(
        Guid jobId,
        string payloadJson,
        Guid? siteId,
        string? requestedBy,
        IServiceProvider services,
        Action<int, string?> reportProgress)
    {
        JobId = jobId;
        PayloadJson = payloadJson;
        SiteId = siteId;
        RequestedBy = requestedBy;
        Services = services;
        _reportProgress = reportProgress;
    }

    public Guid JobId { get; }
    public string PayloadJson { get; }
    public Guid? SiteId { get; }
    public string? RequestedBy { get; }
    public IServiceProvider Services { get; }

    public TPayload? GetPayload<TPayload>(JsonSerializerOptions? options = null) =>
        JsonSerializer.Deserialize<TPayload>(PayloadJson, options);

    public void ReportProgress(int progress, string? message = null) =>
        _reportProgress(Math.Clamp(progress, 0, 100), message);
}

public interface IBackgroundJobHandler
{
    string JobType { get; }
    Task ExecuteAsync(BackgroundJobContext context, CancellationToken cancellationToken);
}

public interface IBackgroundJobQueue
{
    ValueTask<Guid> EnqueueAsync(BackgroundJobRequest request, CancellationToken cancellationToken = default);
    bool Cancel(Guid jobId);
    BackgroundJobSnapshot? Get(Guid jobId);
    IReadOnlyList<BackgroundJobSnapshot> GetRecent(int take = 100);
}

internal sealed record QueuedBackgroundJob(Guid Id, BackgroundJobRequest Request);

public sealed class InMemoryBackgroundJobQueue : IBackgroundJobQueue
{
    private readonly Channel<QueuedBackgroundJob> _channel;
    private readonly ConcurrentDictionary<Guid, JobState> _jobs = new();
    private readonly ConcurrentDictionary<Guid, CancellationTokenSource> _cancellations = new();

    public InMemoryBackgroundJobQueue()
    {
        _channel = Channel.CreateUnbounded<QueuedBackgroundJob>(new UnboundedChannelOptions
        {
            SingleReader = true,
            SingleWriter = false,
            AllowSynchronousContinuations = false
        });
    }

    internal ChannelReader<QueuedBackgroundJob> Reader => _channel.Reader;

    public async ValueTask<Guid> EnqueueAsync(BackgroundJobRequest request, CancellationToken cancellationToken = default)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(request.Type);
        ArgumentException.ThrowIfNullOrWhiteSpace(request.Name);

        var normalized = request with
        {
            Type = request.Type.Trim(),
            Name = request.Name.Trim(),
            PayloadJson = string.IsNullOrWhiteSpace(request.PayloadJson) ? "{}" : request.PayloadJson,
            MaximumAttempts = Math.Clamp(request.MaximumAttempts, 1, 10)
        };

        var id = Guid.NewGuid();
        _jobs[id] = new JobState(id, normalized);
        _cancellations[id] = new CancellationTokenSource();
        await _channel.Writer.WriteAsync(new QueuedBackgroundJob(id, normalized), cancellationToken);
        return id;
    }

    public bool Cancel(Guid jobId)
    {
        if (!_jobs.TryGetValue(jobId, out var state)) return false;
        if (state.Status is BackgroundJobStatus.Succeeded or BackgroundJobStatus.Failed or BackgroundJobStatus.Cancelled) return false;

        if (_cancellations.TryGetValue(jobId, out var source)) source.Cancel();
        state.MarkCancelled();
        return true;
    }

    public BackgroundJobSnapshot? Get(Guid jobId) =>
        _jobs.TryGetValue(jobId, out var state) ? state.ToSnapshot() : null;

    public IReadOnlyList<BackgroundJobSnapshot> GetRecent(int take = 100) =>
        _jobs.Values
            .OrderByDescending(x => x.CreatedAtUtc)
            .Take(Math.Clamp(take, 1, 1000))
            .Select(x => x.ToSnapshot())
            .ToArray();

    internal ValueTask RequeueAsync(QueuedBackgroundJob job, CancellationToken cancellationToken) =>
        _channel.Writer.WriteAsync(job, cancellationToken);

    internal CancellationToken GetCancellationToken(Guid jobId) =>
        _cancellations.TryGetValue(jobId, out var source) ? source.Token : CancellationToken.None;

    internal bool TryGetState(Guid jobId, out JobState state) =>
        _jobs.TryGetValue(jobId, out state!);

    internal void ReleaseCancellation(Guid jobId)
    {
        if (_cancellations.TryRemove(jobId, out var source)) source.Dispose();
    }

    internal sealed class JobState
    {
        private readonly object _sync = new();

        public JobState(Guid id, BackgroundJobRequest request)
        {
            Id = id;
            Request = request;
            Status = BackgroundJobStatus.Queued;
            CreatedAtUtc = DateTime.UtcNow;
        }

        public Guid Id { get; }
        public BackgroundJobRequest Request { get; }
        public BackgroundJobStatus Status { get; private set; }
        public int Progress { get; private set; }
        public string? ProgressMessage { get; private set; }
        public int Attempt { get; private set; }
        public DateTime CreatedAtUtc { get; }
        public DateTime? StartedAtUtc { get; private set; }
        public DateTime? CompletedAtUtc { get; private set; }
        public string? ErrorMessage { get; private set; }

        public void MarkRunning()
        {
            lock (_sync)
            {
                Attempt++;
                Status = BackgroundJobStatus.Running;
                StartedAtUtc ??= DateTime.UtcNow;
                CompletedAtUtc = null;
                ErrorMessage = null;
            }
        }

        public void ReportProgress(int progress, string? message)
        {
            lock (_sync)
            {
                Progress = Math.Clamp(progress, 0, 100);
                ProgressMessage = message;
            }
        }

        public void MarkSucceeded()
        {
            lock (_sync)
            {
                Status = BackgroundJobStatus.Succeeded;
                Progress = 100;
                CompletedAtUtc = DateTime.UtcNow;
            }
        }

        public void MarkFailed(Exception exception)
        {
            lock (_sync)
            {
                Status = BackgroundJobStatus.Failed;
                ErrorMessage = exception.Message;
                CompletedAtUtc = DateTime.UtcNow;
            }
        }

        public void MarkQueuedForRetry(Exception exception)
        {
            lock (_sync)
            {
                Status = BackgroundJobStatus.Queued;
                ErrorMessage = exception.Message;
                ProgressMessage = $"Retry {Attempt + 1} of {Request.MaximumAttempts}";
            }
        }

        public void MarkCancelled()
        {
            lock (_sync)
            {
                Status = BackgroundJobStatus.Cancelled;
                CompletedAtUtc = DateTime.UtcNow;
                ProgressMessage = "Cancelled";
            }
        }

        public BackgroundJobSnapshot ToSnapshot()
        {
            lock (_sync)
            {
                return new BackgroundJobSnapshot(
                    Id,
                    Request.Type,
                    Request.Name,
                    Request.SiteId,
                    Request.RequestedBy,
                    Status,
                    Progress,
                    ProgressMessage,
                    Attempt,
                    Request.MaximumAttempts,
                    CreatedAtUtc,
                    StartedAtUtc,
                    CompletedAtUtc,
                    ErrorMessage);
            }
        }
    }
}

public sealed class BackgroundJobDispatcher : BackgroundService
{
    private readonly InMemoryBackgroundJobQueue _queue;
    private readonly IServiceScopeFactory _scopeFactory;
    private readonly ILogger<BackgroundJobDispatcher> _logger;

    public BackgroundJobDispatcher(
        InMemoryBackgroundJobQueue queue,
        IServiceScopeFactory scopeFactory,
        ILogger<BackgroundJobDispatcher> logger)
    {
        _queue = queue;
        _scopeFactory = scopeFactory;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        await foreach (var queuedJob in _queue.Reader.ReadAllAsync(stoppingToken))
            await ExecuteJobAsync(queuedJob, stoppingToken);
    }

    private async Task ExecuteJobAsync(QueuedBackgroundJob queuedJob, CancellationToken stoppingToken)
    {
        if (!_queue.TryGetState(queuedJob.Id, out var state)) return;
        if (state.Status == BackgroundJobStatus.Cancelled)
        {
            _queue.ReleaseCancellation(queuedJob.Id);
            return;
        }

        using var linkedCancellation = CancellationTokenSource.CreateLinkedTokenSource(
            stoppingToken,
            _queue.GetCancellationToken(queuedJob.Id));

        try
        {
            state.MarkRunning();
            using var scope = _scopeFactory.CreateScope();
            var handler = scope.ServiceProvider
                .GetServices<IBackgroundJobHandler>()
                .FirstOrDefault(x => string.Equals(x.JobType, queuedJob.Request.Type, StringComparison.OrdinalIgnoreCase));

            if (handler is null)
                throw new InvalidOperationException($"No background job handler is registered for type '{queuedJob.Request.Type}'.");

            var context = new BackgroundJobContext(
                queuedJob.Id,
                queuedJob.Request.PayloadJson,
                queuedJob.Request.SiteId,
                queuedJob.Request.RequestedBy,
                scope.ServiceProvider,
                state.ReportProgress);

            await handler.ExecuteAsync(context, linkedCancellation.Token);
            linkedCancellation.Token.ThrowIfCancellationRequested();
            state.MarkSucceeded();
        }
        catch (OperationCanceledException) when (linkedCancellation.IsCancellationRequested)
        {
            state.MarkCancelled();
        }
        catch (Exception exception)
        {
            _logger.LogError(
                exception,
                "Background job {JobId} ({JobType}) failed on attempt {Attempt}.",
                queuedJob.Id,
                queuedJob.Request.Type,
                state.Attempt);

            if (state.Attempt < queuedJob.Request.MaximumAttempts && !stoppingToken.IsCancellationRequested)
            {
                state.MarkQueuedForRetry(exception);
                var delay = TimeSpan.FromSeconds(Math.Min(30, Math.Pow(2, state.Attempt)));
                await Task.Delay(delay, stoppingToken);
                await _queue.RequeueAsync(queuedJob, stoppingToken);
                return;
            }

            state.MarkFailed(exception);
        }
        finally
        {
            if (state.Status is BackgroundJobStatus.Succeeded or BackgroundJobStatus.Failed or BackgroundJobStatus.Cancelled)
                _queue.ReleaseCancellation(queuedJob.Id);
        }
    }
}
