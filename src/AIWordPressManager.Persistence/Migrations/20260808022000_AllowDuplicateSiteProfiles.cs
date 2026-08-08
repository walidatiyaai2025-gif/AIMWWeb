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
        migrationBuilder.DropIndex(
            name: "IX_Sites_SiteUrl",
            table: "Sites");

        migrationBuilder.CreateIndex(
            name: "IX_Sites_OwnerUserId_SiteUrl",
            table: "Sites",
            columns: new[] { "OwnerUserId", "SiteUrl" });
    }

    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.DropIndex(
            name: "IX_Sites_OwnerUserId_SiteUrl",
            table: "Sites");

        migrationBuilder.CreateIndex(
            name: "IX_Sites_SiteUrl",
            table: "Sites",
            column: "SiteUrl",
            unique: true);
    }
}
