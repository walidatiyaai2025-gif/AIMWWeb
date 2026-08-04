using System.Threading.Channels;

namespace AIWordPressManager.Web.Services;

public sealed class BulkContentOperationQueue
{
    private readonly Channel<BulkContentOperationRequest> _channel = Channel.CreateUnbounded<BulkContentOperationRequest>(
        new UnboundedChannelOptions { SingleReader = true, SingleWriter = false });

    public ValueTask QueueAsync(BulkContentOperationRequest request, CancellationToken cancellationToken = default) =>
        _channel.Writer.WriteAsync(request, cancellationToken);

    public IAsyncEnumerable<BulkContentOperationRequest> ReadAllAsync(CancellationToken cancellationToken) =>
        _channel.Reader.ReadAllAsync(cancellationToken);
}

public sealed record BulkContentOperationRequest(
    Guid JobId,
    Guid SiteId,
    string SiteName,
    string TargetStatus,
    IReadOnlyList<BulkContentTarget> Targets,
    int RetryCount = 2)
{
    public int NormalizedRetryCount => Math.Clamp(RetryCount, 0, 5);
}

public sealed record BulkContentTarget(string ContentType, int WordPressId, string Title);
