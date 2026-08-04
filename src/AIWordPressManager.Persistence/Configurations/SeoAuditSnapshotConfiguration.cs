using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class SeoAuditSnapshotConfiguration : IEntityTypeConfiguration<SeoAuditSnapshot>
{
    public void Configure(EntityTypeBuilder<SeoAuditSnapshot> builder)
    {
        builder.ToTable("SeoAuditSnapshots");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.ConcurrencyToken).IsConcurrencyToken();
        builder.HasIndex(x => new { x.SiteId, x.CapturedAtUtc });
        builder.HasOne(x => x.Site).WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
    }
}
