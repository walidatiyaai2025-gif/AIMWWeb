using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence;

public sealed class AppDbContext(DbContextOptions<AppDbContext> options) : DbContext(options)
{
    public DbSet<Site> Sites => Set<Site>();
    public DbSet<SiteCredential> SiteCredentials => Set<SiteCredential>();
    public DbSet<SiteEmailRecipient> SiteEmailRecipients => Set<SiteEmailRecipient>();
    public DbSet<SiteMailProfile> SiteMailProfiles => Set<SiteMailProfile>();
    public DbSet<AccountEmailRecipient> AccountEmailRecipients => Set<AccountEmailRecipient>();
    public DbSet<AccountMailProfile> AccountMailProfiles => Set<AccountMailProfile>();
    public DbSet<AuthUser> AuthUsers => Set<AuthUser>();
    public DbSet<LoginAudit> LoginAudits => Set<LoginAudit>();
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

        modelBuilder.Entity<LoginAudit>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasIndex(x => x.AttemptedAtUtc);
            entity.HasIndex(x => new { x.UserName, x.AttemptedAtUtc });
            entity.Property(x => x.UserName).HasMaxLength(128).IsRequired();
            entity.Property(x => x.Reason).HasMaxLength(256).IsRequired();
            entity.Property(x => x.IpAddress).HasMaxLength(64).IsRequired();
            entity.Property(x => x.UserAgent).HasMaxLength(1024).IsRequired();
        });

        modelBuilder.Entity<SiteEmailRecipient>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasIndex(x => new { x.SiteId, x.NormalizedEmailAddress }).IsUnique();
            entity.HasIndex(x => new { x.OwnerUserId, x.SiteId });
            entity.Property(x => x.EmailAddress).HasMaxLength(320).IsRequired();
            entity.Property(x => x.NormalizedEmailAddress).HasMaxLength(320).IsRequired();
            entity.Property(x => x.DisplayName).HasMaxLength(120);
            entity.HasOne<Site>().WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
        });

        modelBuilder.Entity<SiteMailProfile>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasIndex(x => x.SiteId).IsUnique();
            entity.HasIndex(x => new { x.OwnerUserId, x.SiteId });
            entity.Property(x => x.Host).HasMaxLength(255).IsRequired();
            entity.Property(x => x.UserName).HasMaxLength(320).IsRequired();
            entity.Property(x => x.ProtectedPassword);
            entity.Property(x => x.FromAddress).HasMaxLength(320).IsRequired();
            entity.Property(x => x.FromName).HasMaxLength(160).IsRequired();
            entity.Property(x => x.ReplyToAddress).HasMaxLength(320).IsRequired();
            entity.HasOne<Site>().WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
        });

        modelBuilder.Entity<AccountEmailRecipient>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasIndex(x => new { x.OwnerUserId, x.NormalizedEmailAddress }).IsUnique();
            entity.Property(x => x.EmailAddress).HasMaxLength(320).IsRequired();
            entity.Property(x => x.NormalizedEmailAddress).HasMaxLength(320).IsRequired();
            entity.Property(x => x.DisplayName).HasMaxLength(120);
            entity.HasOne<AuthUser>().WithMany().HasForeignKey(x => x.OwnerUserId).OnDelete(DeleteBehavior.Cascade);
        });

        modelBuilder.Entity<AccountMailProfile>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasIndex(x => x.OwnerUserId).IsUnique();
            entity.Property(x => x.Host).HasMaxLength(255).IsRequired();
            entity.Property(x => x.UserName).HasMaxLength(320).IsRequired();
            entity.Property(x => x.FromAddress).HasMaxLength(320).IsRequired();
            entity.Property(x => x.FromName).HasMaxLength(160).IsRequired();
            entity.Property(x => x.ReplyToAddress).HasMaxLength(320).IsRequired();
            entity.HasOne<AuthUser>().WithMany().HasForeignKey(x => x.OwnerUserId).OnDelete(DeleteBehavior.Cascade);
        });
    }
}
