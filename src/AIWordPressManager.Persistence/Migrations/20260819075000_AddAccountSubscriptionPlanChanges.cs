using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace AIWordPressManager.Persistence.Migrations;

[DbContext(typeof(AppDbContext))]
[Migration("20260819075000_AddAccountSubscriptionPlanChanges")]
public partial class AddAccountSubscriptionPlanChanges : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable(
            name: "AccountSubscriptionPlanChanges",
            columns: table => new
            {
                Id = table.Column<Guid>(type: "TEXT", nullable: false),
                SubscriptionId = table.Column<Guid>(type: "TEXT", nullable: false),
                FromPlanId = table.Column<Guid>(type: "TEXT", nullable: false),
                ToPlanId = table.Column<Guid>(type: "TEXT", nullable: false),
                Source = table.Column<string>(type: "TEXT", maxLength: 20, nullable: false),
                Reason = table.Column<string>(type: "TEXT", maxLength: 500, nullable: false),
                OccurredAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ProviderObservedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ConcurrencyToken = table.Column<byte[]>(type: "BLOB", nullable: false)
            },
            constraints: table =>
            {
                table.PrimaryKey("PK_AccountSubscriptionPlanChanges", x => x.Id);
                table.ForeignKey(
                    name: "FK_AccountSubscriptionPlanChanges_AccountSubscriptions_SubscriptionId",
                    column: x => x.SubscriptionId,
                    principalTable: "AccountSubscriptions",
                    principalColumn: "Id",
                    onDelete: ReferentialAction.Cascade);
                table.ForeignKey(
                    name: "FK_AccountSubscriptionPlanChanges_SubscriptionPlans_FromPlanId",
                    column: x => x.FromPlanId,
                    principalTable: "SubscriptionPlans",
                    principalColumn: "Id",
                    onDelete: ReferentialAction.Restrict);
                table.ForeignKey(
                    name: "FK_AccountSubscriptionPlanChanges_SubscriptionPlans_ToPlanId",
                    column: x => x.ToPlanId,
                    principalTable: "SubscriptionPlans",
                    principalColumn: "Id",
                    onDelete: ReferentialAction.Restrict);
            });

        migrationBuilder.CreateIndex(
            name: "IX_AccountSubscriptionPlanChanges_FromPlanId",
            table: "AccountSubscriptionPlanChanges",
            column: "FromPlanId");

        migrationBuilder.CreateIndex(
            name: "IX_AccountSubscriptionPlanChanges_ToPlanId",
            table: "AccountSubscriptionPlanChanges",
            column: "ToPlanId");

        migrationBuilder.CreateIndex(
            name: "IX_AccountSubscriptionPlanChanges_SubscriptionId_OccurredAtUtc",
            table: "AccountSubscriptionPlanChanges",
            columns: new[] { "SubscriptionId", "OccurredAtUtc" });
    }

    protected override void Down(MigrationBuilder migrationBuilder) =>
        migrationBuilder.DropTable(name: "AccountSubscriptionPlanChanges");
}
