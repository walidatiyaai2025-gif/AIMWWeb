using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Billing;

public sealed class SubscriptionLifecyclePolicyService(
    AppDbContext dbContext,
    IAccountSubscriptionService subscriptionService,
    ILogger<SubscriptionLifecyclePolicyService> logger) : ISubscriptionLifecyclePolicyService
{
    public async Task<SubscriptionLifecyclePolicyResult> EvaluateAsync(
        Guid subscriptionId,
        DateTime utcNow,
        CancellationToken cancellationToken = default)
    {
        if (subscriptionId == Guid.Empty) throw new ArgumentException("Subscription ID is required.", nameof(subscriptionId));
        if (utcNow.Kind != DateTimeKind.Utc) throw new ArgumentException("Evaluation timestamp must be UTC.", nameof(utcNow));

        var snapshot = await LoadSnapshotAsync(subscriptionId, cancellationToken)
            ?? throw new KeyNotFoundException("Account subscription was not found.");
        var decision = SubscriptionLifecyclePolicy.Evaluate(new(
            snapshot.Status,
            snapshot.TrialEndsAtUtc,
            snapshot.CurrentPeriodEndsAtUtc,
            snapshot.CancelAtPeriodEnd,
            snapshot.GraceUntilUtc,
            snapshot.GracePeriodDays), utcNow);

        if (!decision.RequiresTransition || !decision.TargetStatus.HasValue)
        {
            return new(
                subscriptionId,
                snapshot.Status,
                snapshot.Status,
                false,
                decision.Reason,
                snapshot.GraceUntilUtc);
        }

        var transitioned = await subscriptionService.TransitionAsync(subscriptionId, new(
            decision.TargetStatus.Value,
            SubscriptionTransitionSource.System,
            decision.Reason,
            utcNow,
            ProviderEventAtUtc: null,
            decision.GraceUntilUtc), cancellationToken);

        return new(
            subscriptionId,
            snapshot.Status,
            transitioned.Subscription.Status,
            transitioned.StatusChanged,
            decision.Reason,
            transitioned.Subscription.GraceUntilUtc);
    }

    public async Task<SubscriptionLifecycleBatchResult> EvaluateBatchAsync(
        DateTime utcNow,
        int take = 200,
        CancellationToken cancellationToken = default)
    {
        if (utcNow.Kind != DateTimeKind.Utc) throw new ArgumentException("Evaluation timestamp must be UTC.", nameof(utcNow));
        take = Math.Clamp(take, 1, 500);
        var ids = await dbContext.AccountSubscriptions.AsNoTracking()
            .Where(x => x.Status != AccountSubscriptionStatus.Expired)
            .OrderBy(x => x.UpdatedAtUtc)
            .ThenBy(x => x.Id)
            .Select(x => x.Id)
            .Take(take)
            .ToListAsync(cancellationToken);

        var changed = 0;
        var unchanged = 0;
        var failed = 0;
        foreach (var id in ids)
        {
            try
            {
                var result = await EvaluateAsync(id, utcNow, cancellationToken);
                if (result.StatusChanged) changed++; else unchanged++;
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                failed++;
                logger.LogWarning(ex, "Lifecycle policy evaluation failed for subscription {SubscriptionId}; later candidates will continue.", id);
            }
        }
        return new(ids.Count, changed, unchanged, failed);
    }

    private Task<PolicySnapshot?> LoadSnapshotAsync(Guid subscriptionId, CancellationToken cancellationToken) =>
        (from subscription in dbContext.AccountSubscriptions.AsNoTracking()
         join plan in dbContext.SubscriptionPlans.AsNoTracking() on subscription.PlanId equals plan.Id
         where subscription.Id == subscriptionId
         select new PolicySnapshot(
             subscription.Status,
             subscription.TrialEndsAtUtc,
             subscription.CurrentPeriodEndsAtUtc,
             subscription.CancelAtPeriodEnd,
             subscription.GraceUntilUtc,
             plan.GracePeriodDays))
        .SingleOrDefaultAsync(cancellationToken);

    private sealed record PolicySnapshot(
        AccountSubscriptionStatus Status,
        DateTime? TrialEndsAtUtc,
        DateTime? CurrentPeriodEndsAtUtc,
        bool CancelAtPeriodEnd,
        DateTime? GraceUntilUtc,
        int GracePeriodDays);
}

public sealed class SubscriptionLifecyclePolicyWorker(
    IServiceScopeFactory scopeFactory,
    ILogger<SubscriptionLifecyclePolicyWorker> logger) : BackgroundService
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromMinutes(1);

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                using var scope = scopeFactory.CreateScope();
                var service = scope.ServiceProvider.GetRequiredService<ISubscriptionLifecyclePolicyService>();
                var result = await service.EvaluateBatchAsync(DateTime.UtcNow, 200, stoppingToken);
                if (result.Changed > 0 || result.Failed > 0)
                    logger.LogInformation("Subscription lifecycle policy pass: scanned {Scanned}, changed {Changed}, unchanged {Unchanged}, failed {Failed}.", result.Scanned, result.Changed, result.Unchanged, result.Failed);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                logger.LogError(ex, "Subscription lifecycle policy pass failed and will be retried.");
            }

            try
            {
                await Task.Delay(PollInterval, stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
        }
    }
}
