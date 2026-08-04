using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace AIWordPressManager.Persistence.Migrations;

[DbContext(typeof(AppDbContext))]
[Migration("20260802130000_InitialDatabase")]
public partial class InitialDatabase : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable("ApplicationSettings", table => new
        {
            Id = table.Column<Guid>(nullable: false),
            Key = table.Column<string>(maxLength: 200, nullable: false),
            Value = table.Column<string>(nullable: false),
            CreatedAtUtc = table.Column<DateTime>(nullable: false),
            UpdatedAtUtc = table.Column<DateTime>(nullable: false),
            ConcurrencyToken = table.Column<byte[]>(nullable: false)
        }, constraints: table => table.PrimaryKey("PK_ApplicationSettings", x => x.Id));

        migrationBuilder.CreateTable("Backups", table => new
        {
            Id = table.Column<Guid>(nullable: false),
            FilePath = table.Column<string>(maxLength: 2048, nullable: false),
            FileSizeBytes = table.Column<long>(nullable: false),
            IsVerified = table.Column<bool>(nullable: false),
            CreatedAtUtc = table.Column<DateTime>(nullable: false),
            UpdatedAtUtc = table.Column<DateTime>(nullable: false),
            ConcurrencyToken = table.Column<byte[]>(nullable: false)
        }, constraints: table => table.PrimaryKey("PK_Backups", x => x.Id));

        migrationBuilder.CreateTable("DatabaseVersions", table => new
        {
            Id = table.Column<Guid>(nullable: false),
            MigrationId = table.Column<string>(maxLength: 200, nullable: false),
            AppliedAtUtc = table.Column<DateTime>(nullable: false),
            CreatedAtUtc = table.Column<DateTime>(nullable: false),
            UpdatedAtUtc = table.Column<DateTime>(nullable: false),
            ConcurrencyToken = table.Column<byte[]>(nullable: false)
        }, constraints: table => table.PrimaryKey("PK_DatabaseVersions", x => x.Id));

        migrationBuilder.CreateTable("Sites", table => new
        {
            Id = table.Column<Guid>(nullable: false),
            Name = table.Column<string>(maxLength: 200, nullable: false),
            SiteUrl = table.Column<string>(maxLength: 2048, nullable: false),
            HomeUrl = table.Column<string>(maxLength: 2048, nullable: true),
            WordPressVersion = table.Column<string>(maxLength: 50, nullable: true),
            LanguageCode = table.Column<string>(maxLength: 20, nullable: true),
            ConnectionStatus = table.Column<string>(maxLength: 40, nullable: false),
            LastConnectionTestAtUtc = table.Column<DateTime>(nullable: true),
            IsDeleted = table.Column<bool>(nullable: false, defaultValue: false),
            DeletedAtUtc = table.Column<DateTime>(nullable: true),
            CreatedAtUtc = table.Column<DateTime>(nullable: false),
            UpdatedAtUtc = table.Column<DateTime>(nullable: false),
            ConcurrencyToken = table.Column<byte[]>(nullable: false)
        }, constraints: table => table.PrimaryKey("PK_Sites", x => x.Id));

        migrationBuilder.CreateTable("SiteCredentials", table => new
        {
            Id = table.Column<Guid>(nullable: false),
            SiteId = table.Column<Guid>(nullable: false),
            UserName = table.Column<string>(maxLength: 200, nullable: false),
            ProtectedApplicationPassword = table.Column<string>(nullable: false),
            CreatedAtUtc = table.Column<DateTime>(nullable: false),
            UpdatedAtUtc = table.Column<DateTime>(nullable: false),
            ConcurrencyToken = table.Column<byte[]>(nullable: false)
        }, constraints: table =>
        {
            table.PrimaryKey("PK_SiteCredentials", x => x.Id);
            table.ForeignKey("FK_SiteCredentials_Sites_SiteId", x => x.SiteId, "Sites", "Id", onDelete: ReferentialAction.Cascade);
        });

        migrationBuilder.CreateIndex("IX_ApplicationSettings_Key", "ApplicationSettings", "Key", unique: true);
        migrationBuilder.CreateIndex("IX_Backups_CreatedAtUtc", "Backups", "CreatedAtUtc");
        migrationBuilder.CreateIndex("IX_DatabaseVersions_MigrationId", "DatabaseVersions", "MigrationId", unique: true);
        migrationBuilder.CreateIndex("IX_Sites_SiteUrl", "Sites", "SiteUrl", unique: true);
        migrationBuilder.CreateIndex("IX_SiteCredentials_SiteId", "SiteCredentials", "SiteId", unique: true);
    }

    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.DropTable("ApplicationSettings");
        migrationBuilder.DropTable("Backups");
        migrationBuilder.DropTable("DatabaseVersions");
        migrationBuilder.DropTable("SiteCredentials");
        migrationBuilder.DropTable("Sites");
    }
}
