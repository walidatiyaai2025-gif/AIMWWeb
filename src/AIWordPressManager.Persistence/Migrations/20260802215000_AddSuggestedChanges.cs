using System;
using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;
#nullable disable
namespace AIWordPressManager.Persistence.Migrations;
[DbContext(typeof(AppDbContext))]
[Migration("20260802215000_AddSuggestedChanges")]
public partial class AddSuggestedChanges : Migration
{
    protected override void Up(MigrationBuilder m)
    {
        m.CreateTable(name:"SuggestedChanges", columns:t=>new {
            Id=t.Column<Guid>(type:"TEXT",nullable:false), SiteId=t.Column<Guid>(type:"TEXT",nullable:false),
            Fingerprint=t.Column<string>(type:"TEXT",maxLength:180,nullable:false), SourceType=t.Column<string>(type:"TEXT",maxLength:60,nullable:false),
            ObjectType=t.Column<string>(type:"TEXT",maxLength:60,nullable:false), ObjectId=t.Column<string>(type:"TEXT",maxLength:120,nullable:false),
            ChangeType=t.Column<string>(type:"TEXT",maxLength:100,nullable:false), CurrentValue=t.Column<string>(type:"TEXT",maxLength:4000,nullable:false),
            ProposedValue=t.Column<string>(type:"TEXT",maxLength:4000,nullable:false), Reason=t.Column<string>(type:"TEXT",maxLength:2000,nullable:false),
            Confidence=t.Column<double>(type:"REAL",nullable:false), RiskLevel=t.Column<string>(type:"TEXT",maxLength:30,nullable:false),
            RequiresBackup=t.Column<bool>(type:"INTEGER",nullable:false), RequiresStaging=t.Column<bool>(type:"INTEGER",nullable:false),
            ApprovalStatus=t.Column<string>(type:"TEXT",maxLength:30,nullable:false), ExecutionStatus=t.Column<string>(type:"TEXT",maxLength:30,nullable:false),
            ApprovedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:true), RejectedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:true),
            CreatedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false), UpdatedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false), ConcurrencyToken=t.Column<byte[]>(type:"BLOB",nullable:false)
        }, constraints:t=>{t.PrimaryKey("PK_SuggestedChanges",x=>x.Id);t.ForeignKey("FK_SuggestedChanges_Sites_SiteId",x=>x.SiteId,"Sites","Id",onDelete:ReferentialAction.Cascade);});
        m.CreateIndex("IX_SuggestedChanges_SiteId_Fingerprint","SuggestedChanges",new[]{"SiteId","Fingerprint"},unique:true);
        m.CreateIndex("IX_SuggestedChanges_SiteId_ApprovalStatus","SuggestedChanges",new[]{"SiteId","ApprovalStatus"});
        m.CreateIndex("IX_SuggestedChanges_SiteId_RiskLevel","SuggestedChanges",new[]{"SiteId","RiskLevel"});
        m.CreateIndex("IX_SuggestedChanges_CreatedAtUtc","SuggestedChanges","CreatedAtUtc");
    }
    protected override void Down(MigrationBuilder m) => m.DropTable("SuggestedChanges");
}
