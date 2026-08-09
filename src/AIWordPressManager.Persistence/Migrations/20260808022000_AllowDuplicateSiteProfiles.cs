using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace AIWordPressManager.Persistence.Migrations;

[DbContext(typeof(AppDbContext))]
[Migration("20260808022000_AllowDuplicateSiteProfiles")]
public partial class AllowDuplicateSiteProfiles : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        // Legacy databases do not necessarily have Sites.OwnerUserId yet.
        // Do not reference that compatibility column during EF migration execution,
        // because DatabaseInitializationService adds it immediately after MigrateAsync.
        migrationBuilder.Sql("DROP INDEX IF EXISTS IX_Sites_SiteUrl;");
        migrationBuilder.Sql("DROP INDEX IF EXISTS IX_Sites_OwnerUserId_SiteUrl;");

        // Keep URL lookups indexed while allowing duplicates. The final owner+URL
        // index is created by the SQLite compatibility step after OwnerUserId exists.
        migrationBuilder.Sql("CREATE INDEX IF NOT EXISTS IX_Sites_SiteUrl ON Sites (SiteUrl);");
    }

    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.Sql("DROP INDEX IF EXISTS IX_Sites_OwnerUserId_SiteUrl;");
        migrationBuilder.Sql("DROP INDEX IF EXISTS IX_Sites_SiteUrl;");
        migrationBuilder.Sql("CREATE UNIQUE INDEX IF NOT EXISTS IX_Sites_SiteUrl ON Sites (SiteUrl);");
    }
}
