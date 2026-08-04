using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class WordPressContentRecordConfiguration : IEntityTypeConfiguration<WordPressContentRecord>
{
    public void Configure(EntityTypeBuilder<WordPressContentRecord> builder)
    {
        builder.ToTable("WordPressContentRecords");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.ContentType).HasMaxLength(32).IsRequired();
        builder.Property(x => x.Title).HasMaxLength(500).IsRequired();
        builder.Property(x => x.Slug).HasMaxLength(300).IsRequired();
        builder.Property(x => x.Status).HasMaxLength(32).IsRequired();
        builder.Property(x => x.Link).HasMaxLength(2048).IsRequired();
        builder.Property(x => x.RenderedContent).IsRequired();
        builder.Property(x => x.RenderedExcerpt).IsRequired();
        builder.Property(x => x.ConcurrencyToken).IsConcurrencyToken();
        builder.HasIndex(x => new { x.SiteId, x.ContentType, x.WordPressId }).IsUnique();
        builder.HasIndex(x => new { x.SiteId, x.Slug });
        builder.HasIndex(x => x.ModifiedAtUtc);
        builder.HasOne(x => x.Site).WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
    }
}
