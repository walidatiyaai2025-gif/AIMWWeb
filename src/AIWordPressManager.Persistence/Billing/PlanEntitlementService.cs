using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Billing;

public sealed class PlanEntitlementService(AppDbContext dbContext) :
    IPlanEntitlementCatalog,
    IPlanEntitlementResolver
{
    public IReadOnlyList<EntitlementDefinitionItem> ListDefinitions() =>
        EntitlementDefinitionCatalog.All
            .Select(x => new EntitlementDefinitionItem(x.Key, x.ValueType, x.RequiresNonNegativeNumber))
            .ToArray();

    public async Task<IReadOnlyList<PlanEntitlementItem>> ListAsync(
        Guid planId,
        CancellationToken cancellationToken = default)
    {
        await RequirePlanAsync(planId, cancellationToken);
        return await dbContext.PlanEntitlements.AsNoTracking()
            .Where(x => x.PlanId == planId)
            .OrderBy(x => x.NormalizedKey)
            .Select(x => new PlanEntitlementItem(
                x.Id,
                x.PlanId,
                x.Key,
                x.ValueType,
                x.Value,
                x.CreatedAtUtc,
                x.UpdatedAtUtc))
            .ToListAsync(cancellationToken);
    }

    public async Task<PlanEntitlementItem> SetAsync(
        Guid planId,
        string key,
        string? rawValue,
        CancellationToken cancellationToken = default)
    {
        var definition = EntitlementDefinitionCatalog.GetRequired(key);
        await RequirePlanAsync(planId, cancellationToken);
        var normalizedKey = EntitlementDefinitionCatalog.NormalizeKey(definition.Key);

        var existing = await dbContext.PlanEntitlements
            .SingleOrDefaultAsync(
                x => x.PlanId == planId && x.NormalizedKey == normalizedKey,
                cancellationToken);

        if (existing is not null)
        {
            existing.UpdateValue(rawValue, DateTime.UtcNow);
            await dbContext.SaveChangesAsync(cancellationToken);
            return ToItem(existing);
        }

        var entitlement = new PlanEntitlement(planId, definition.Key, rawValue, DateTime.UtcNow);
        dbContext.PlanEntitlements.Add(entitlement);
        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
        }
        catch (DbUpdateException ex)
        {
            dbContext.Entry(entitlement).State = EntityState.Detached;
            if (await EntitlementExistsAsync(planId, normalizedKey, cancellationToken))
            {
                throw new InvalidOperationException(
                    $"Entitlement '{definition.Key}' already exists for this plan. Reload the catalog before retrying the update.",
                    ex);
            }
            throw;
        }

        return ToItem(entitlement);
    }

    public async Task<bool> RemoveAsync(
        Guid planId,
        string key,
        CancellationToken cancellationToken = default)
    {
        var definition = EntitlementDefinitionCatalog.GetRequired(key);
        await RequirePlanAsync(planId, cancellationToken);
        var normalizedKey = EntitlementDefinitionCatalog.NormalizeKey(definition.Key);
        var existing = await dbContext.PlanEntitlements
            .SingleOrDefaultAsync(
                x => x.PlanId == planId && x.NormalizedKey == normalizedKey,
                cancellationToken);
        if (existing is null) return false;

        dbContext.PlanEntitlements.Remove(existing);
        await dbContext.SaveChangesAsync(cancellationToken);
        return true;
    }

    public async Task<PlanEntitlementResolution> ResolveAsync(
        Guid planId,
        string key,
        CancellationToken cancellationToken = default)
    {
        var definition = EntitlementDefinitionCatalog.GetRequired(key);
        await RequirePlanAsync(planId, cancellationToken);
        var normalizedKey = EntitlementDefinitionCatalog.NormalizeKey(definition.Key);
        var stored = await dbContext.PlanEntitlements.AsNoTracking()
            .Where(x => x.PlanId == planId && x.NormalizedKey == normalizedKey)
            .Select(x => new { x.ValueType, x.Value })
            .SingleOrDefaultAsync(cancellationToken);

        if (stored is null)
            return PlanEntitlementResolution.Missing(planId, definition);
        if (stored.ValueType != definition.ValueType)
            throw new InvalidOperationException($"Stored entitlement type for '{definition.Key}' does not match its canonical definition.");

        return ToResolution(planId, definition, stored.Value);
    }

    public async Task<BooleanEntitlementCheck> CheckBooleanCapabilityAsync(
        Guid planId,
        string key,
        CancellationToken cancellationToken = default)
    {
        var definition = EntitlementDefinitionCatalog.GetRequired(key);
        if (definition.ValueType != EntitlementValueType.Boolean)
            throw new ArgumentException($"Entitlement '{definition.Key}' is not a Boolean capability.", nameof(key));

        var resolved = await ResolveAsync(planId, definition.Key, cancellationToken);
        return new BooleanEntitlementCheck(
            resolved.IsConfigured,
            definition.Key,
            resolved.IsConfigured && resolved.BooleanValue == true);
    }

    public async Task<IntegerEntitlementLimitCheck> CheckIntegerLimitAsync(
        Guid planId,
        string key,
        long currentUsage,
        long requestedAdditional = 1,
        CancellationToken cancellationToken = default)
    {
        if (currentUsage < 0)
            throw new ArgumentOutOfRangeException(nameof(currentUsage), "Current usage cannot be negative.");
        if (requestedAdditional < 0)
            throw new ArgumentOutOfRangeException(nameof(requestedAdditional), "Requested additional usage cannot be negative.");

        var definition = EntitlementDefinitionCatalog.GetRequired(key);
        if (definition.ValueType != EntitlementValueType.Integer)
            throw new ArgumentException($"Entitlement '{definition.Key}' is not an Integer limit.", nameof(key));

        var resolved = await ResolveAsync(planId, definition.Key, cancellationToken);
        if (!resolved.IsConfigured || resolved.IntegerValue is null)
        {
            return new IntegerEntitlementLimitCheck(
                false,
                definition.Key,
                null,
                currentUsage,
                requestedAdditional,
                false);
        }

        var limit = resolved.IntegerValue.Value;
        var allowed = currentUsage <= limit && requestedAdditional <= limit - currentUsage;
        return new IntegerEntitlementLimitCheck(
            true,
            definition.Key,
            limit,
            currentUsage,
            requestedAdditional,
            allowed);
    }

    private async Task RequirePlanAsync(Guid planId, CancellationToken cancellationToken)
    {
        if (planId == Guid.Empty)
            throw new ArgumentException("Subscription plan ID is required.", nameof(planId));

        if (!await dbContext.SubscriptionPlans.AsNoTracking().AnyAsync(x => x.Id == planId, cancellationToken))
            throw new KeyNotFoundException("Subscription plan was not found.");
    }

    private Task<bool> EntitlementExistsAsync(
        Guid planId,
        string normalizedKey,
        CancellationToken cancellationToken) =>
        dbContext.PlanEntitlements.AsNoTracking()
            .AnyAsync(x => x.PlanId == planId && x.NormalizedKey == normalizedKey, cancellationToken);

    private static PlanEntitlementItem ToItem(PlanEntitlement value) =>
        new(
            value.Id,
            value.PlanId,
            value.Key,
            value.ValueType,
            value.Value,
            value.CreatedAtUtc,
            value.UpdatedAtUtc);

    private static PlanEntitlementResolution ToResolution(
        Guid planId,
        EntitlementDefinition definition,
        string canonicalValue)
    {
        return definition.ValueType switch
        {
            EntitlementValueType.Boolean => new(
                true, planId, definition.Key, definition.ValueType, canonicalValue,
                EntitlementValueCodec.ParseBoolean(canonicalValue), null, null, null),
            EntitlementValueType.Integer => new(
                true, planId, definition.Key, definition.ValueType, canonicalValue,
                null, EntitlementValueCodec.ParseInteger(canonicalValue), null, null),
            EntitlementValueType.Decimal => new(
                true, planId, definition.Key, definition.ValueType, canonicalValue,
                null, null, EntitlementValueCodec.ParseDecimal(canonicalValue), null),
            EntitlementValueType.String => new(
                true, planId, definition.Key, definition.ValueType, canonicalValue,
                null, null, null, canonicalValue),
            _ => throw new InvalidOperationException($"Unsupported entitlement type '{definition.ValueType}'.")
        };
    }
}
