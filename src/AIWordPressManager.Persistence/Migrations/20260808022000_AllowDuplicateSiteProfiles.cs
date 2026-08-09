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
        // Older databases may contain either the original global unique index
        // or the intermediate owner+URL unique index. Remove both defensively.
        migrationBuilder.Sql("DROP INDEX IF EXISTS IX_Sites_SiteUrl;");
        migrationBuilder.Sql("DROP INDEX IF EXISTS IX_Sites_OwnerUserId_SiteUrl;");

        migrationBuilder.Sql("CREATE INDEX IF NOT EXISTS IX_Sites_OwnerUserId_SiteUrl ON Sites (OwnerUserId, SiteUrl);");
    }

    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.Sql("DROP INDEX IF EXISTS IX_Sites_OwnerUserId_SiteUrl;");
        migrationBuilder.Sql("CREATE UNIQUE INDEX IF NOT EXISTS IX_Sites_SiteUrl ON Sites (SiteUrl);");
    }
}
