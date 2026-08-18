using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class ApplicationRoleGrantConfiguration : IEntityTypeConfiguration<ApplicationRoleGrant>
{
    public void Configure(EntityTypeBuilder<ApplicationRoleGrant> builder)
    {
        builder.ToTable("ApplicationRoleGrants");
        builder.ConfigureEntity();
        builder.Property(x => x.Permission).HasMaxLength(120).IsRequired();
        builder.HasIndex(x => new { x.RoleId, x.Permission }).IsUnique();
        builder.HasOne<ApplicationRole>().WithMany().HasForeignKey(x => x.RoleId).OnDelete(DeleteBehavior.Cascade);
    }
}