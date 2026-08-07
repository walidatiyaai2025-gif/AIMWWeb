using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable
namespace AIWordPressManager.Persistence.Migrations;

[Migration("20260808011500_AddEmailSchedules")]
public partial class AddEmailSchedules : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable(
            name: "EmailSchedules",
            columns: table => new
            {
                Id = table.Column<Guid>(type: "TEXT", nullable: false),
                OwnerUserId = table.Column<Guid>(type: "TEXT", nullable: false),
                SiteId = table.Column<Guid>(type: "TEXT", nullable: true),
                Scope = table.Column<string>(type: "TEXT", maxLength: 16, nullable: false),
                ReportType = table.Column<string>(type: "TEXT", maxLength: 120, nullable: false),
                TemplateKey = table.Column<string>(type: "TEXT", maxLength: 160, nullable: false),
                TimezoneId = table.Column<string>(type: "TEXT", maxLength: 120, nullable: false),
                Frequency = table.Column<string>(type: "TEXT", maxLength: 32, nullable: false),
                TimeOfDay = table.Column<TimeSpan>(type: "TEXT", nullable: false),
                Weekday = table.Column<int>(type: "INTEGER", nullable: true),
                MonthDay = table.Column<int>(type: "INTEGER", nullable: true),
                IsEnabled = table.Column<bool>(type: "INTEGER", nullable: false),
                RetryCount = table.Column<int>(type: "INTEGER", nullable: false),
                RetryDelayMinutes = table.Column<int>(type: "INTEGER", nullable: false),
                ConsecutiveFailures = table.Column<int>(type: "INTEGER", nullable: false),
                NextRunUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                LastRunUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                ActiveOccurrenceUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                LastStatus = table.Column<string>(type: "TEXT", maxLength: 32, nullable: false),
                LastError = table.Column<string>(type: "TEXT", maxLength: 1000, nullable: true),
                ClaimToken = table.Column<string>(type: "TEXT", maxLength: 100, nullable: true),
                ClaimedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ConcurrencyToken = table.Column<byte[]>(type: "BLOB", nullable: false)
            },
            constraints: table =>
            {
                table.PrimaryKey("PK_EmailSchedules", x => x.Id);
                table.ForeignKey("FK_EmailSchedules_Sites_SiteId", x => x.SiteId, "Sites", "Id", onDelete: ReferentialAction.Cascade);
            });

        migrationBuilder.CreateIndex("IX_EmailSchedules_OwnerUserId_SiteId", "EmailSchedules", new[] { "OwnerUserId", "SiteId" });
        migrationBuilder.CreateIndex("IX_EmailSchedules_IsEnabled_NextRunUtc", "EmailSchedules", new[] { "IsEnabled", "NextRunUtc" });
        migrationBuilder.CreateIndex("IX_EmailSchedules_ClaimedAtUtc", "EmailSchedules", "ClaimedAtUtc");
    }

    protected override void Down(MigrationBuilder migrationBuilder) => migrationBuilder.DropTable("EmailSchedules");
}
