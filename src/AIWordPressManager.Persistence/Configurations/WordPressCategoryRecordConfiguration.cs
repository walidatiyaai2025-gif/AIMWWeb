using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class WordPressCategoryRecordConfiguration : IEntityTypeConfiguration<WordPressCategoryRecord>
{
    public void Configure(EntityTypeBuilder<WordPressCategoryRecord> builder)
    {
        builder.ToTable("WordPressCategoryRecords");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Name).HasMaxLength(300).IsRequired();
        builder.Property(x => x.Slug).HasMaxLength(300).IsRequired();
        builder.Property(x => x.ConcurrencyToken).IsConcurrencyToken();
        builder.HasIndex(x => new { x.SiteId, x.WordPressId }).IsUnique();
        builder.HasIndex(x => new { x.SiteId, x.Slug }).IsUnique();
        builder.HasOne(x => x.Site).WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
    }
}
