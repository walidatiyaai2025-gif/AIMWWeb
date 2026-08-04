using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
namespace AIWordPressManager.Persistence.Configurations;
public sealed class WordPressTagRecordConfiguration : IEntityTypeConfiguration<WordPressTagRecord>
{ public void Configure(EntityTypeBuilder<WordPressTagRecord> b) { b.ToTable("WordPressTagRecords"); b.HasKey(x=>x.Id); b.Property(x=>x.Name).HasMaxLength(300).IsRequired(); b.Property(x=>x.Slug).HasMaxLength(300).IsRequired(); b.Property(x=>x.ConcurrencyToken).IsConcurrencyToken(); b.HasIndex(x=>new{x.SiteId,x.WordPressId}).IsUnique(); b.HasIndex(x=>new{x.SiteId,x.Slug}).IsUnique(); b.HasOne(x=>x.Site).WithMany().HasForeignKey(x=>x.SiteId).OnDelete(DeleteBehavior.Cascade); } }
