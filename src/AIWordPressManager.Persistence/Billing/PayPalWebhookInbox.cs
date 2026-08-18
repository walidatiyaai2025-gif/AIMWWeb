using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Billing;

public sealed class PayPalWebhookInbox(AppDbContext dbContext) : IPayPalWebhookInbox
{
    public async Task<PayPalWebhookInboxAcceptResult> AcceptVerifiedAsync(
        GatewayVerifiedEvent gatewayEvent,
        DateTime receivedAtUtc,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(gatewayEvent);
        if (gatewayEvent.Authority != GatewayEvidenceAuthority.VerifiedWebhook)
            throw new ArgumentException("Only verified webhook evidence can enter the PayPal webhook inbox.", nameof(gatewayEvent));
        if (receivedAtUtc.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Webhook receive timestamp must be UTC.", nameof(receivedAtUtc));

        var normalizedProviderEventId = gatewayEvent.ProviderEventId.Trim().ToUpperInvariant();
        var existing = await FindByNormalizedIdAsync(normalizedProviderEventId, cancellationToken);
        if (existing is not null)
            return new(false, EnsureDuplicateMatches(existing, gatewayEvent));

        var entity = new PayPalWebhookInboxEvent(
            gatewayEvent.ProviderEventId,
            gatewayEvent.EventType,
            gatewayEvent.ProviderSubscriptionReference,
            gatewayEvent.State.ToString(),
            gatewayEvent.OccurredAtUtc,
            receivedAtUtc);
        dbContext.Set<PayPalWebhookInboxEvent>().Add(entity);

        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
            return new(true, ToItem(entity));
        }
        catch (DbUpdateException ex)
        {
            dbContext.Entry(entity).State = EntityState.Detached;
            var raced = await FindByNormalizedIdAsync(normalizedProviderEventId, cancellationToken);
            if (raced is null) throw;

            try
            {
                return new(false, EnsureDuplicateMatches(raced, gatewayEvent));
            }
            catch (InvalidOperationException conflict)
            {
                throw new InvalidOperationException(
                    "A verified PayPal event ID collided with different normalized event data.",
                    new AggregateException(ex, conflict));
            }
        }
    }

    public async Task<PayPalWebhookInboxItem?> GetByProviderEventIdAsync(
        string providerEventId,
        CancellationToken cancellationToken = default)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(providerEventId);
        var normalized = providerEventId.Trim().ToUpperInvariant();
        var entity = await FindByNormalizedIdAsync(normalized, cancellationToken);
        return entity is null ? null : ToItem(entity);
    }

    private Task<PayPalWebhookInboxEvent?> FindByNormalizedIdAsync(
        string normalizedProviderEventId,
        CancellationToken cancellationToken) =>
        dbContext.Set<PayPalWebhookInboxEvent>().AsNoTracking()
            .SingleOrDefaultAsync(x => x.NormalizedProviderEventId == normalizedProviderEventId, cancellationToken);

    private static PayPalWebhookInboxItem EnsureDuplicateMatches(
        PayPalWebhookInboxEvent existing,
        GatewayVerifiedEvent incoming)
    {
        if (!string.Equals(existing.EventType, incoming.EventType, StringComparison.Ordinal) ||
            !string.Equals(existing.ProviderSubscriptionReference, incoming.ProviderSubscriptionReference, StringComparison.Ordinal) ||
            !string.Equals(existing.NormalizedState, incoming.State.ToString(), StringComparison.Ordinal) ||
            existing.OccurredAtUtc != incoming.OccurredAtUtc)
        {
            throw new InvalidOperationException(
                "A verified PayPal event ID was replayed with different normalized event data.");
        }

        return ToItem(existing);
    }

    private static PayPalWebhookInboxItem ToItem(PayPalWebhookInboxEvent value)
    {
        if (!Enum.TryParse<GatewaySubscriptionState>(value.NormalizedState, false, out var state))
            throw new InvalidOperationException("Stored PayPal webhook state is not recognized.");

        return new(
            value.Id,
            value.ProviderEventId,
            value.EventType,
            value.ProviderSubscriptionReference,
            state,
            value.OccurredAtUtc,
            value.ReceivedAtUtc,
            value.CreatedAtUtc);
    }
}
