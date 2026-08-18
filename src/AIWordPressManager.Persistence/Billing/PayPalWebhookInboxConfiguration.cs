using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Billing;

public sealed class PayPalWebhookInboxConfiguration : IEntityTypeConfiguration<PayPalWebhookInboxEvent>
{
    public void Configure(EntityTypeBuilder<PayPalWebhookInboxEvent> entity)
    {
        entity.ToTable("PayPalWebhookInboxEvents");
        entity.HasKey(x => x.Id);
        entity.HasIndex(x => x.NormalizedProviderEventId).IsUnique();
        entity.HasIndex(x => new { x.ProviderSubscriptionReference, x.OccurredAtUtc });
        entity.HasIndex(x => x.ReceivedAtUtc);
        entity.Property(x => x.ProviderEventId).HasMaxLength(200).IsRequired();
        entity.Property(x => x.NormalizedProviderEventId).HasMaxLength(200).IsRequired();
        entity.Property(x => x.EventType).HasMaxLength(160).IsRequired();
        entity.Property(x => x.ProviderSubscriptionReference).HasMaxLength(200).IsRequired();
        entity.Property(x => x.NormalizedState).HasMaxLength(32).IsRequired();
    }
}
