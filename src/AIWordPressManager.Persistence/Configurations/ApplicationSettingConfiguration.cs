using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class ApplicationSettingConfiguration : IEntityTypeConfiguration<ApplicationSetting>
{
    public void Configure(EntityTypeBuilder<ApplicationSetting> builder)
    {
        builder.ToTable("ApplicationSettings");
        builder.ConfigureEntity();
        builder.Property(x => x.Key).HasMaxLength(200).IsRequired();
        builder.Property(x => x.Value).IsRequired();
        builder.HasIndex(x => x.Key).IsUnique();
    }
}
