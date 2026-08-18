using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace AIWordPressManager.Persistence.Migrations;

[DbContext(typeof(AppDbContext))]
[Migration("20260818205600_AddPayPalSubscriptionSyncState")]
public partial class AddPayPalSubscriptionSyncState : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable(
            name: "PayPalWebhookProcessingStates",
            columns: table => new
            {
                Id = table.Column<Guid>(type: "TEXT", nullable: false),
                InboxEventId = table.Column<Guid>(type: "TEXT", nullable: false),
                Status = table.Column<int>(type: "INTEGER", nullable: false),
                AttemptCount = table.Column<int>(type: "INTEGER", nullable: false),
                NextAttemptAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                ClaimToken = table.Column<string>(type: "TEXT", maxLength: 64, nullable: true),
                ClaimUntilUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                LastError = table.Column<string>(type: "TEXT", maxLength: 1000, nullable: true),
                ProcessedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ConcurrencyToken = table.Column<byte[]>(type: "BLOB", nullable: false)
            },
            constraints: table =>
            {
                table.PrimaryKey("PK_PayPalWebhookProcessingStates", x => x.Id);
                table.ForeignKey(
                    name: "FK_PayPalWebhookProcessingStates_PayPalWebhookInboxEvents_InboxEventId",
                    column: x => x.InboxEventId,
                    principalTable: "PayPalWebhookInboxEvents",
                    principalColumn: "Id",
                    onDelete: ReferentialAction.Cascade);
            });

        migrationBuilder.CreateIndex(
            name: "IX_PayPalWebhookProcessingStates_ClaimUntilUtc",
            table: "PayPalWebhookProcessingStates",
            column: "ClaimUntilUtc");

        migrationBuilder.CreateIndex(
            name: "IX_PayPalWebhookProcessingStates_InboxEventId",
            table: "PayPalWebhookProcessingStates",
            column: "InboxEventId",
            unique: true);

        migrationBuilder.CreateIndex(
            name: "IX_PayPalWebhookProcessingStates_Status_NextAttemptAtUtc",
            table: "PayPalWebhookProcessingStates",
            columns: new[] { "Status", "NextAttemptAtUtc" });
    }

    protected override void Down(MigrationBuilder migrationBuilder) =>
        migrationBuilder.DropTable(name: "PayPalWebhookProcessingStates");
}
