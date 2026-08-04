using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class ContentAuditIssueConfiguration : IEntityTypeConfiguration<ContentAuditIssue>
{
    public void Configure(EntityTypeBuilder<ContentAuditIssue> builder)
    {
        builder.ToTable("ContentAuditIssues");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.IssueCode).HasMaxLength(80).IsRequired();
        builder.Property(x => x.Severity).HasMaxLength(30).IsRequired();
        builder.Property(x => x.Title).HasMaxLength(300).IsRequired();
        builder.Property(x => x.Description).HasMaxLength(2000).IsRequired();
        builder.Property(x => x.ConcurrencyToken).IsConcurrencyToken();
        builder.HasIndex(x => new { x.SiteId, x.IssueCode });
        builder.HasIndex(x => x.DetectedAtUtc);
        builder.HasOne(x => x.Site).WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
        builder.HasOne(x => x.ContentRecord).WithMany().HasForeignKey(x => x.ContentRecordId).OnDelete(DeleteBehavior.Cascade);
    }
}
