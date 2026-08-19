using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed record AdminBillingSearchItem(
    Guid SubscriptionId,
    Guid OwnerUserId,
    string UserName,
    Guid PlanId,
    string PlanNameEn,
    string PlanNameAr,
    AccountSubscriptionStatus Status,
    string? ProviderKey,
    string? MaskedProviderSubscriptionReference,
    DateTime? CurrentPeriodEndsAtUtc,
    DateTime? GraceUntilUtc,
    DateTime? LastProviderEventAtUtc);

public sealed record AdminBillingSupportDetail(
    AdminBillingSearchItem Subscription,
    DateTime? TrialStartedAtUtc,
    DateTime? TrialEndsAtUtc,
    DateTime? CurrentPeriodStartUtc,
    DateTime? CurrentPeriodEndsAtUtc,
    bool CancelAtPeriodEnd,
    DateTime? SuspendedAtUtc,
    DateTime? CancelledAtUtc,
    DateTime? ExpiredAtUtc,
    IReadOnlyList<AccountBillingHistoryItem> History);

public sealed record AdminBillingReconciliationResult(
    bool Changed,
    AccountSubscriptionStatus LocalStatus,
    string? PlanNameEn,
    string? PlanNameAr,
    DateTime ProviderObservedAtUtc,
    string Summary);

