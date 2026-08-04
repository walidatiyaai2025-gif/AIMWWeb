using System.Collections.Concurrent;
using AIWordPressManager.Application.Abstractions;

namespace AIWordPressManager.Infrastructure.Jobs;

public sealed class JobCancellationRegistry : IJobCancellationRegistry
{
    private readonly ConcurrentDictionary<Guid, CancellationTokenSource> _tokens = new();

    public IDisposable Register(Guid jobId, CancellationTokenSource cancellationTokenSource)
    {
        _tokens[jobId] = cancellationTokenSource;
        return new Registration(_tokens, jobId, cancellationTokenSource);
    }

    public bool TryCancel(Guid jobId)
    {
        if (!_tokens.TryGetValue(jobId, out var source)) return false;
        if (!source.IsCancellationRequested) source.Cancel();
        return true;
    }

    public bool IsRegistered(Guid jobId) => _tokens.ContainsKey(jobId);

    private sealed class Registration(
        ConcurrentDictionary<Guid, CancellationTokenSource> tokens,
        Guid jobId,
        CancellationTokenSource source) : IDisposable
    {
        public void Dispose()
        {
            if (tokens.TryGetValue(jobId, out var current) && ReferenceEquals(current, source))
                tokens.TryRemove(jobId, out _);
        }
    }
}
