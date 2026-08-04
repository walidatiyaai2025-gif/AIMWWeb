using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class BackupRecordConfiguration : IEntityTypeConfiguration<BackupRecord>
{
    public void Configure(EntityTypeBuilder<BackupRecord> builder)
    {
        builder.ToTable("Backups");
        builder.ConfigureEntity();
        builder.Property(x => x.FilePath).HasMaxLength(2048).IsRequired();
        builder.HasIndex(x => x.CreatedAtUtc);
    }
}
