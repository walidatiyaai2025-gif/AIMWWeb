using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class SuggestedChangeConfiguration : IEntityTypeConfiguration<SuggestedChange>
{
    public void Configure(EntityTypeBuilder<SuggestedChange> builder)
    {
        builder.ToTable("SuggestedChanges");
        builder.ConfigureEntity();
        builder.Property(x => x.Fingerprint).HasMaxLength(180).IsRequired();
        builder.Property(x => x.SourceType).HasMaxLength(60).IsRequired();
        builder.Property(x => x.ObjectType).HasMaxLength(60).IsRequired();
        builder.Property(x => x.ObjectId).HasMaxLength(120).IsRequired();
        builder.Property(x => x.ChangeType).HasMaxLength(100).IsRequired();
        builder.Property(x => x.CurrentValue).HasMaxLength(4000).IsRequired();
        builder.Property(x => x.ProposedValue).HasMaxLength(4000).IsRequired();
        builder.Property(x => x.Reason).HasMaxLength(2000).IsRequired();
        builder.Property(x => x.RiskLevel).HasMaxLength(30).IsRequired();
        builder.Property(x => x.ApprovalStatus).HasMaxLength(30).IsRequired();
        builder.Property(x => x.ExecutionStatus).HasMaxLength(30).IsRequired();
        builder.HasOne(x => x.Site).WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
        builder.HasIndex(x => new { x.SiteId, x.Fingerprint }).IsUnique();
        builder.HasIndex(x => new { x.SiteId, x.ApprovalStatus });
        builder.HasIndex(x => new { x.SiteId, x.RiskLevel });
        builder.HasIndex(x => x.CreatedAtUtc);
    }
}
