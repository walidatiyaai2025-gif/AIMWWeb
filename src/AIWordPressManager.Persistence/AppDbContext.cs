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
    public DbSet<EmailSchedule> EmailSchedules => Set<EmailSchedule>();
    public DbSet<EmailOutboxMessage> EmailOutboxMessages => Set<EmailOutboxMessage>();
    public DbSet<EmailDeliveryAttempt> EmailDeliveryAttempts => Set<EmailDeliveryAttempt>();
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

        modelBuilder.Entity<EmailSchedule>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasIndex(x => new { x.OwnerUserId, x.SiteId });
            entity.HasIndex(x => new { x.IsEnabled, x.NextRunUtc });
            entity.HasIndex(x => x.ClaimedAtUtc);
            entity.Property(x => x.Scope).HasMaxLength(16).IsRequired();
            entity.Property(x => x.ReportType).HasMaxLength(120).IsRequired();
            entity.Property(x => x.TemplateKey).HasMaxLength(160).IsRequired();
            entity.Property(x => x.TimezoneId).HasMaxLength(120).IsRequired();
            entity.Property(x => x.Frequency).HasMaxLength(32).IsRequired();
            entity.Property(x => x.LastStatus).HasMaxLength(32).IsRequired();
            entity.Property(x => x.LastError).HasMaxLength(1000);
            entity.Property(x => x.ClaimToken).HasMaxLength(100);
            entity.HasOne<AuthUser>().WithMany().HasForeignKey(x => x.OwnerUserId).OnDelete(DeleteBehavior.Cascade);
            entity.HasOne<Site>().WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.Cascade);
        });

        modelBuilder.Entity<EmailOutboxMessage>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasIndex(x => new { x.OwnerUserId, x.IdempotencyKey }).IsUnique();
            entity.HasIndex(x => new { x.Status, x.NextAttemptAtUtc });
            entity.HasIndex(x => x.CorrelationId);
            entity.HasIndex(x => new { x.OwnerUserId, x.CreatedAtUtc });
            entity.Property(x => x.Scope).HasMaxLength(16).IsRequired();
            entity.Property(x => x.TemplateKey).HasMaxLength(160).IsRequired();
            entity.Property(x => x.Subject).HasMaxLength(500).IsRequired();
            entity.Property(x => x.HtmlBody).IsRequired();
            entity.Property(x => x.TextBody).IsRequired();
            entity.Property(x => x.RecipientsJson).IsRequired();
            entity.Property(x => x.IdempotencyKey).HasMaxLength(200).IsRequired();
            entity.Property(x => x.CorrelationId).HasMaxLength(100).IsRequired();
            entity.Property(x => x.Status).HasMaxLength(32).IsRequired();
            entity.Property(x => x.ClaimToken).HasMaxLength(100);
            entity.Property(x => x.LastError).HasMaxLength(1000);
            entity.HasOne<AuthUser>().WithMany().HasForeignKey(x => x.OwnerUserId).OnDelete(DeleteBehavior.Cascade);
            entity.HasOne<Site>().WithMany().HasForeignKey(x => x.SiteId).OnDelete(DeleteBehavior.SetNull);
        });

        modelBuilder.Entity<EmailDeliveryAttempt>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasIndex(x => new { x.OutboxMessageId, x.AttemptNumber }).IsUnique();
            entity.Property(x => x.Status).HasMaxLength(32).IsRequired();
            entity.Property(x => x.ProviderSummary).HasMaxLength(500);
            entity.Property(x => x.ErrorCategory).HasMaxLength(100);
            entity.Property(x => x.SanitizedError).HasMaxLength(1000);
            entity.HasOne<EmailOutboxMessage>().WithMany().HasForeignKey(x => x.OutboxMessageId).OnDelete(DeleteBehavior.Cascade);
        });
    }
}
