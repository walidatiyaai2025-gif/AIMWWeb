using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class SiteCredentialConfiguration : IEntityTypeConfiguration<SiteCredential>
{
    public void Configure(EntityTypeBuilder<SiteCredential> builder)
    {
        builder.ToTable("SiteCredentials");
        builder.ConfigureEntity();
        builder.Property(x => x.UserName).HasMaxLength(200).IsRequired();
        builder.Property(x => x.ProtectedApplicationPassword).IsRequired();
        builder.HasIndex(x => x.SiteId).IsUnique();
        builder.HasQueryFilter(x => !x.Site.IsDeleted);
        builder.HasOne(x => x.Site).WithOne().HasForeignKey<SiteCredential>(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
    }
}
