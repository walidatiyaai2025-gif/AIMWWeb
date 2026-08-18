using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace AIWordPressManager.Persistence.Migrations;

[DbContext(typeof(AppDbContext))]
[Migration("20260818190000_AddAccountSubscriptionState")]
public partial class AddAccountSubscriptionState : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable(
            name: "AccountSubscriptions",
            columns: table => new
            {
                Id = table.Column<Guid>(type: "TEXT", nullable: false),
                OwnerUserId = table.Column<Guid>(type: "TEXT", nullable: false),
                PlanId = table.Column<Guid>(type: "TEXT", nullable: false),
                Status = table.Column<string>(type: "TEXT", maxLength: 16, nullable: false),
                TrialStartedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                TrialEndsAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                CurrentPeriodStartUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                CurrentPeriodEndsAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                CancelAtPeriodEnd = table.Column<bool>(type: "INTEGER", nullable: false),
                GraceUntilUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                CancelledAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                SuspendedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                ExpiredAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                ProviderKey = table.Column<string>(type: "TEXT", maxLength: 64, nullable: true),
                ProviderSubscriptionReference = table.Column<string>(type: "TEXT", maxLength: 200, nullable: true),
                LastProviderEventAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ConcurrencyToken = table.Column<byte[]>(type: "BLOB", nullable: false)
            },
            constraints: table =>
            {
                table.PrimaryKey("PK_AccountSubscriptions", x => x.Id);
                table.ForeignKey("FK_AccountSubscriptions_AuthUsers_OwnerUserId", x => x.OwnerUserId, "AuthUsers", "Id", onDelete: ReferentialAction.Cascade);
                table.ForeignKey("FK_AccountSubscriptions_SubscriptionPlans_PlanId", x => x.PlanId, "SubscriptionPlans", "Id", onDelete: ReferentialAction.Restrict);
            });

        migrationBuilder.CreateTable(
            name: "AccountSubscriptionTransitions",
            columns: table => new
            {
                Id = table.Column<Guid>(type: "TEXT", nullable: false),
                SubscriptionId = table.Column<Guid>(type: "TEXT", nullable: false),
                FromStatus = table.Column<string>(type: "TEXT", maxLength: 16, nullable: false),
                ToStatus = table.Column<string>(type: "TEXT", maxLength: 16, nullable: false),
                Source = table.Column<string>(type: "TEXT", maxLength: 20, nullable: false),
                Reason = table.Column<string>(type: "TEXT", maxLength: 500, nullable: false),
                OccurredAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ProviderEventAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true),
                CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false),
                ConcurrencyToken = table.Column<byte[]>(type: "BLOB", nullable: false)
            },
            constraints: table =>
            {
                table.PrimaryKey("PK_AccountSubscriptionTransitions", x => x.Id);
                table.ForeignKey("FK_AccountSubscriptionTransitions_AccountSubscriptions_SubscriptionId", x => x.SubscriptionId, "AccountSubscriptions", "Id", onDelete: ReferentialAction.Cascade);
            });

        migrationBuilder.CreateIndex("IX_AccountSubscriptions_OwnerUserId", "AccountSubscriptions", "OwnerUserId", unique: true);
        migrationBuilder.CreateIndex("IX_AccountSubscriptions_PlanId", "AccountSubscriptions", "PlanId");
        migrationBuilder.CreateIndex("IX_AccountSubscriptions_Status", "AccountSubscriptions", "Status");
        migrationBuilder.CreateIndex("IX_AccountSubscriptions_LastProviderEventAtUtc", "AccountSubscriptions", "LastProviderEventAtUtc");
        migrationBuilder.CreateIndex("IX_AccountSubscriptionTransitions_SubscriptionId_OccurredAtUtc", "AccountSubscriptionTransitions", new[] { "SubscriptionId", "OccurredAtUtc" });
    }

    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.DropTable("AccountSubscriptionTransitions");
        migrationBuilder.DropTable("AccountSubscriptions");
    }
}
