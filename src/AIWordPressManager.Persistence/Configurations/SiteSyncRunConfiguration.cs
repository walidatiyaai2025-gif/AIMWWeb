using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class SiteSyncRunConfiguration : IEntityTypeConfiguration<SiteSyncRun>
{
    public void Configure(EntityTypeBuilder<SiteSyncRun> builder)
    {
        builder.ToTable("SiteSyncRuns");
        builder.ConfigureEntity();
        builder.Property(x => x.Status).HasMaxLength(32).IsRequired();
        builder.Property(x => x.Message).HasMaxLength(2000).IsRequired();
        builder.HasIndex(x => new { x.SiteId, x.StartedAtUtc });
        builder.HasOne(x => x.Site)
            .WithMany()
            .HasForeignKey(x => x.SiteId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}
