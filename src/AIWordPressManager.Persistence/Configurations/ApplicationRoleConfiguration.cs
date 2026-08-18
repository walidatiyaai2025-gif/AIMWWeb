using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class ApplicationRoleConfiguration : IEntityTypeConfiguration<ApplicationRole>
{
    public void Configure(EntityTypeBuilder<ApplicationRole> builder)
    {
        builder.ToTable("ApplicationRoles");
        builder.ConfigureEntity();
        builder.Property(x => x.Name).HasMaxLength(64).IsRequired();
        builder.Property(x => x.NormalizedName).HasMaxLength(64).IsRequired();
        builder.Property(x => x.DisplayNameEn).HasMaxLength(120).IsRequired();
        builder.Property(x => x.DisplayNameAr).HasMaxLength(120).IsRequired();
        builder.HasIndex(x => x.NormalizedName).IsUnique();
    }
}