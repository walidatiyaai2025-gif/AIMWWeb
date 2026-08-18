using AIWordPressManager.Domain.Common;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Billing;

public enum PayPalWebhookProcessingStatus
{
    Pending = 1,
    Processing = 2,
    Processed = 3,
    Failed = 4
}

public sealed class PayPalWebhookProcessingState : Entity
{
    private PayPalWebhookProcessingState() { }

    public PayPalWebhookProcessingState(Guid inboxEventId, DateTime utcNow)
    {
        if (inboxEventId == Guid.Empty)
            throw new ArgumentException("Webhook inbox event ID is required.", nameof(inboxEventId));
        if (utcNow.Kind != DateTimeKind.Utc)
            throw new ArgumentException("Processing timestamp must be UTC.", nameof(utcNow));

        InboxEventId = inboxEventId;
        Status = PayPalWebhookProcessingStatus.Pending;
        MarkUpdated(utcNow);
    }

    public Guid InboxEventId { get; private set; }
    public PayPalWebhookProcessingStatus Status { get; private set; }
    public int AttemptCount { get; private set; }
    public DateTime? NextAttemptAtUtc { get; private set; }
    public string? ClaimToken { get; private set; }
    public DateTime? ClaimUntilUtc { get; private set; }
    public string? LastError { get; private set; }
    public DateTime? ProcessedAtUtc { get; private set; }
}

public sealed class PayPalWebhookProcessingStateConfiguration : IEntityTypeConfiguration<PayPalWebhookProcessingState>
{
    public void Configure(EntityTypeBuilder<PayPalWebhookProcessingState> builder)
    {
        builder.ToTable("PayPalWebhookProcessingStates");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Status).HasConversion<int>();
        builder.Property(x => x.ClaimToken).HasMaxLength(64);
        builder.Property(x => x.LastError).HasMaxLength(1000);
        builder.Property(x => x.ConcurrencyToken).IsConcurrencyToken();
        builder.HasIndex(x => x.InboxEventId).IsUnique();
        builder.HasIndex(x => new { x.Status, x.NextAttemptAtUtc });
        builder.HasIndex(x => x.ClaimUntilUtc);
        builder.HasOne<PayPalWebhookInboxEvent>()
            .WithMany()
            .HasForeignKey(x => x.InboxEventId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}
