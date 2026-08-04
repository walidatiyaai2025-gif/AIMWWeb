using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace AIWordPressManager.Persistence.Configurations;

public sealed class ExecutionJobConfiguration : IEntityTypeConfiguration<ExecutionJob>
{
    public void Configure(EntityTypeBuilder<ExecutionJob> builder)
    {
        builder.ToTable("ExecutionJobs");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.JobType).HasMaxLength(80).IsRequired();
        builder.Property(x => x.Status).HasMaxLength(40).IsRequired();
        builder.Property(x => x.CurrentStep).HasMaxLength(300).IsRequired();
        builder.Property(x => x.ErrorDetails).HasMaxLength(4000);
        builder.Property(x => x.ConcurrencyToken).IsConcurrencyToken();
        builder.HasIndex(x => x.Status);
        builder.HasIndex(x => x.CreatedAtUtc);
        builder.HasOne(x => x.Site).WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
    }
}
