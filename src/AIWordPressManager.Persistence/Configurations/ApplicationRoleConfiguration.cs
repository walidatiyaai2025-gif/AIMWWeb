using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class ApplicationRoleConfiguration : IEntityTypeConfiguration<ApplicationRole>
{
    public void Configure(EntityTypeBuilder<ApplicationRole> entity)
    {
        entity.ToTable("ApplicationRoles");
        entity.HasKey(x => x.Id);
        entity.HasIndex(x => x.NormalizedName).IsUnique();
        entity.Property(x => x.Name).HasMaxLength(64).IsRequired();
        entity.Property(x => x.NormalizedName).HasMaxLength(64).IsRequired();
        entity.Property(x => x.DisplayNameEnglish).HasMaxLength(120).IsRequired();
        entity.Property(x => x.DisplayNameArabic).HasMaxLength(120).IsRequired();
    }
}

public sealed class ApplicationRoleGrantConfiguration : IEntityTypeConfiguration<ApplicationRoleGrant>
{
    public void Configure(EntityTypeBuilder<ApplicationRoleGrant> entity)
    {
        entity.ToTable("ApplicationRoleGrants");
        entity.HasKey(x => x.Id);
        entity.HasIndex(x => new { x.ApplicationRoleId, x.Permission }).IsUnique();
        entity.Property(x => x.Permission).HasMaxLength(80).IsRequired();
        entity.HasOne<ApplicationRole>()
            .WithMany()
            .HasForeignKey(x => x.ApplicationRoleId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}
