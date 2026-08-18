using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace AIWordPressManager.Persistence.Migrations;

[DbContext(typeof(AppDbContext))]
[Migration("20260818183000_AddSubscriptionPlanCatalog")]
public partial class AddSubscriptionPlanCatalog : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable(
            name: "SubscriptionPlans",
            columns: table => new
            {
                Id = table.Column<Guid>(type: "TEXT", nullable: false),
                Code = table.Column<string>(type: "TEXT", maxLength: 64, nullable: false),
                NormalizedCode = table.Column<string>(type: "TEXT", maxLength: 64, nullable: false),
                NameEn = table.Column<string>(type: "TEXT", maxLength: 160, nullable: false),
                NameAr = table.Column<string>(type: "TEXT", maxLength: 160, nullable: false),
                DescriptionEn = table.Column<string>(type: "TEXT", maxLength: 1000, nullable: false),
                DescriptionAr = table.Column<string>(type: "TEXT", maxLength: 1000, nullable: false),
                BillingInterval = table.Column<string>(type: "TEXT", maxLength: 16, nullable: false),
                Price = table.Column<decimal>(type: "TEXT", precision: 18, scale: 4, nullable: false),
                Currency = table.Column<string>(type: "TEXT", maxLength: 3, nullable: false),
                TrialDays = table.Column<int>(type: "INTEGER", nullable: false),
                GracePeriodDays = table.Column<int>(type: "INTEGER", nullable: false),
                IsEnabled = table.Column<bool>(type: "INTEGER", nullable: false),
                SortOrder = table.Column<int>(type: "INTEGER", nullable: false),
                GatewayProductId = table.Column<string>(type: "TEXT", maxLength: 200, nullable: true),
                GatewayPlanId = table.Column<string>(type: "TEXT", maxLength: 200, nullable: true),
                CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ConcurrencyToken = table.Column<byte[]>(type: "BLOB", nullable: false)
            },
            constraints: table => table.PrimaryKey("PK_SubscriptionPlans", x => x.Id));

        migrationBuilder.CreateIndex(
            name: "IX_SubscriptionPlans_NormalizedCode",
            table: "SubscriptionPlans",
            column: "NormalizedCode",
            unique: true);

        migrationBuilder.CreateIndex(
            name: "IX_SubscriptionPlans_IsEnabled_SortOrder",
            table: "SubscriptionPlans",
            columns: new[] { "IsEnabled", "SortOrder" });
    }

    protected override void Down(MigrationBuilder migrationBuilder) =>
        migrationBuilder.DropTable(name: "SubscriptionPlans");
}
