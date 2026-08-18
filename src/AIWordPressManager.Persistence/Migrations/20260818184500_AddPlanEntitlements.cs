using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace AIWordPressManager.Persistence.Migrations;

[DbContext(typeof(AppDbContext))]
[Migration("20260818184500_AddPlanEntitlements")]
public partial class AddPlanEntitlements : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable(
            name: "PlanEntitlements",
            columns: table => new
            {
                Id = table.Column<Guid>(type: "TEXT", nullable: false),
                PlanId = table.Column<Guid>(type: "TEXT", nullable: false),
                Key = table.Column<string>(type: "TEXT", maxLength: 128, nullable: false),
                NormalizedKey = table.Column<string>(type: "TEXT", maxLength: 128, nullable: false),
                ValueType = table.Column<string>(type: "TEXT", maxLength: 16, nullable: false),
                Value = table.Column<string>(type: "TEXT", maxLength: 1000, nullable: false),
                CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ConcurrencyToken = table.Column<byte[]>(type: "BLOB", nullable: false)
            },
            constraints: table =>
            {
                table.PrimaryKey("PK_PlanEntitlements", x => x.Id);
                table.ForeignKey(
                    name: "FK_PlanEntitlements_SubscriptionPlans_PlanId",
                    column: x => x.PlanId,
                    principalTable: "SubscriptionPlans",
                    principalColumn: "Id",
                    onDelete: ReferentialAction.Cascade);
            });

        migrationBuilder.CreateIndex(
            name: "IX_PlanEntitlements_PlanId",
            table: "PlanEntitlements",
            column: "PlanId");

        migrationBuilder.CreateIndex(
            name: "IX_PlanEntitlements_PlanId_NormalizedKey",
            table: "PlanEntitlements",
            columns: new[] { "PlanId", "NormalizedKey" },
            unique: true);
    }

    protected override void Down(MigrationBuilder migrationBuilder) =>
        migrationBuilder.DropTable(name: "PlanEntitlements");
}
