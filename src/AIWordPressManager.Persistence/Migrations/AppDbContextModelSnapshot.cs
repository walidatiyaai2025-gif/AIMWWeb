using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Metadata;

#nullable disable

namespace AIWordPressManager.Persistence.Migrations;

[DbContext(typeof(AppDbContext))]
partial class AppDbContextModelSnapshot : ModelSnapshot
{
    protected override void BuildModel(ModelBuilder modelBuilder)
    {
#pragma warning disable 612, 618
        modelBuilder.HasAnnotation("ProductVersion", "8.0.13");
        modelBuilder.Entity("AIWordPressManager.Domain.Entities.ApplicationSetting", b =>
        {
            b.Property<Guid>("Id"); b.Property<string>("Key").IsRequired().HasMaxLength(200); b.Property<string>("Value").IsRequired();
            b.Property<DateTime>("CreatedAtUtc"); b.Property<DateTime>("UpdatedAtUtc"); b.Property<byte[]>("ConcurrencyToken").IsConcurrencyToken().IsRequired();
            b.HasKey("Id"); b.HasIndex("Key").IsUnique(); b.ToTable("ApplicationSettings");
        });
        modelBuilder.Entity("AIWordPressManager.Domain.Entities.BackupRecord", b =>
        {
            b.Property<Guid>("Id"); b.Property<string>("FilePath").IsRequired().HasMaxLength(2048); b.Property<long>("FileSizeBytes"); b.Property<bool>("IsVerified");
            b.Property<DateTime>("CreatedAtUtc"); b.Property<DateTime>("UpdatedAtUtc"); b.Property<byte[]>("ConcurrencyToken").IsConcurrencyToken().IsRequired();
            b.HasKey("Id"); b.HasIndex("CreatedAtUtc"); b.ToTable("Backups");
        });
        modelBuilder.Entity("AIWordPressManager.Domain.Entities.DatabaseVersion", b =>
        {
            b.Property<Guid>("Id"); b.Property<string>("MigrationId").IsRequired().HasMaxLength(200); b.Property<DateTime>("AppliedAtUtc");
            b.Property<DateTime>("CreatedAtUtc"); b.Property<DateTime>("UpdatedAtUtc"); b.Property<byte[]>("ConcurrencyToken").IsConcurrencyToken().IsRequired();
            b.HasKey("Id"); b.HasIndex("MigrationId").IsUnique(); b.ToTable("DatabaseVersions");
        });
        modelBuilder.Entity("AIWordPressManager.Domain.Entities.Site", b =>
        {
            b.Property<Guid>("Id"); b.Property<string>("Name").IsRequired().HasMaxLength(200); b.Property<string>("SiteUrl").IsRequired().HasMaxLength(2048);
            b.Property<string>("HomeUrl").HasMaxLength(2048); b.Property<string>("WordPressVersion").HasMaxLength(50); b.Property<string>("LanguageCode").HasMaxLength(20);
            b.Property<string>("ConnectionStatus").IsRequired().HasMaxLength(40); b.Property<DateTime?>("LastConnectionTestAtUtc"); b.Property<bool>("IsDeleted").HasDefaultValue(false); b.Property<DateTime?>("DeletedAtUtc");
            b.Property<DateTime>("CreatedAtUtc"); b.Property<DateTime>("UpdatedAtUtc"); b.Property<byte[]>("ConcurrencyToken").IsConcurrencyToken().IsRequired();
            b.HasKey("Id"); b.HasIndex("SiteUrl").IsUnique(); b.ToTable("Sites");
        });
        modelBuilder.Entity("AIWordPressManager.Domain.Entities.SiteCredential", b =>
        {
            b.Property<Guid>("Id"); b.Property<Guid>("SiteId"); b.Property<string>("UserName").IsRequired().HasMaxLength(200); b.Property<string>("ProtectedApplicationPassword").IsRequired();
            b.Property<DateTime>("CreatedAtUtc"); b.Property<DateTime>("UpdatedAtUtc"); b.Property<byte[]>("ConcurrencyToken").IsConcurrencyToken().IsRequired();
            b.HasKey("Id"); b.HasIndex("SiteId").IsUnique(); b.ToTable("SiteCredentials");
        });
        modelBuilder.Entity("AIWordPressManager.Domain.Entities.SiteCredential", b =>
        {
            b.HasOne("AIWordPressManager.Domain.Entities.Site", "Site").WithOne().HasForeignKey("AIWordPressManager.Domain.Entities.SiteCredential", "SiteId").OnDelete(DeleteBehavior.Cascade).IsRequired();
            b.Navigation("Site");
        });
#pragma warning restore 612, 618
    }
}
