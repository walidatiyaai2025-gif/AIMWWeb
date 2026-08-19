using System.Globalization;
using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Email;

/// <summary>
/// Relays committed subscription lifecycle audit records into the durable account email outbox.
/// Provider commands, browser returns, and other request-side signals are intentionally not inputs.
/// </summary>
public sealed class SubscriptionBillingEmailAlertRelay(
    AppDbContext dbContext,
    IEmailTemplateRenderer templateRenderer,
    IEmailOutbox emailOutbox,
    ILogger<SubscriptionBillingEmailAlertRelay> logger)
{
    private const int MaxCandidatesPerType = 500;
    private const int MaxCandidatesPerPass = 500;
    private const int MaxDetailsLength = 1000;

    public async Task<SubscriptionBillingEmailRelayResult> RelayPendingAsync(
        DateTime sinceUtc,
        IReadOnlySet<string>? alreadyHandled = null,
        CancellationToken cancellationToken = default)
    {
        sinceUtc = sinceUtc.Kind == DateTimeKind.Utc ? sinceUtc : sinceUtc.ToUniversalTime();
        var candidates = await LoadCandidatesAsync(sinceUtc, cancellationToken);
        var handled = new List<string>(candidates.Count);
        var enqueued = 0;
        var skipped = 0;
        var failed = 0;

        foreach (var candidate in candidates)
        {
            if (alreadyHandled?.Contains(candidate.EventKey) == true)
                continue;

            try
            {
                var idempotencyKey = BuildIdempotencyKey(candidate.Kind, candidate.EventId);
                var existing = await dbContext.EmailOutboxMessages.AsNoTracking()
                    .AnyAsync(
                        x => x.OwnerUserId == candidate.OwnerUserId && x.IdempotencyKey == idempotencyKey,
                        cancellationToken);
                if (existing)
                {
                    enqueued++;
                    handled.Add(candidate.EventKey);
                    continue;
                }

                var recipients = await dbContext.AccountEmailRecipients.AsNoTracking()
                    .Where(x => x.OwnerUserId == candidate.OwnerUserId && x.IsEnabled)
                    .OrderBy(x => x.CreatedAtUtc)
                    .Select(x => x.EmailAddress)
                    .ToListAsync(cancellationToken);
                if (recipients.Count == 0)
                {
                    skipped++;
                    handled.Add(candidate.EventKey);
                    continue;
                }

                var correlationId = BuildCorrelationId(candidate.Kind, candidate.EventId);
                var rendered = templateRenderer.Render(new EmailTemplateRenderRequest(
                    EmailTemplateKeys.BillingEvent,
                    "en",
                    new Dictionary<string, string?>(StringComparer.OrdinalIgnoreCase)
                    {
                        ["AccountName"] = candidate.AccountName,
                        ["BillingStatus"] = candidate.BillingStatus,
                        ["OccurredAt"] = FormatUtc(candidate.OccurredAtUtc),
                        ["PlanName"] = candidate.PlanName,
                        ["Amount"] = candidate.Amount.ToString("0.####", CultureInfo.InvariantCulture),
                        ["Currency"] = candidate.Currency,
                        ["Reference"] = candidate.Reference,
                        ["Details"] = candidate.Details
                    }));

                await emailOutbox.EnqueueAsync(new EmailOutboxEnqueueRequest(
                    OwnerUserId: candidate.OwnerUserId,
                    SiteId: null,
                    ScheduleId: null,
                    TemplateKey: rendered.TemplateKey,
                    Subject: rendered.Subject,
                    HtmlBody: rendered.HtmlBody,
                    TextBody: rendered.TextBody,
                    Recipients: recipients,
                    IdempotencyKey: idempotencyKey,
                    CorrelationId: correlationId,
                    MaxAttempts: 5), cancellationToken);

                enqueued++;
                handled.Add(candidate.EventKey);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                failed++;
                logger.LogWarning(
                    ex,
                    "Could not relay subscription billing event {BillingEventKey}. The relay will retry it on a later pass.",
                    candidate.EventKey);
            }
        }

        return new SubscriptionBillingEmailRelayResult(candidates.Count, enqueued, skipped, failed, handled);
    }

    private async Task<IReadOnlyList<BillingEventCandidate>> LoadCandidatesAsync(
        DateTime sinceUtc,
        CancellationToken cancellationToken)
    {
        var statusRows = await (
            from transition in dbContext.AccountSubscriptionTransitions.AsNoTracking()
            join subscription in dbContext.AccountSubscriptions.AsNoTracking()
                on transition.SubscriptionId equals subscription.Id
            join plan in dbContext.SubscriptionPlans.AsNoTracking()
                on subscription.PlanId equals plan.Id
            join owner in dbContext.AuthUsers.AsNoTracking()
                on subscription.OwnerUserId equals owner.Id
            where transition.OccurredAtUtc >= sinceUtc
            orderby transition.OccurredAtUtc, transition.Id
            select new StatusTransitionRow(
                transition.Id,
                subscription.OwnerUserId,
                owner.UserName,
                transition.FromStatus,
                transition.ToStatus,
                transition.Source,
                transition.Reason,
                transition.OccurredAtUtc,
                plan.NameEn,
                plan.Price,
                plan.Currency,
                subscription.ProviderSubscriptionReference))
            .Take(MaxCandidatesPerType)
            .ToListAsync(cancellationToken);

        var planRows = await (
            from change in dbContext.AccountSubscriptionPlanChanges.AsNoTracking()
            join subscription in dbContext.AccountSubscriptions.AsNoTracking()
                on change.SubscriptionId equals subscription.Id
            join fromPlan in dbContext.SubscriptionPlans.AsNoTracking()
                on change.FromPlanId equals fromPlan.Id
            join toPlan in dbContext.SubscriptionPlans.AsNoTracking()
                on change.ToPlanId equals toPlan.Id
            join owner in dbContext.AuthUsers.AsNoTracking()
                on subscription.OwnerUserId equals owner.Id
            where change.OccurredAtUtc >= sinceUtc
            orderby change.OccurredAtUtc, change.Id
            select new PlanChangeRow(
                change.Id,
                subscription.OwnerUserId,
                owner.UserName,
                fromPlan.NameEn,
                toPlan.NameEn,
                toPlan.Price,
                toPlan.Currency,
                change.Source,
                change.Reason,
                change.OccurredAtUtc,
                subscription.ProviderSubscriptionReference))
            .Take(MaxCandidatesPerType)
            .ToListAsync(cancellationToken);

        return statusRows
            .Select(ToCandidate)
            .Concat(planRows.Select(ToCandidate))
            .OrderBy(x => x.OccurredAtUtc)
            .ThenBy(x => x.EventKey, StringComparer.Ordinal)
            .Take(MaxCandidatesPerPass)
            .ToArray();
    }

    private static BillingEventCandidate ToCandidate(StatusTransitionRow row) => new(
        row.Id,
        BillingEventKind.StatusTransition,
        BuildEventKey(BillingEventKind.StatusTransition, row.Id),
        row.OwnerUserId,
        NormalizeLabel(row.AccountName, "Account"),
        row.ToStatus.ToString(),
        NormalizeLabel(row.PlanName, "Subscription plan"),
        row.Amount,
        NormalizeLabel(row.Currency, "USD"),
        NormalizeOptional(row.Reference),
        NormalizeDetails($"Subscription status changed from {row.FromStatus} to {row.ToStatus}. Source: {row.Source}. Reason: {row.Reason}"),
        row.OccurredAtUtc);

    private static BillingEventCandidate ToCandidate(PlanChangeRow row) => new(
        row.Id,
        BillingEventKind.PlanChange,
        BuildEventKey(BillingEventKind.PlanChange, row.Id),
        row.OwnerUserId,
        NormalizeLabel(row.AccountName, "Account"),
        "Plan changed",
        NormalizeLabel(row.ToPlanName, "Subscription plan"),
        row.Amount,
        NormalizeLabel(row.Currency, "USD"),
        NormalizeOptional(row.Reference),
        NormalizeDetails($"Subscription plan changed from {row.FromPlanName} to {row.ToPlanName}. Source: {row.Source}. Reason: {row.Reason}"),
        row.OccurredAtUtc);

    internal static string BuildIdempotencyKeyForStatusTransition(Guid eventId) =>
        BuildIdempotencyKey(BillingEventKind.StatusTransition, eventId);

    internal static string BuildIdempotencyKeyForPlanChange(Guid eventId) =>
        BuildIdempotencyKey(BillingEventKind.PlanChange, eventId);

    private static string BuildIdempotencyKey(BillingEventKind kind, Guid eventId) => kind switch
    {
        BillingEventKind.StatusTransition => $"alert:billing:status-transition:{eventId:N}",
        BillingEventKind.PlanChange => $"alert:billing:plan-change:{eventId:N}",
        _ => throw new ArgumentOutOfRangeException(nameof(kind))
    };

    private static string BuildCorrelationId(BillingEventKind kind, Guid eventId) => kind switch
    {
        BillingEventKind.StatusTransition => $"billing:status:{eventId:N}",
        BillingEventKind.PlanChange => $"billing:plan:{eventId:N}",
        _ => throw new ArgumentOutOfRangeException(nameof(kind))
    };

    private static string BuildEventKey(BillingEventKind kind, Guid eventId) =>
        $"{(kind == BillingEventKind.StatusTransition ? "status" : "plan")}:{eventId:N}";

    private static string FormatUtc(DateTime value) =>
        value.ToUniversalTime().ToString("yyyy-MM-dd HH:mm:ss 'UTC'", CultureInfo.InvariantCulture);

    private static string NormalizeLabel(string? value, string fallback)
    {
        var clean = string.IsNullOrWhiteSpace(value) ? fallback : value.Trim();
        clean = clean.Replace('\r', ' ').Replace('\n', ' ').Trim();
        return clean.Length <= 160 ? clean : clean[..160];
    }

    private static string? NormalizeOptional(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        var clean = value.Trim().Replace('\r', ' ').Replace('\n', ' ').Trim();
        return clean.Length <= 200 ? clean : clean[..200];
    }

    private static string NormalizeDetails(string value)
    {
        var clean = string.Join(' ', value.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries));
        return clean.Length <= MaxDetailsLength ? clean : clean[..MaxDetailsLength];
    }

    private enum BillingEventKind
    {
        StatusTransition,
        PlanChange
    }

    private sealed record StatusTransitionRow(
        Guid Id,
        Guid OwnerUserId,
        string AccountName,
        AccountSubscriptionStatus FromStatus,
        AccountSubscriptionStatus ToStatus,
        SubscriptionTransitionSource Source,
        string Reason,
        DateTime OccurredAtUtc,
        string PlanName,
        decimal Amount,
        string Currency,
        string? Reference);

    private sealed record PlanChangeRow(
        Guid Id,
        Guid OwnerUserId,
        string AccountName,
        string FromPlanName,
        string ToPlanName,
        decimal Amount,
        string Currency,
        SubscriptionTransitionSource Source,
        string Reason,
        DateTime OccurredAtUtc,
        string? Reference);

    private sealed record BillingEventCandidate(
        Guid EventId,
        BillingEventKind Kind,
        string EventKey,
        Guid OwnerUserId,
        string AccountName,
        string BillingStatus,
        string PlanName,
        decimal Amount,
        string Currency,
        string? Reference,
        string Details,
        DateTime OccurredAtUtc);
}

public sealed record SubscriptionBillingEmailRelayResult(
    int Scanned,
    int Enqueued,
    int Skipped,
    int Failed,
    IReadOnlyList<string> HandledEventKeys);
