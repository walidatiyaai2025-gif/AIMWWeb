using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable
namespace AIWordPressManager.Persistence.Migrations;

[Migration("20260803110000_AddSeoAuditSnapshots")]
public partial class AddSeoAuditSnapshots : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable(
            name: "SeoAuditSnapshots",
            columns: table => new
            {
                Id = table.Column<Guid>(type: "TEXT", nullable: false),
                SiteId = table.Column<Guid>(type: "TEXT", nullable: false),
                Score = table.Column<int>(type: "INTEGER", nullable: false),
                AuditedItems = table.Column<int>(type: "INTEGER", nullable: false),
                HighIssues = table.Column<int>(type: "INTEGER", nullable: false),
                MediumIssues = table.Column<int>(type: "INTEGER", nullable: false),
                LowIssues = table.Column<int>(type: "INTEGER", nullable: false),
                CapturedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ConcurrencyToken = table.Column<byte[]>(type: "BLOB", nullable: false)
            },
            constraints: table =>
            {
                table.PrimaryKey("PK_SeoAuditSnapshots", x => x.Id);
                table.ForeignKey("FK_SeoAuditSnapshots_Sites_SiteId", x => x.SiteId, "Sites", "Id", onDelete: ReferentialAction.Cascade);
            });
        migrationBuilder.CreateIndex("IX_SeoAuditSnapshots_SiteId_CapturedAtUtc", "SeoAuditSnapshots", new[] { "SiteId", "CapturedAtUtc" });
    }
    protected override void Down(MigrationBuilder migrationBuilder) => migrationBuilder.DropTable("SeoAuditSnapshots");
}
