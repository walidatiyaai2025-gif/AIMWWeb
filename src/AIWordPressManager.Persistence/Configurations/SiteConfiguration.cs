using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class SiteConfiguration : IEntityTypeConfiguration<Site>
{
    public void Configure(EntityTypeBuilder<Site> builder)
    {
        builder.ToTable("Sites");
        builder.ConfigureEntity();
        builder.Property(x => x.Name).HasMaxLength(200).IsRequired();
        builder.Property(x => x.SiteUrl).HasMaxLength(2048).IsRequired();
        builder.Property(x => x.HomeUrl).HasMaxLength(2048);
        builder.Property(x => x.WordPressVersion).HasMaxLength(50);
        builder.Property(x => x.LanguageCode).HasMaxLength(20);
        builder.Property(x => x.ConnectionStatus).HasConversion<string>().HasMaxLength(40).IsRequired();
        builder.Property(x => x.IsDeleted).HasDefaultValue(false);
        builder.HasIndex(x => x.SiteUrl).IsUnique();
        builder.HasQueryFilter(x => !x.IsDeleted);
    }
}
