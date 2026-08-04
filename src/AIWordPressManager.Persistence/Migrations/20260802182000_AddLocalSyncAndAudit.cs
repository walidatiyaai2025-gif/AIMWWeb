using System;
using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace AIWordPressManager.Persistence.Migrations;

[DbContext(typeof(AppDbContext))]
[Migration("20260802182000_AddLocalSyncAndAudit")]
public partial class AddLocalSyncAndAudit : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.CreateTable(name: "ExecutionJobs", columns: table => new
        {
            Id = table.Column<Guid>(type: "TEXT", nullable: false), SiteId = table.Column<Guid>(type: "TEXT", nullable: false), JobType = table.Column<string>(type: "TEXT", maxLength: 80, nullable: false), Status = table.Column<string>(type: "TEXT", maxLength: 40, nullable: false), ProgressPercent = table.Column<int>(type: "INTEGER", nullable: false), CurrentStep = table.Column<string>(type: "TEXT", maxLength: 300, nullable: false), ErrorDetails = table.Column<string>(type: "TEXT", maxLength: 4000, nullable: true), StartedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), CompletedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true), CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), ConcurrencyToken = table.Column<byte[]>(type: "BLOB", rowVersion: true, nullable: false)
        }, constraints: table => { table.PrimaryKey("PK_ExecutionJobs", x => x.Id); table.ForeignKey("FK_ExecutionJobs_Sites_SiteId", x => x.SiteId, "Sites", "Id", onDelete: ReferentialAction.Cascade); });

        migrationBuilder.CreateTable(name: "WordPressCategoryRecords", columns: table => new
        {
            Id = table.Column<Guid>(type: "TEXT", nullable: false), SiteId = table.Column<Guid>(type: "TEXT", nullable: false), WordPressId = table.Column<int>(type: "INTEGER", nullable: false), Name = table.Column<string>(type: "TEXT", maxLength: 300, nullable: false), Slug = table.Column<string>(type: "TEXT", maxLength: 300, nullable: false), PostCount = table.Column<int>(type: "INTEGER", nullable: false), LastSynchronizedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), ConcurrencyToken = table.Column<byte[]>(type: "BLOB", rowVersion: true, nullable: false)
        }, constraints: table => { table.PrimaryKey("PK_WordPressCategoryRecords", x => x.Id); table.ForeignKey("FK_WordPressCategoryRecords_Sites_SiteId", x => x.SiteId, "Sites", "Id", onDelete: ReferentialAction.Cascade); });

        migrationBuilder.CreateTable(name: "WordPressContentRecords", columns: table => new
        {
            Id = table.Column<Guid>(type: "TEXT", nullable: false), SiteId = table.Column<Guid>(type: "TEXT", nullable: false), WordPressId = table.Column<int>(type: "INTEGER", nullable: false), ContentType = table.Column<string>(type: "TEXT", maxLength: 32, nullable: false), Title = table.Column<string>(type: "TEXT", maxLength: 500, nullable: false), Slug = table.Column<string>(type: "TEXT", maxLength: 300, nullable: false), Status = table.Column<string>(type: "TEXT", maxLength: 32, nullable: false), Link = table.Column<string>(type: "TEXT", maxLength: 2048, nullable: false), RenderedContent = table.Column<string>(type: "TEXT", nullable: false), RenderedExcerpt = table.Column<string>(type: "TEXT", nullable: false), ModifiedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: true), LastSynchronizedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), ConcurrencyToken = table.Column<byte[]>(type: "BLOB", rowVersion: true, nullable: false)
        }, constraints: table => { table.PrimaryKey("PK_WordPressContentRecords", x => x.Id); table.ForeignKey("FK_WordPressContentRecords_Sites_SiteId", x => x.SiteId, "Sites", "Id", onDelete: ReferentialAction.Cascade); });

        migrationBuilder.CreateTable(name: "ContentAuditIssues", columns: table => new
        {
            Id = table.Column<Guid>(type: "TEXT", nullable: false), SiteId = table.Column<Guid>(type: "TEXT", nullable: false), ContentRecordId = table.Column<Guid>(type: "TEXT", nullable: false), IssueCode = table.Column<string>(type: "TEXT", maxLength: 80, nullable: false), Severity = table.Column<string>(type: "TEXT", maxLength: 30, nullable: false), Title = table.Column<string>(type: "TEXT", maxLength: 300, nullable: false), Description = table.Column<string>(type: "TEXT", maxLength: 2000, nullable: false), DetectedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), CreatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), UpdatedAtUtc = table.Column<DateTime>(type: "TEXT", nullable: false), ConcurrencyToken = table.Column<byte[]>(type: "BLOB", rowVersion: true, nullable: false)
        }, constraints: table => { table.PrimaryKey("PK_ContentAuditIssues", x => x.Id); table.ForeignKey("FK_ContentAuditIssues_Sites_SiteId", x => x.SiteId, "Sites", "Id", onDelete: ReferentialAction.Cascade); table.ForeignKey("FK_ContentAuditIssues_WordPressContentRecords_ContentRecordId", x => x.ContentRecordId, "WordPressContentRecords", "Id", onDelete: ReferentialAction.Cascade); });

        migrationBuilder.CreateIndex("IX_ExecutionJobs_CreatedAtUtc", "ExecutionJobs", "CreatedAtUtc"); migrationBuilder.CreateIndex("IX_ExecutionJobs_SiteId", "ExecutionJobs", "SiteId"); migrationBuilder.CreateIndex("IX_ExecutionJobs_Status", "ExecutionJobs", "Status");
        migrationBuilder.CreateIndex("IX_WordPressCategoryRecords_SiteId_Slug", "WordPressCategoryRecords", new[] { "SiteId", "Slug" }, unique: true); migrationBuilder.CreateIndex("IX_WordPressCategoryRecords_SiteId_WordPressId", "WordPressCategoryRecords", new[] { "SiteId", "WordPressId" }, unique: true);
        migrationBuilder.CreateIndex("IX_WordPressContentRecords_ModifiedAtUtc", "WordPressContentRecords", "ModifiedAtUtc"); migrationBuilder.CreateIndex("IX_WordPressContentRecords_SiteId_ContentType_WordPressId", "WordPressContentRecords", new[] { "SiteId", "ContentType", "WordPressId" }, unique: true); migrationBuilder.CreateIndex("IX_WordPressContentRecords_SiteId_Slug", "WordPressContentRecords", new[] { "SiteId", "Slug" });
        migrationBuilder.CreateIndex("IX_ContentAuditIssues_ContentRecordId", "ContentAuditIssues", "ContentRecordId"); migrationBuilder.CreateIndex("IX_ContentAuditIssues_DetectedAtUtc", "ContentAuditIssues", "DetectedAtUtc"); migrationBuilder.CreateIndex("IX_ContentAuditIssues_SiteId_IssueCode", "ContentAuditIssues", new[] { "SiteId", "IssueCode" });
    }

    protected override void Down(MigrationBuilder migrationBuilder) { migrationBuilder.DropTable("ContentAuditIssues"); migrationBuilder.DropTable("ExecutionJobs"); migrationBuilder.DropTable("WordPressCategoryRecords"); migrationBuilder.DropTable("WordPressContentRecords"); }
}
