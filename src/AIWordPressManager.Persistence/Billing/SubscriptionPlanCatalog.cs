using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Billing;

public sealed class SubscriptionPlanCatalog(AppDbContext dbContext) : ISubscriptionPlanCatalog
{
    public async Task<IReadOnlyList<SubscriptionPlanItem>> ListAsync(
        bool includeDisabled = false,
        CancellationToken cancellationToken = default)
    {
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
