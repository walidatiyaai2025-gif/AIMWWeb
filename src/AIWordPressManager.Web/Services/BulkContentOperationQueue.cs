using System.Threading.Channels;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Http;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class BulkContentOperationQueue(
    IHttpContextAccessor httpContextAccessor,
    IServiceScopeFactory scopeFactory,
    ExecutionOperationTracker tracker)
{
    private readonly Channel<BulkContentOperationRequest> _channel = Channel.CreateUnbounded<BulkContentOperationRequest>(
        new UnboundedChannelOptions { SingleReader = true, SingleWriter = false });

    public async ValueTask QueueAsync(BulkContentOperationRequest request, CancellationToken cancellationToken = default)
    {
        var currentUser = new CurrentUserContext(httpContextAccessor);
        currentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);
        var ownerUserId = currentUser.RequireUserId();

        using var scope = scopeFactory.CreateScope();
        var dbContext = scope.ServiceProvider.GetRequiredService<AppDbContext>();
        var ownsSite = await dbContext.Sites
            .AsNoTracking()
            .AnyAsync(
                x => x.Id == request.SiteId && x.OwnerUserId == ownerUserId && !x.IsDeleted,
                cancellationToken);

        if (!ownsSite)
            throw new UnauthorizedAccessException("The selected site does not belong to the current user.");

        tracker.BindOwner(request.JobId, ownerUserId, request.SiteId);
        await _channel.Writer.WriteAsync(request with { OwnerUserId = ownerUserId }, cancellationToken);
    }

    public IAsyncEnumerable<BulkContentOperationRequest> ReadAllAsync(CancellationToken cancellationToken) =>
        _channel.Reader.ReadAllAsync(cancellationToken);
}

public sealed record BulkContentOperationRequest(
    Guid JobId,
    Guid SiteId,
    string SiteName,
    string TargetStatus,
    IReadOnlyList<BulkContentTarget> Targets,
    int RetryCount = 2,
    Guid OwnerUserId = default)
{
    public int NormalizedRetryCount => Math.Clamp(RetryCount, 0, 5);
}

public sealed record BulkContentTarget(string ContentType, int WordPressId, string Title);