/// <summary>
/// Administrator-only subscription support boundary. This service intentionally exposes no
/// manual payment-success operation. Provider-bound status/plan correction is accepted only from
/// a fresh authoritative provider API snapshot; support access overrides are separately audited.
/// </summary>
public sealed class AdminBillingSupportService(
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    IAccountSubscriptionService subscriptionService,
    IPaymentGatewayRegistry paymentGatewayRegistry,
    IHttpContextAccessor? httpContextAccessor = null)
{
    private const string PayPalGatewayKey = "paypal";
    private readonly ApplicationSecurityAuditService _securityAudit =
        new(dbContext, currentUser, httpContextAccessor);

    public async Task<IReadOnlyList<AdminBillingSearchItem>> SearchAsync(
        string? query,
        int take = 50,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        take = Math.Clamp(take, 1, 100);
        var clean = NormalizeQuery(query);
        var normalized = clean.ToUpperInvariant();
        var hasOwnerId = Guid.TryParse(clean, out var ownerId);

        var rows = await (
                from subscription in dbContext.AccountSubscriptions.AsNoTracking()
                join user in dbContext.AuthUsers.AsNoTracking() on subscription.OwnerUserId equals user.Id
                join plan in dbContext.SubscriptionPlans.AsNoTracking() on subscription.PlanId equals plan.Id
                where clean.Length == 0 ||
                      (hasOwnerId && subscription.OwnerUserId == ownerId) ||
                      user.NormalizedUserName.Contains(normalized) ||
                      (subscription.ProviderSubscriptionReference != null &&
                       subscription.ProviderSubscriptionReference.Contains(clean))
                orderby subscription.UpdatedAtUtc descending, user.UserName
                select new
                {
                    subscription.Id,
                    subscription.OwnerUserId,
                    user.UserName,
                    subscription.PlanId,
                    plan.NameEn,
                    plan.NameAr,
                    subscription.Status,
                    subscription.ProviderKey,
                    subscription.ProviderSubscriptionReference,
                    subscription.CurrentPeriodEndsAtUtc,
                    subscription.GraceUntilUtc,
                    subscription.LastProviderEventAtUtc
                })
            .Take(take)
            .ToListAsync(cancellationToken);

        return rows.Select(x => new AdminBillingSearchItem(
                x.Id,
                x.OwnerUserId,
                x.UserName,
                x.PlanId,
                x.NameEn,
                x.NameAr,
                x.Status,
                x.ProviderKey,
                MaskProviderReference(x.ProviderSubscriptionReference),
                NormalizeNullableUtc(x.CurrentPeriodEndsAtUtc),
                NormalizeNullableUtc(x.GraceUntilUtc),
                NormalizeNullableUtc(x.LastProviderEventAtUtc)))
            .ToArray();
    }

    public async Task<AdminBillingSupportDetail> GetAsync(
        Guid subscriptionId,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        var row = await RequireSupportRowAsync(subscriptionId, cancellationToken);
        var history = await subscriptionService.ListBillingHistoryAsync(
            row.OwnerUserId,
            row.Id,
            100,
            cancellationToken);

        return new AdminBillingSupportDetail(
            ToSearchItem(row),
            NormalizeNullableUtc(row.TrialStartedAtUtc),
            NormalizeNullableUtc(row.TrialEndsAtUtc),
            NormalizeNullableUtc(row.CurrentPeriodStartUtc),
            NormalizeNullableUtc(row.CurrentPeriodEndsAtUtc),
            row.CancelAtPeriodEnd,
            NormalizeNullableUtc(row.SuspendedAtUtc),
            NormalizeNullableUtc(row.CancelledAtUtc),
            NormalizeNullableUtc(row.ExpiredAtUtc),
            history);
    }

    public async Task<AdminBillingSupportDetail> GrantOrExtendGraceAsync(
        Guid subscriptionId,
        DateTime graceUntilUtc,
        string supportReason,
        CancellationToken cancellationToken = default)
    {
        var actorId = currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        RequireUtc(graceUntilUtc, nameof(graceUntilUtc));
        var reason = RequireSupportReason(supportReason);
        var now = DateTime.UtcNow;
        if (graceUntilUtc <= now)
            throw new ArgumentException("Grace end must be later than the current time.", nameof(graceUntilUtc));

        var row = await RequireSupportRowAsync(subscriptionId, cancellationToken);
        if (row.Status == AccountSubscriptionStatus.Expired || row.Status == AccountSubscriptionStatus.Cancelled)
            throw new InvalidOperationException("Grace cannot be granted to a cancelled or expired subscription.");

        var action = "Billing.GraceGranted";
        if (row.Status == AccountSubscriptionStatus.Grace)
        {
            var currentGraceUntil = NormalizeNullableUtc(row.GraceUntilUtc);
            if (currentGraceUntil.HasValue && graceUntilUtc <= currentGraceUntil.Value)
                throw new InvalidOperationException("Grace extension must move the existing grace end forward.");

            var updated = await dbContext.AccountSubscriptions
                .Where(x => x.Id == subscriptionId && x.Status == AccountSubscriptionStatus.Grace)
                .ExecuteUpdateAsync(setters => setters
                    .SetProperty(x => x.GraceUntilUtc, graceUntilUtc)
                    .SetProperty(x => x.UpdatedAtUtc, now), cancellationToken);
            if (updated != 1)
                throw new InvalidOperationException("Subscription state changed before the grace extension could be applied.");
            action = "Billing.GraceExtended";
        }
        else
        {
            if (!AccountSubscriptionStateMachine.CanTransition(row.Status, AccountSubscriptionStatus.Grace))
                throw new InvalidOperationException($"Subscription transition {row.Status} -> Grace is not allowed.");

            await subscriptionService.TransitionAsync(subscriptionId, new(
                AccountSubscriptionStatus.Grace,
                SubscriptionTransitionSource.Administration,
                reason,
                now,
                null,
                graceUntilUtc), cancellationToken);
        }

        await RecordInterventionAsync(
            actorId,
            action,
            row,
            reason,
            new Dictionary<string, string>
            {
                ["graceUntilUtc"] = graceUntilUtc.ToString("O"),
                ["previousStatus"] = row.Status.ToString()
            },
            cancellationToken);
        return await GetAsync(subscriptionId, cancellationToken);
    }

    public async Task<AdminBillingSupportDetail> SuspendAsync(
        Guid subscriptionId,
        string supportReason,
        CancellationToken cancellationToken = default)
    {
        var actorId = currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        var reason = RequireSupportReason(supportReason);
        var row = await RequireSupportRowAsync(subscriptionId, cancellationToken);
        if (row.Status == AccountSubscriptionStatus.Suspended)
            throw new InvalidOperationException("Subscription is already suspended.");
        if (!AccountSubscriptionStateMachine.CanTransition(row.Status, AccountSubscriptionStatus.Suspended))
            throw new InvalidOperationException($"Subscription transition {row.Status} -> Suspended is not allowed.");

        await subscriptionService.TransitionAsync(subscriptionId, new(
            AccountSubscriptionStatus.Suspended,
            SubscriptionTransitionSource.Administration,
            reason,
            DateTime.UtcNow), cancellationToken);

        await RecordInterventionAsync(actorId, "Billing.Suspended", row, reason, null, cancellationToken);
        return await GetAsync(subscriptionId, cancellationToken);
    }

    public async Task<AdminBillingSupportDetail> ReactivateAsync(
        Guid subscriptionId,
        string supportReason,
        CancellationToken cancellationToken = default)
    {
        var actorId = currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        var reason = RequireSupportReason(supportReason);
        var row = await RequireSupportRowAsync(subscriptionId, cancellationToken);
        if (row.Status != AccountSubscriptionStatus.Suspended)
            throw new InvalidOperationException("Only a suspended subscription can be administratively reactivated.");
        if (!string.IsNullOrWhiteSpace(row.ProviderKey) || !string.IsNullOrWhiteSpace(row.ProviderSubscriptionReference))
            throw new InvalidOperationException("Provider-bound subscriptions must be reactivated from authoritative provider reconciliation, not an administrative Active override.");

        await subscriptionService.TransitionAsync(subscriptionId, new(
            AccountSubscriptionStatus.Active,
            SubscriptionTransitionSource.Administration,
            reason,
            DateTime.UtcNow), cancellationToken);

        await RecordInterventionAsync(actorId, "Billing.Reactivated", row, reason, null, cancellationToken);
        return await GetAsync(subscriptionId, cancellationToken);
    }

    public async Task<AdminBillingReconciliationResult> ReconcilePayPalAsync(
        Guid subscriptionId,
        string supportReason,
        CancellationToken cancellationToken = default)
    {
        var actorId = currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        var supportNote = RequireSupportReason(supportReason);
        var row = await RequireSupportRowAsync(subscriptionId, cancellationToken);
        if (!string.Equals(row.ProviderKey, PayPalGatewayKey, StringComparison.OrdinalIgnoreCase) ||
            string.IsNullOrWhiteSpace(row.ProviderSubscriptionReference))
            throw new InvalidOperationException("The subscription is not bound to PayPal.");
        if (row.Status == AccountSubscriptionStatus.Expired)
            throw new InvalidOperationException("An expired subscription is terminal and cannot be reconciled into a new state.");

        var providerReference = row.ProviderSubscriptionReference.Trim();
        var gateway = paymentGatewayRegistry.GetRequired(PayPalGatewayKey, PaymentGatewayCapability.SubscriptionLookup);
        var snapshot = await gateway.GetSubscriptionAsync(providerReference, cancellationToken);
        if (!string.Equals(snapshot.ProviderSubscriptionReference, providerReference, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("PayPal subscription snapshot reference did not match the selected subscription.");

        var observedAt = snapshot.ObservedAtUtc;
        var lastProviderEvent = NormalizeNullableUtc(row.LastProviderEventAtUtc);
        if (lastProviderEvent.HasValue && observedAt <= lastProviderEvent.Value)
        {
            await RecordInterventionAsync(
                actorId,
                "Billing.Reconciled",
                row,
                supportNote,
                new Dictionary<string, string>
                {
                    ["changed"] = bool.FalseString,
                    ["providerReference"] = MaskProviderReference(providerReference) ?? "—",
                    ["providerObservedAtUtc"] = observedAt.ToString("O"),
                    ["result"] = "StaleOrDuplicateProviderSnapshot"
                },
                cancellationToken);
            return new(false, row.Status, row.PlanNameEn, row.PlanNameAr, observedAt,
                "Provider snapshot was not newer than the last applied provider evidence.");
        }

        Guid? authoritativePlanId = null;
        SupportPlanRow? authoritativePlan = null;
        if (!string.IsNullOrWhiteSpace(snapshot.ProviderPlanReference))
        {
            var plans = await dbContext.SubscriptionPlans.AsNoTracking()
                .Where(x => x.GatewayPlanId == snapshot.ProviderPlanReference)
                .Select(x => new SupportPlanRow(x.Id, x.NameEn, x.NameAr))
                .Take(2)
                .ToListAsync(cancellationToken);
            if (plans.Count == 0)
                throw new InvalidOperationException("PayPal subscription snapshot references an unmapped local plan.");
            if (plans.Count > 1)
                throw new InvalidOperationException("PayPal plan mapping is ambiguous in the local plan catalog.");
            authoritativePlan = plans[0];
            authoritativePlanId = authoritativePlan.Id;
        }

        var targetStatus = ResolveProviderTargetStatus(row, snapshot);
        if (targetStatus != row.Status && !AccountSubscriptionStateMachine.CanTransition(row.Status, targetStatus))
            targetStatus = row.Status;

        var providerReason = "Administrator-requested PayPal reconciliation applied from an authoritative provider API snapshot.";
        var changed = false;
        await using var transaction = await dbContext.Database.BeginTransactionAsync(cancellationToken);
        if (authoritativePlanId.HasValue && authoritativePlanId.Value != row.PlanId)
        {
            var planChange = await subscriptionService.ChangePlanAsync(subscriptionId, new(
                authoritativePlanId.Value,
                SubscriptionTransitionSource.Provider,
                providerReason,
                DateTime.UtcNow,
                observedAt), cancellationToken);
            changed |= planChange.PlanChanged;
        }

        var transition = await subscriptionService.TransitionAsync(subscriptionId, new(
            targetStatus,
            SubscriptionTransitionSource.Provider,
            providerReason,
            DateTime.UtcNow,
            observedAt), cancellationToken);
        changed |= transition.StatusChanged;

        var localPeriodStart = NormalizeNullableUtc(row.CurrentPeriodStartUtc);
        var localPeriodEnd = NormalizeNullableUtc(row.CurrentPeriodEndsAtUtc);
        if (snapshot.CurrentPeriodStartUtc.HasValue && snapshot.CurrentPeriodEndsAtUtc.HasValue &&
            (localPeriodStart != snapshot.CurrentPeriodStartUtc.Value || localPeriodEnd != snapshot.CurrentPeriodEndsAtUtc.Value))
        {
            await subscriptionService.UpdatePeriodsAsync(subscriptionId, new(
                NormalizeNullableUtc(row.TrialStartedAtUtc),
                NormalizeNullableUtc(row.TrialEndsAtUtc),
                snapshot.CurrentPeriodStartUtc,
                snapshot.CurrentPeriodEndsAtUtc), cancellationToken);
            changed = true;
        }
        await transaction.CommitAsync(cancellationToken);

        await RecordInterventionAsync(
            actorId,
            "Billing.Reconciled",
            row,
            supportNote,
            new Dictionary<string, string>
            {
                ["changed"] = changed.ToString(),
                ["providerReference"] = MaskProviderReference(providerReference) ?? "—",
                ["providerObservedAtUtc"] = observedAt.ToString("O"),
                ["providerState"] = snapshot.State.ToString(),
                ["resultingStatus"] = targetStatus.ToString(),
                ["planChanged"] = (authoritativePlanId.HasValue && authoritativePlanId.Value != row.PlanId).ToString()
            },
            cancellationToken);

        return new(
            changed,
            targetStatus,
            authoritativePlan?.NameEn ?? row.PlanNameEn,
            authoritativePlan?.NameAr ?? row.PlanNameAr,
            observedAt,
            changed ? "Authoritative PayPal snapshot was applied." : "Authoritative PayPal snapshot confirmed the current subscription state.");
    }

    private async Task<SupportSubscriptionRow> RequireSupportRowAsync(
        Guid subscriptionId,
        CancellationToken cancellationToken)
    {
        if (subscriptionId == Guid.Empty)
            throw new ArgumentException("Subscription ID is required.", nameof(subscriptionId));

        return await (
                from subscription in dbContext.AccountSubscriptions.AsNoTracking()
                join user in dbContext.AuthUsers.AsNoTracking() on subscription.OwnerUserId equals user.Id
                join plan in dbContext.SubscriptionPlans.AsNoTracking() on subscription.PlanId equals plan.Id
                where subscription.Id == subscriptionId
                select new SupportSubscriptionRow(
                    subscription.Id,
                    subscription.OwnerUserId,
                    user.UserName,
                    subscription.PlanId,
                    plan.NameEn,
                    plan.NameAr,
                    subscription.Status,
                    subscription.ProviderKey,
                    subscription.ProviderSubscriptionReference,
                    subscription.TrialStartedAtUtc,
                    subscription.TrialEndsAtUtc,
                    subscription.CurrentPeriodStartUtc,
                    subscription.CurrentPeriodEndsAtUtc,
                    subscription.CancelAtPeriodEnd,
                    subscription.GraceUntilUtc,
                    subscription.SuspendedAtUtc,
                    subscription.CancelledAtUtc,
                    subscription.ExpiredAtUtc,
                    subscription.LastProviderEventAtUtc))
            .SingleOrDefaultAsync(cancellationToken)
            ?? throw new KeyNotFoundException("Account subscription was not found.");
    }

    private async Task RecordInterventionAsync(
        Guid actorId,
        string action,
        SupportSubscriptionRow row,
        string supportReason,
        IReadOnlyDictionary<string, string>? extra,
        CancellationToken cancellationToken)
    {
        var metadata = new Dictionary<string, string>(StringComparer.Ordinal)
        {
            ["actorId"] = actorId.ToString("D"),
            ["ownerUserId"] = row.OwnerUserId.ToString("D"),
            ["supportReason"] = supportReason,
            ["providerKey"] = string.IsNullOrWhiteSpace(row.ProviderKey) ? "none" : row.ProviderKey,
            ["providerReference"] = MaskProviderReference(row.ProviderSubscriptionReference) ?? "none"
        };
        if (extra is not null)
        {
            foreach (var pair in extra)
                metadata[pair.Key] = pair.Value;
        }

        await _securityAudit.RecordCurrentAsync(
            "BillingSupport",
            action,
            "Succeeded",
            "AccountSubscription",
            row.Id.ToString("D"),
            row.UserName,
            metadata,
            cancellationToken);
    }

    private static AccountSubscriptionStatus ResolveProviderTargetStatus(
        SupportSubscriptionRow local,
        GatewaySubscriptionSnapshot snapshot)
    {
        if (snapshot.State == GatewaySubscriptionState.Suspended) return AccountSubscriptionStatus.Suspended;
        if (snapshot.State == GatewaySubscriptionState.Cancelled) return AccountSubscriptionStatus.Cancelled;
        if (snapshot.State == GatewaySubscriptionState.Expired) return AccountSubscriptionStatus.Expired;
        if (snapshot.State == GatewaySubscriptionState.PastDue) return AccountSubscriptionStatus.PastDue;

        if (snapshot.State == GatewaySubscriptionState.Active &&
            local.Status is AccountSubscriptionStatus.PastDue or AccountSubscriptionStatus.Grace)
        {
            var lastProviderEvent = NormalizeNullableUtc(local.LastProviderEventAtUtc);
            var hasNewerPaymentPeriod = snapshot.CurrentPeriodStartUtc.HasValue &&
                                        lastProviderEvent.HasValue &&
                                        snapshot.CurrentPeriodStartUtc.Value > lastProviderEvent.Value;
            return hasNewerPaymentPeriod ? AccountSubscriptionStatus.Active : local.Status;
        }

        return snapshot.State == GatewaySubscriptionState.Active
            ? AccountSubscriptionStatus.Active
            : local.Status;
    }

    private static AdminBillingSearchItem ToSearchItem(SupportSubscriptionRow row) => new(
        row.Id,
        row.OwnerUserId,
        row.UserName,
        row.PlanId,
        row.PlanNameEn,
        row.PlanNameAr,
        row.Status,
        row.ProviderKey,
        MaskProviderReference(row.ProviderSubscriptionReference),
        NormalizeNullableUtc(row.CurrentPeriodEndsAtUtc),
        NormalizeNullableUtc(row.GraceUntilUtc),
        NormalizeNullableUtc(row.LastProviderEventAtUtc));

    private static string NormalizeQuery(string? query)
    {
        var clean = string.Join(' ', (query ?? string.Empty).Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries));
        if (clean.Length > 200)
            throw new ArgumentException("Billing support search query must be at most 200 characters.", nameof(query));
        return clean;
    }

    private static string RequireSupportReason(string? reason)
    {
        var clean = string.Join(' ', (reason ?? string.Empty).Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries));
        if (clean.Length < 5 || clean.Length > 500)
            throw new ArgumentException("A support reason between 5 and 500 characters is required.", nameof(reason));
        return clean;
    }

    public static string? MaskProviderReference(string? reference)
    {
        var clean = (reference ?? string.Empty).Trim();
        if (clean.Length == 0) return null;
        if (clean.Length <= 8) return new string('•', clean.Length);
        return $"{clean[..3]}…{clean[^4..]}";
    }

    private static void RequireUtc(DateTime value, string parameterName)
    {
        if (value.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Timestamp must be UTC.", parameterName);
    }

    private static DateTime? NormalizeNullableUtc(DateTime? value) => value.HasValue
        ? value.Value.Kind switch
        {
            DateTimeKind.Utc => value.Value,
            DateTimeKind.Local => value.Value.ToUniversalTime(),
            _ => DateTime.SpecifyKind(value.Value, DateTimeKind.Utc)
        }
        : null;

    private sealed record SupportPlanRow(Guid Id, string NameEn, string NameAr);

    private sealed record SupportSubscriptionRow(
        Guid Id,
        Guid OwnerUserId,
        string UserName,
        Guid PlanId,
        string PlanNameEn,
        string PlanNameAr,
        AccountSubscriptionStatus Status,
        string? ProviderKey,
        string? ProviderSubscriptionReference,
        DateTime? TrialStartedAtUtc,
        DateTime? TrialEndsAtUtc,
        DateTime? CurrentPeriodStartUtc,
        DateTime? CurrentPeriodEndsAtUtc,
        bool CancelAtPeriodEnd,
        DateTime? GraceUntilUtc,
        DateTime? SuspendedAtUtc,
        DateTime? CancelledAtUtc,
        DateTime? ExpiredAtUtc,
        DateTime? LastProviderEventAtUtc);
}
