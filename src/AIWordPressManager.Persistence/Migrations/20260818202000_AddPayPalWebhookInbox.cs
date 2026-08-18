using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace AIWordPressManager.Persistence.Migrations;

[DbContext(typeof(AppDbContext))]
[Migration("20260818202000_AddPayPalWebhookInbox")]
public partial class AddPayPalWebhookInbox : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable(
            name: "PayPalWebhookInboxEvents",
            columns: table => new
            {
                Id = table.Column<Guid>(type: "TEXT", nullable: false),
                ProviderEventId = table.Column<string>(type: "TEXT", maxLength: 200, nullable: false),
                NormalizedProviderEventId = table.Column<string>(type: "TEXT", maxLength: 200, nullable: false),
                EventType = table.Column<string>(type: "TEXT", maxLength: 160, nullable: false),
                ProviderSubscriptionReference = table.Column<string>(type: "TEXT", maxLength: 200, nullable: false),
                NormalizedState = table.Column<string>(type: "TEXT", maxLength: 32, nullable: false),
                OccurredAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ReceivedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ConcurrencyToken = table.Column<byte[]>(type: "BLOB", nullable: false)
            },
            constraints: table => table.PrimaryKey("PK_PayPalWebhookInboxEvents", x => x.Id));

        migrationBuilder.CreateIndex(
            name: "IX_PayPalWebhookInboxEvents_NormalizedProviderEventId",
            table: "PayPalWebhookInboxEvents",
            column: "NormalizedProviderEventId",
            unique: true);

        migrationBuilder.CreateIndex(
            name: "IX_PayPalWebhookInboxEvents_ProviderSubscriptionReference_OccurredAtUtc",
            table: "PayPalWebhookInboxEvents",
            columns: new[] { "ProviderSubscriptionReference", "OccurredAtUtc" });

        migrationBuilder.CreateIndex(
            name: "IX_PayPalWebhookInboxEvents_ReceivedAtUtc",
            table: "PayPalWebhookInboxEvents",
            column: "ReceivedAtUtc");
    }

    protected override void Down(MigrationBuilder migrationBuilder) =>
        migrationBuilder.DropTable(name: "PayPalWebhookInboxEvents");
}
