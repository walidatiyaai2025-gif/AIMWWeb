using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence;

public sealed class AppDbContext(DbContextOptions<AppDbContext> options) : DbContext(options)
{
    public DbSet<Site> Sites => Set<Site>();
    public DbSet<SiteCredential> SiteCredentials => Set<SiteCredential>();
    public DbSet<AuthUser> AuthUsers => Set<AuthUser>();
    public DbSet<ApplicationSetting> ApplicationSettings => Set<ApplicationSetting>();
    public DbSet<DatabaseVersion> DatabaseVersions => Set<DatabaseVersion>();
    public DbSet<BackupRecord> Backups => Set<BackupRecord>();
    public DbSet<WordPressContentRecord> WordPressContentRecords => Set<WordPressContentRecord>();
    public DbSet<WordPressCategoryRecord> WordPressCategoryRecords => Set<WordPressCategoryRecord>();
    public DbSet<WordPressTagRecord> WordPressTagRecords => Set<WordPressTagRecord>();
    public DbSet<WordPressMediaRecord> WordPressMediaRecords => Set<WordPressMediaRecord>();
    public DbSet<ExecutionJob> ExecutionJobs => Set<ExecutionJob>();
    public DbSet<ContentAuditIssue> ContentAuditIssues => Set<ContentAuditIssue>();
    public DbSet<SeoAuditIssue> SeoAuditIssues => Set<SeoAuditIssue>();
    public DbSet<SeoAuditSnapshot> SeoAuditSnapshots => Set<SeoAuditSnapshot>();
    public DbSet<BrokenLinkRecord> BrokenLinks => Set<BrokenLinkRecord>();
    public DbSet<SuggestedChange> SuggestedChanges => Set<SuggestedChange>();

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        base.OnModelCreating(modelBuilder);
        modelBuilder.ApplyConfigurationsFromAssembly(typeof(AppDbContext).Assembly);

        modelBuilder.Entity<AuthUser>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasIndex(x => x.NormalizedUserName).IsUnique();
            entity.Property(x => x.UserName).HasMaxLength(128).IsRequired();
            entity.Property(x => x.NormalizedUserName).HasMaxLength(128).IsRequired();
            entity.Property(x => x.PasswordHash).IsRequired();
            entity.Property(x => x.Role).HasMaxLength(64).IsRequired();
            entity.Property(x => x.LastPage).HasMaxLength(1024).IsRequired();
        });
    }
}
