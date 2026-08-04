using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class DatabaseVersionConfiguration : IEntityTypeConfiguration<DatabaseVersion>
{
    public void Configure(EntityTypeBuilder<DatabaseVersion> builder)
    {
        builder.ToTable("DatabaseVersions");
        builder.ConfigureEntity();
        builder.Property(x => x.MigrationId).HasMaxLength(200).IsRequired();
        builder.Property(x => x.AppliedAtUtc).IsRequired();
        builder.HasIndex(x => x.MigrationId).IsUnique();
    }
}
