using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class BrokenLinkRecordConfiguration : IEntityTypeConfiguration<BrokenLinkRecord>
{
    public void Configure(EntityTypeBuilder<BrokenLinkRecord> builder)
    {
        builder.ToTable("BrokenLinks");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.SourceUrl).HasMaxLength(2048).IsRequired();
        builder.Property(x => x.TargetUrl).HasMaxLength(2048).IsRequired();
        builder.Property(x => x.Status).HasMaxLength(40).IsRequired();
        builder.Property(x => x.ErrorMessage).HasMaxLength(2000);
        builder.Property(x => x.ConcurrencyToken).IsConcurrencyToken();
        builder.HasIndex(x => new { x.SiteId, x.Status });
        builder.HasIndex(x => x.CheckedAtUtc);
        builder.HasIndex(x => x.TargetUrl);
        builder.HasOne(x => x.Site).WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.ContentRecord).WithMany().HasForeignKey(x => x.ContentRecordId).OnDelete(DeleteBehavior.Cascade);
    }
}
