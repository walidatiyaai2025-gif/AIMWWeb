using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Billing;

public sealed class PayPalSubscriptionSynchronizationService(
    AppDbContext dbContext,
    IPaymentGatewayRegistry paymentGatewayRegistry,
    IAccountSubscriptionService subscriptionService) : IPayPalSubscriptionSynchronizationService
{
    private const string ProviderKey = "paypal";
    private static readonly TimeSpan ClaimDuration = TimeSpan.FromMinutes(5);

    public async Task<PayPalSubscriptionSyncBatchResult> ProcessVerifiedEventsAsync(
        DateTime utcNow,
        int take = 100,
        CancellationToken cancellationToken = default)
    {
        RequireUtc(utcNow, nameof(utcNow));
        take = Math.Clamp(take, 1, 500);
        await SeedMissingProcessingStatesAsync(utcNow, take, cancellationToken);

        var candidateIds = await dbContext.Set<PayPalWebhookProcessingState>().AsNoTracking()
            .Where(x =>
                x.Status == PayPalWebhookProcessingStatus.Pending ||
                (x.Status == PayPalWebhookProcessingStatus.Failed &&
                 (!x.NextAttemptAtUtc.HasValue || x.NextAttemptAtUtc <= utcNow)) ||
                (x.Status == PayPalWebhookProcessingStatus.Processing &&
                 x.ClaimUntilUtc.HasValue && x.ClaimUntilUtc <= utcNow))
            .OrderBy(x => x.NextAttemptAtUtc ?? x.CreatedAtUtc)
            .ThenBy(x => x.CreatedAtUtc)
            .Select(x => x.Id)
            .Take(take)
            .ToListAsync(cancellationToken);

        var scanned = 0;
        var changed = 0;
        var unchanged = 0;
        var ignored = 0;
        var failed = 0;

        foreach (var stateId in candidateIds)
        {
            cancellationToken.ThrowIfCancellationRequested();
            var claimToken = Guid.NewGuid().ToString("N");
            var claimUntil = utcNow.Add(ClaimDuration);
            var newConcurrencyToken = Guid.NewGuid().ToByteArray();

            var claimed = await dbContext.Set<PayPalWebhookProcessingState>()
                .Where(x =>
                    x.Id == stateId &&
                    (x.Status == PayPalWebhookProcessingStatus.Pending ||
                     (x.Status == PayPalWebhookProcessingStatus.Failed &&
                      (!x.NextAttemptAtUtc.HasValue || x.NextAttemptAtUtc <= utcNow)) ||
                     (x.Status == PayPalWebhookProcessingStatus.Processing &&
                      x.ClaimUntilUtc.HasValue && x.ClaimUntilUtc <= utcNow)))
                .ExecuteUpdateAsync(setters => setters
                    .SetProperty(x => x.Status, PayPalWebhookProcessingStatus.Processing)
                    .SetProperty(x => x.ClaimToken, claimToken)
                    .SetProperty(x => x.ClaimUntilUtc, claimUntil)
                    .SetProperty(x => x.AttemptCount, x => x.AttemptCount + 1)
                    .SetProperty(x => x.NextAttemptAtUtc, (DateTime?)null)
                    .SetProperty(x => x.LastError, (string?)null)
                    .SetProperty(x => x.UpdatedAtUtc, utcNow)
                    .SetProperty(x => x.ConcurrencyToken, newConcurrencyToken),
                    cancellationToken);

            if (claimed == 0) continue;
            scanned++;

            var claimedState = await dbContext.Set<PayPalWebhookProcessingState>().AsNoTracking()
                .SingleAsync(x => x.Id == stateId, cancellationToken);
            var inboxEvent = await dbContext.Set<PayPalWebhookInboxEvent>().AsNoTracking()
                .SingleAsync(x => x.Id == claimedState.InboxEventId, cancellationToken);

            var triggerState = Enum.TryParse<GatewaySubscriptionState>(inboxEvent.NormalizedState, true, out var parsedState)
                ? parsedState
                : GatewaySubscriptionState.Unknown;
            var trigger = new VerifiedTrigger(
                inboxEvent.ProviderEventId,
                inboxEvent.EventType,
                inboxEvent.ProviderSubscriptionReference,
                triggerState,
                NormalizeUtc(inboxEvent.OccurredAtUtc));

            try
            {
                var outcome = await SynchronizeReferenceAsync(
                    inboxEvent.ProviderSubscriptionReference,
                    trigger,
                    utcNow,
                    cancellationToken);

                await MarkProcessedAsync(stateId, claimToken, utcNow, cancellationToken);
                switch (outcome)
                {
                    case SyncOutcome.Changed: changed++; break;
                    case SyncOutcome.Unchanged: unchanged++; break;
                    default: ignored++; break;
                }
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                failed++;
                await MarkFailedAsync(
                    stateId,
                    claimToken,
                    claimedState.AttemptCount,
                    ex,
                    utcNow,
                    cancellationToken);
            }
        }

        return new(scanned, changed, unchanged, ignored, failed);
    }

    public async Task<PayPalSubscriptionSyncBatchResult> ReconcileBoundSubscriptionsAsync(
        DateTime utcNow,
        int take = 100,
        CancellationToken cancellationToken = default)
    {
        RequireUtc(utcNow, nameof(utcNow));
        take = Math.Clamp(take, 1, 500);

        var references = await dbContext.AccountSubscriptions.AsNoTracking()
            .Where(x =>
                x.ProviderKey == ProviderKey &&
                x.ProviderSubscriptionReference != null &&
                x.ProviderSubscriptionReference != string.Empty &&
                x.Status != AccountSubscriptionStatus.Expired)
            .OrderBy(x => x.LastProviderEventAtUtc ?? x.CreatedAtUtc)
            .ThenBy(x => x.CreatedAtUtc)
            .Select(x => x.ProviderSubscriptionReference!)
            .Take(take)
            .ToListAsync(cancellationToken);

        var scanned = 0;
        var changed = 0;
        var unchanged = 0;
        var ignored = 0;
        var failed = 0;

        foreach (var reference in references)
        {
            cancellationToken.ThrowIfCancellationRequested();
            scanned++;
            try
            {
                var outcome = await SynchronizeReferenceAsync(reference, null, utcNow, cancellationToken);
                switch (outcome)
                {
                    case SyncOutcome.Changed: changed++; break;
                    case SyncOutcome.Unchanged: unchanged++; break;
                    default: ignored++; break;
                }
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch
            {
                failed++;
            }
        }

        return new(scanned, changed, unchanged, ignored, failed);
    }

    private async Task<SyncOutcome> SynchronizeReferenceAsync(
        string providerSubscriptionReference,
        VerifiedTrigger? trigger,
        DateTime utcNow,
        CancellationToken cancellationToken)
    {
        var cleanReference = (providerSubscriptionReference ?? string.Empty).Trim();
        if (cleanReference.Length == 0 || cleanReference.Length > 200)
            return SyncOutcome.Ignored;

        var rawLocalRows = await dbContext.AccountSubscriptions.AsNoTracking()
            .Where(x => x.ProviderKey == ProviderKey && x.ProviderSubscriptionReference == cleanReference)
            .OrderBy(x => x.CreatedAtUtc)
            .Select(x => new
            {
                x.Id,
                x.PlanId,
                x.Status,
                x.TrialStartedAtUtc,
                x.TrialEndsAtUtc,
                x.CurrentPeriodStartUtc,
                x.CurrentPeriodEndsAtUtc,
                x.LastProviderEventAtUtc
            })
            .Take(2)
            .ToListAsync(cancellationToken);

        var localRows = rawLocalRows
            .Select(x => new LocalSubscription(
                x.Id,
                x.PlanId,
                x.Status,
                NormalizeNullableUtc(x.TrialStartedAtUtc),
                NormalizeNullableUtc(x.TrialEndsAtUtc),
                NormalizeNullableUtc(x.CurrentPeriodStartUtc),
                NormalizeNullableUtc(x.CurrentPeriodEndsAtUtc),
                NormalizeNullableUtc(x.LastProviderEventAtUtc)))
            .ToList();

        if (localRows.Count == 0) return SyncOutcome.Ignored;
        if (localRows.Count > 1)
            throw new InvalidOperationException("PayPal subscription reference is bound to more than one account subscription.");

        var local = localRows[0];
        if (local.Status == AccountSubscriptionStatus.Expired)
            return SyncOutcome.Unchanged;
        if (trigger is not null && local.LastProviderEventAtUtc.HasValue &&
            trigger.OccurredAtUtc <= local.LastProviderEventAtUtc.Value)
            return SyncOutcome.Unchanged;

        var gateway = paymentGatewayRegistry.GetRequired(ProviderKey, PaymentGatewayCapability.SubscriptionLookup);
        var snapshot = await gateway.GetSubscriptionAsync(cleanReference, cancellationToken);
        if (!string.Equals(snapshot.ProviderSubscriptionReference, cleanReference, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("PayPal subscription snapshot reference did not match the bound subscription.");

        var providerObservationAt = trigger?.OccurredAtUtc ?? snapshot.ObservedAtUtc;
        if (local.LastProviderEventAtUtc.HasValue && providerObservationAt <= local.LastProviderEventAtUtc.Value)
            return SyncOutcome.Unchanged;

        Guid? authoritativePlanId = null;
        if (!string.IsNullOrWhiteSpace(snapshot.ProviderPlanReference))
            authoritativePlanId = await ResolveProviderPlanIdAsync(snapshot.ProviderPlanReference, cancellationToken);

        var target = ResolveTargetStatus(local, snapshot, trigger);
        var targetStatus = target ?? local.Status;
        if (targetStatus != local.Status && !AccountSubscriptionStateMachine.CanTransition(local.Status, targetStatus))
            targetStatus = local.Status;

        var reason = trigger is null
            ? "PayPal subscription reconciled from authoritative provider snapshot."
            : $"Verified PayPal event '{trigger.EventType}' reconciled from authoritative provider snapshot.";

        await using var transaction = await dbContext.Database.BeginTransactionAsync(cancellationToken);
        var changed = false;
        if (authoritativePlanId.HasValue && authoritativePlanId.Value != local.PlanId)
        {
            var planChange = await subscriptionService.ChangePlanAsync(local.Id, new(
                authoritativePlanId.Value,
                SubscriptionTransitionSource.Provider,
                reason,
                utcNow,
                providerObservationAt), cancellationToken);
            changed = planChange.PlanChanged;
        }

        var transition = await subscriptionService.TransitionAsync(local.Id, new(
            targetStatus,
            SubscriptionTransitionSource.Provider,
            reason,
            utcNow,
            providerObservationAt), cancellationToken);

        changed |= transition.StatusChanged;
        if (snapshot.CurrentPeriodStartUtc.HasValue && snapshot.CurrentPeriodEndsAtUtc.HasValue)
        {
            var periodStart = snapshot.CurrentPeriodStartUtc.Value;
            var periodEnd = snapshot.CurrentPeriodEndsAtUtc.Value;
            if (local.CurrentPeriodStartUtc != periodStart || local.CurrentPeriodEndsAtUtc != periodEnd)
            {
                await subscriptionService.UpdatePeriodsAsync(local.Id, new(
                    local.TrialStartedAtUtc,
                    local.TrialEndsAtUtc,
                    periodStart,
                    periodEnd), cancellationToken);
                changed = true;
            }
        }

        await transaction.CommitAsync(cancellationToken);
        return changed ? SyncOutcome.Changed : SyncOutcome.Unchanged;
    }

    private async Task<Guid> ResolveProviderPlanIdAsync(string providerPlanReference, CancellationToken cancellationToken)
    {
        var clean = providerPlanReference.Trim();
        var planIds = await dbContext.SubscriptionPlans.AsNoTracking()
            .Where(x => x.GatewayPlanId == clean)
            .Select(x => x.Id)
            .Take(2)
            .ToListAsync(cancellationToken);

        if (planIds.Count == 0)
            throw new InvalidOperationException("PayPal subscription snapshot references a plan that is not mapped in the local plan catalog.");
        if (planIds.Count > 1)
            throw new InvalidOperationException("PayPal plan mapping is ambiguous in the local plan catalog.");
        return planIds[0];
    }

    private static AccountSubscriptionStatus? ResolveTargetStatus(
        LocalSubscription local,
        GatewaySubscriptionSnapshot snapshot,
        VerifiedTrigger? trigger)
    {
        if (snapshot.State == GatewaySubscriptionState.Suspended)
            return AccountSubscriptionStatus.Suspended;
        if (snapshot.State == GatewaySubscriptionState.Cancelled)
            return AccountSubscriptionStatus.Cancelled;
        if (snapshot.State == GatewaySubscriptionState.Expired)
            return AccountSubscriptionStatus.Expired;

        if (trigger?.State == GatewaySubscriptionState.PastDue && snapshot.State == GatewaySubscriptionState.Active)
        {
            var recoveredAfterFailure = snapshot.CurrentPeriodStartUtc.HasValue &&
                                        snapshot.CurrentPeriodStartUtc.Value > trigger.OccurredAtUtc;
            return recoveredAfterFailure ? AccountSubscriptionStatus.Active : AccountSubscriptionStatus.PastDue;
        }

        if (snapshot.State == GatewaySubscriptionState.Active &&
            local.Status is AccountSubscriptionStatus.PastDue or AccountSubscriptionStatus.Grace)
        {
            var hasNewerPayment = snapshot.CurrentPeriodStartUtc.HasValue &&
                                  local.LastProviderEventAtUtc.HasValue &&
                                  snapshot.CurrentPeriodStartUtc.Value > local.LastProviderEventAtUtc.Value;
            return hasNewerPayment ? AccountSubscriptionStatus.Active : local.Status;
        }

        return snapshot.State switch
        {
            GatewaySubscriptionState.Active => AccountSubscriptionStatus.Active,
            GatewaySubscriptionState.PastDue => AccountSubscriptionStatus.PastDue,
            _ => null
        };
    }

    private async Task SeedMissingProcessingStatesAsync(
        DateTime utcNow,
        int take,
        CancellationToken cancellationToken)
    {
        var missingEventIds = await dbContext.Set<PayPalWebhookInboxEvent>().AsNoTracking()
            .Where(webhook => !dbContext.Set<PayPalWebhookProcessingState>().Any(state => state.InboxEventId == webhook.Id))
            .OrderBy(x => x.ReceivedAtUtc)
            .Select(x => x.Id)
            .Take(take)
            .ToListAsync(cancellationToken);

        foreach (var eventId in missingEventIds)
        {
            if (await dbContext.Set<PayPalWebhookProcessingState>().AsNoTracking()
                    .AnyAsync(x => x.InboxEventId == eventId, cancellationToken))
                continue;

            var state = new PayPalWebhookProcessingState(eventId, utcNow);
            dbContext.Set<PayPalWebhookProcessingState>().Add(state);
            try
            {
                await dbContext.SaveChangesAsync(cancellationToken);
                dbContext.Entry(state).State = EntityState.Detached;
            }
            catch (DbUpdateException)
            {
                dbContext.Entry(state).State = EntityState.Detached;
                if (!await dbContext.Set<PayPalWebhookProcessingState>().AsNoTracking()
                        .AnyAsync(x => x.InboxEventId == eventId, cancellationToken))
                    throw;
            }
        }
    }

    private async Task MarkProcessedAsync(
        Guid stateId,
        string claimToken,
        DateTime utcNow,
        CancellationToken cancellationToken)
    {
        var concurrencyToken = Guid.NewGuid().ToByteArray();
        await dbContext.Set<PayPalWebhookProcessingState>()
            .Where(x => x.Id == stateId && x.ClaimToken == claimToken)
            .ExecuteUpdateAsync(setters => setters
                .SetProperty(x => x.Status, PayPalWebhookProcessingStatus.Processed)
                .SetProperty(x => x.ProcessedAtUtc, utcNow)
                .SetProperty(x => x.ClaimToken, (string?)null)
                .SetProperty(x => x.ClaimUntilUtc, (DateTime?)null)
                .SetProperty(x => x.NextAttemptAtUtc, (DateTime?)null)
                .SetProperty(x => x.LastError, (string?)null)
                .SetProperty(x => x.UpdatedAtUtc, utcNow)
                .SetProperty(x => x.ConcurrencyToken, concurrencyToken),
                cancellationToken);
    }

    private async Task MarkFailedAsync(
        Guid stateId,
        string claimToken,
        int attemptCount,
        Exception exception,
        DateTime utcNow,
        CancellationToken cancellationToken)
    {
        var exponent = Math.Clamp(attemptCount, 1, 6);
        var delayMinutes = Math.Min(60, 1 << exponent);
        var nextAttempt = utcNow.AddMinutes(delayMinutes);
        var sanitizedError = $"PayPal subscription synchronization failed ({exception.GetType().Name}).";
        var concurrencyToken = Guid.NewGuid().ToByteArray();

        await dbContext.Set<PayPalWebhookProcessingState>()
            .Where(x => x.Id == stateId && x.ClaimToken == claimToken)
            .ExecuteUpdateAsync(setters => setters
                .SetProperty(x => x.Status, PayPalWebhookProcessingStatus.Failed)
                .SetProperty(x => x.NextAttemptAtUtc, nextAttempt)
                .SetProperty(x => x.ClaimToken, (string?)null)
                .SetProperty(x => x.ClaimUntilUtc, (DateTime?)null)
                .SetProperty(x => x.LastError, sanitizedError)
                .SetProperty(x => x.UpdatedAtUtc, utcNow)
                .SetProperty(x => x.ConcurrencyToken, concurrencyToken),
                cancellationToken);
    }

    private static DateTime NormalizeUtc(DateTime value) => value.Kind switch
    {
        DateTimeKind.Utc => value,
        DateTimeKind.Local => value.ToUniversalTime(),
        _ => DateTime.SpecifyKind(value, DateTimeKind.Utc)
    };

    private static DateTime? NormalizeNullableUtc(DateTime? value) =>
        value.HasValue ? NormalizeUtc(value.Value) : null;

    private static void RequireUtc(DateTime value, string parameterName)
    {
        if (value.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Timestamp must be UTC.", parameterName);
    }

    private enum SyncOutcome
    {
        Changed = 1,
        Unchanged = 2,
        Ignored = 3
    }

    private sealed record VerifiedTrigger(
        string ProviderEventId,
        string EventType,
        string ProviderSubscriptionReference,
        GatewaySubscriptionState State,
        DateTime OccurredAtUtc);

    private sealed record LocalSubscription(
        Guid Id,
        Guid PlanId,
        AccountSubscriptionStatus Status,
        DateTime? TrialStartedAtUtc,
        DateTime? TrialEndsAtUtc,
        DateTime? CurrentPeriodStartUtc,
        DateTime? CurrentPeriodEndsAtUtc,
        DateTime? LastProviderEventAtUtc);
}

public sealed class PayPalSubscriptionSynchronizationWorker(
    IServiceScopeFactory scopeFactory,
    ILogger<PayPalSubscriptionSynchronizationWorker> logger) : BackgroundService
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromMinutes(1);

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                using var scope = scopeFactory.CreateScope();
                var service = scope.ServiceProvider.GetRequiredService<IPayPalSubscriptionSynchronizationService>();
                var webhook = await service.ProcessVerifiedEventsAsync(DateTime.UtcNow, 100, stoppingToken);
                var reconciliation = await service.ReconcileBoundSubscriptionsAsync(DateTime.UtcNow, 100, stoppingToken);

                if (webhook.Failed > 0 || reconciliation.Failed > 0)
                {
                    logger.LogWarning(
                        "PayPal subscription synchronization pass completed with failures. WebhookFailures={WebhookFailures}, ReconciliationFailures={ReconciliationFailures}",
                        webhook.Failed,
                        reconciliation.Failed);
                }
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                logger.LogError(ex, "PayPal subscription synchronization worker pass failed.");
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