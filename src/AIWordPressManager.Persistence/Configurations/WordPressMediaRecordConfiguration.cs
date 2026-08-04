using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
namespace AIWordPressManager.Persistence.Configurations;
public sealed class WordPressMediaRecordConfiguration : IEntityTypeConfiguration<WordPressMediaRecord>
{ public void Configure(EntityTypeBuilder<WordPressMediaRecord> b) { b.ToTable("WordPressMediaRecords"); b.HasKey(x=>x.Id); b.Property(x=>x.Title).HasMaxLength(500).IsRequired(); b.Property(x=>x.Slug).HasMaxLength(300).IsRequired(); b.Property(x=>x.MediaType).HasMaxLength(80).IsRequired(); b.Property(x=>x.MimeType).HasMaxLength(150).IsRequired(); b.Property(x=>x.SourceUrl).HasMaxLength(2048).IsRequired(); b.Property(x=>x.ConcurrencyToken).IsConcurrencyToken(); b.HasIndex(x=>new{x.SiteId,x.WordPressId}).IsUnique(); b.HasIndex(x=>x.ModifiedAtUtc); b.HasOne(x=>x.Site).WithMany().HasForeignKey(x=>x.SiteId).OnDelete(DeleteBehavior.Cascade); } }
