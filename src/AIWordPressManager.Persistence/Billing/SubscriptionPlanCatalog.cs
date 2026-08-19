using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Billing;

public sealed class SubscriptionPlanCatalog(AppDbContext dbContext) : ISubscriptionPlanCatalog
{
    public const string FreeTrialCode = "free-trial";
    public const int FreeTrialDays = 14;

    private static readonly IReadOnlyDictionary<string, string> FreeTrialEntitlements =
        new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            [EntitlementDefinitionCatalog.SitesMax] = "1",
            [EntitlementDefinitionCatalog.EmailSiteRecipientsMax] = "2",
            [EntitlementDefinitionCatalog.EmailSchedulesMax] = "1",
            [EntitlementDefinitionCatalog.EmailDashboardDigest] = "false",
            [EntitlementDefinitionCatalog.AutomationSchedulesMax] = "1",
            [EntitlementDefinitionCatalog.AiEnabled] = "true",
            [EntitlementDefinitionCatalog.AiMonthlyRequestsMax] = "50",
            [EntitlementDefinitionCatalog.BackupRetentionDays] = "3",
            [EntitlementDefinitionCatalog.PremiumSeo] = "false"
        };

    public async Task<IReadOnlyList<SubscriptionPlanItem>> ListAsync(
        bool includeDisabled = false,
        CancellationToken cancellationToken = default)
    {
        await EnsureFreeTrialAsync(cancellationToken);

        var query = dbContext.SubscriptionPlans.AsNoTracking();
        if (!includeDisabled)
            query = query.Where(x => x.IsEnabled);

        return await query
            .OrderBy(x => x.SortOrder)
            .ThenBy(x => x.NormalizedCode)
            .Select(x => new SubscriptionPlanItem(
                x.Id,
                x.Code,
                x.NameEn,
                x.NameAr,
                x.DescriptionEn,
                x.DescriptionAr,
                x.BillingInterval,
                x.Price,
                x.Currency,
                x.TrialDays,
                x.GracePeriodDays,
                x.IsEnabled,
                x.SortOrder,
                x.GatewayProductId,
                x.GatewayPlanId,
                x.CreatedAtUtc,
                x.UpdatedAtUtc))
            .ToListAsync(cancellationToken);
    }

    public async Task<SubscriptionPlanItem?> GetByCodeAsync(
        string code,
        bool includeDisabled = false,
        CancellationToken cancellationToken = default)
    {
        await EnsureFreeTrialAsync(cancellationToken);

        var normalizedCode = SubscriptionPlan.NormalizeCode(code).ToUpperInvariant();
        var query = dbContext.SubscriptionPlans.AsNoTracking()
            .Where(x => x.NormalizedCode == normalizedCode);
        if (!includeDisabled)
            query = query.Where(x => x.IsEnabled);

        return await query
            .Select(x => new SubscriptionPlanItem(
                x.Id,
                x.Code,
                x.NameEn,
                x.NameAr,
                x.DescriptionEn,
                x.DescriptionAr,
                x.BillingInterval,
                x.Price,
                x.Currency,
                x.TrialDays,
                x.GracePeriodDays,
                x.IsEnabled,
                x.SortOrder,
                x.GatewayProductId,
                x.GatewayPlanId,
                x.CreatedAtUtc,
                x.UpdatedAtUtc))
            .SingleOrDefaultAsync(cancellationToken);
    }

    public async Task<SubscriptionPlanItem> CreateAsync(
        SubscriptionPlanCreateRequest request,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        await EnsureFreeTrialAsync(cancellationToken);

        var plan = new SubscriptionPlan(
            request.Code,
            request.NameEn,
            request.NameAr,
            request.DescriptionEn,
            request.DescriptionAr,
            request.BillingInterval,
            request.Price,
            request.Currency,
            request.TrialDays,
            request.GracePeriodDays,
            request.IsEnabled,
            request.SortOrder,
            request.GatewayProductId,
            request.GatewayPlanId,
            DateTime.UtcNow);

        if (await CodeExistsAsync(plan.NormalizedCode, cancellationToken))
            throw DuplicateCode(plan.Code);

        dbContext.SubscriptionPlans.Add(plan);
        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
        }
        catch (DbUpdateException ex)
        {
            dbContext.Entry(plan).State = EntityState.Detached;
            if (await CodeExistsAsync(plan.NormalizedCode, cancellationToken))
                throw DuplicateCode(plan.Code, ex);
            throw;
        }

        return ToItem(plan);
    }

    public async Task<SubscriptionPlanItem> UpdateAsync(
        Guid planId,
        SubscriptionPlanUpdateRequest request,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        var plan = await RequireAsync(planId, cancellationToken);
        plan.Update(
            request.NameEn,
            request.NameAr,
            request.DescriptionEn,
            request.DescriptionAr,
            request.BillingInterval,
            request.Price,
            request.Currency,
            request.TrialDays,
            request.GracePeriodDays,
            request.IsEnabled,
            request.SortOrder,
            request.GatewayProductId,
            request.GatewayPlanId,
            DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return ToItem(plan);
    }

    public async Task<SubscriptionPlanItem> SetEnabledAsync(
        Guid planId,
        bool enabled,
        CancellationToken cancellationToken = default)
    {
        var plan = await RequireAsync(planId, cancellationToken);
        plan.SetEnabled(enabled, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return ToItem(plan);
    }

    private async Task EnsureFreeTrialAsync(CancellationToken cancellationToken)
    {
        var normalizedCode = FreeTrialCode.ToUpperInvariant();
        var plan = await dbContext.SubscriptionPlans
            .SingleOrDefaultAsync(x => x.NormalizedCode == normalizedCode, cancellationToken);

        if (plan is null)
        {
            plan = new SubscriptionPlan(
                FreeTrialCode,
                "Free Trial",
                "تجربة مجانية",
                "Explore the core WordPress operating workspace for 14 days with deliberately limited sites, AI usage, automation and premium capabilities.",
                "جرّب مساحة تشغيل WordPress الأساسية لمدة 14 يومًا مع حدود واضحة لعدد المواقع واستخدام الذكاء الاصطناعي والأتمتة والميزات المتقدمة.",
                SubscriptionPlan.MonthlyInterval,
                0m,
                "USD",
                FreeTrialDays,
                0,
                true,
                0,
                null,
                null,
                DateTime.UtcNow);

            dbContext.SubscriptionPlans.Add(plan);
            try
            {
                await dbContext.SaveChangesAsync(cancellationToken);
            }
            catch (DbUpdateException)
            {
                dbContext.Entry(plan).State = EntityState.Detached;
                plan = await dbContext.SubscriptionPlans
                    .SingleOrDefaultAsync(x => x.NormalizedCode == normalizedCode, cancellationToken);
                if (plan is null) throw;
            }
        }

        await EnsureFreeTrialEntitlementsAsync(plan.Id, cancellationToken);
    }

    private async Task EnsureFreeTrialEntitlementsAsync(Guid planId, CancellationToken cancellationToken)
    {
        var existing = await dbContext.PlanEntitlements.AsNoTracking()
            .Where(x => x.PlanId == planId)
            .Select(x => x.NormalizedKey)
            .ToListAsync(cancellationToken);
        var existingKeys = existing.ToHashSet(StringComparer.Ordinal);

        var missing = FreeTrialEntitlements
            .Where(pair => !existingKeys.Contains(EntitlementDefinitionCatalog.NormalizeKey(pair.Key)))
            .Select(pair => new PlanEntitlement(planId, pair.Key, pair.Value, DateTime.UtcNow))
            .ToArray();

        if (missing.Length == 0) return;

        dbContext.PlanEntitlements.AddRange(missing);
        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
        }
        catch (DbUpdateException)
        {
            foreach (var entitlement in missing)
                dbContext.Entry(entitlement).State = EntityState.Detached;

            // Another request may have completed the idempotent bootstrap concurrently.
            var persistedCount = await dbContext.PlanEntitlements.AsNoTracking()
                .CountAsync(x => x.PlanId == planId, cancellationToken);
            if (persistedCount < FreeTrialEntitlements.Count) throw;
        }
    }

    private Task<bool> CodeExistsAsync(string normalizedCode, CancellationToken cancellationToken) =>
        dbContext.SubscriptionPlans.AsNoTracking()
            .AnyAsync(x => x.NormalizedCode == normalizedCode, cancellationToken);

    private async Task<SubscriptionPlan> RequireAsync(Guid planId, CancellationToken cancellationToken)
    {
        if (planId == Guid.Empty)
            throw new ArgumentException("Subscription plan ID is required.", nameof(planId));

        return await dbContext.SubscriptionPlans
            .SingleOrDefaultAsync(x => x.Id == planId, cancellationToken)
            ?? throw new KeyNotFoundException("Subscription plan was not found.");
    }

    private static InvalidOperationException DuplicateCode(string code, Exception? inner = null) =>
        new($"A subscription plan with code '{code}' already exists.", inner);

    private static SubscriptionPlanItem ToItem(SubscriptionPlan plan) =>
        new(
            plan.Id,
            plan.Code,
            plan.NameEn,
            plan.NameAr,
            plan.DescriptionEn,
            plan.DescriptionAr,
            plan.BillingInterval,
            plan.Price,
            plan.Currency,
            plan.TrialDays,
            plan.GracePeriodDays,
            plan.IsEnabled,
            plan.SortOrder,
            plan.GatewayProductId,
            plan.GatewayPlanId,
            plan.CreatedAtUtc,
            plan.UpdatedAtUtc);
}