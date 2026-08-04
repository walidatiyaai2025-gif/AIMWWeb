using System;
using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;
#nullable disable
namespace AIWordPressManager.Persistence.Migrations;
[DbContext(typeof(AppDbContext))]
[Migration("20260802203000_AddSeoAuditAndBrokenLinks")]
public partial class AddSeoAuditAndBrokenLinks : Migration
{
    protected override void Up(MigrationBuilder m)
    {
        m.CreateTable(name:"SeoAuditIssues", columns:t=>new { Id=t.Column<Guid>(type:"TEXT",nullable:false), SiteId=t.Column<Guid>(type:"TEXT",nullable:false), ContentRecordId=t.Column<Guid>(type:"TEXT",nullable:false), IssueCode=t.Column<string>(type:"TEXT",maxLength:80,nullable:false), Severity=t.Column<string>(type:"TEXT",maxLength:30,nullable:false), Title=t.Column<string>(type:"TEXT",maxLength:300,nullable:false), Description=t.Column<string>(type:"TEXT",maxLength:2000,nullable:false), DetectedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false), CreatedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false), UpdatedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false), ConcurrencyToken=t.Column<byte[]>(type:"BLOB",nullable:false)}, constraints:t=>{t.PrimaryKey("PK_SeoAuditIssues",x=>x.Id);t.ForeignKey("FK_SeoAuditIssues_Sites_SiteId",x=>x.SiteId,"Sites","Id",onDelete:ReferentialAction.Cascade);t.ForeignKey("FK_SeoAuditIssues_WordPressContentRecords_ContentRecordId",x=>x.ContentRecordId,"WordPressContentRecords","Id",onDelete:ReferentialAction.Cascade);});
        m.CreateTable(name:"BrokenLinks", columns:t=>new { Id=t.Column<Guid>(type:"TEXT",nullable:false), SiteId=t.Column<Guid>(type:"TEXT",nullable:false), ContentRecordId=t.Column<Guid>(type:"TEXT",nullable:false), SourceUrl=t.Column<string>(type:"TEXT",maxLength:2048,nullable:false), TargetUrl=t.Column<string>(type:"TEXT",maxLength:2048,nullable:false), StatusCode=t.Column<int>(type:"INTEGER",nullable:true), Status=t.Column<string>(type:"TEXT",maxLength:40,nullable:false), ErrorMessage=t.Column<string>(type:"TEXT",maxLength:2000,nullable:true), CheckedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false), CreatedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false), UpdatedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false), ConcurrencyToken=t.Column<byte[]>(type:"BLOB",nullable:false)}, constraints:t=>{t.PrimaryKey("PK_BrokenLinks",x=>x.Id);t.ForeignKey("FK_BrokenLinks_Sites_SiteId",x=>x.SiteId,"Sites","Id",onDelete:ReferentialAction.Cascade);t.ForeignKey("FK_BrokenLinks_WordPressContentRecords_ContentRecordId",x=>x.ContentRecordId,"WordPressContentRecords","Id",onDelete:ReferentialAction.Cascade);});
        m.CreateIndex("IX_SeoAuditIssues_SiteId_IssueCode","SeoAuditIssues",new[]{"SiteId","IssueCode"}); m.CreateIndex("IX_SeoAuditIssues_ContentRecordId","SeoAuditIssues","ContentRecordId"); m.CreateIndex("IX_SeoAuditIssues_DetectedAtUtc","SeoAuditIssues","DetectedAtUtc");
        m.CreateIndex("IX_BrokenLinks_SiteId_Status","BrokenLinks",new[]{"SiteId","Status"}); m.CreateIndex("IX_BrokenLinks_ContentRecordId","BrokenLinks","ContentRecordId"); m.CreateIndex("IX_BrokenLinks_CheckedAtUtc","BrokenLinks","CheckedAtUtc"); m.CreateIndex("IX_BrokenLinks_TargetUrl","BrokenLinks","TargetUrl");
    }
    protected override void Down(MigrationBuilder m) { m.DropTable("BrokenLinks"); m.DropTable("SeoAuditIssues"); }
}
