using System;
using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;
#nullable disable
namespace AIWordPressManager.Persistence.Migrations;
[DbContext(typeof(AppDbContext))]
[Migration("20260802193000_AddTagsMediaAndAvailability")]
public partial class AddTagsMediaAndAvailability : Migration
{
 protected override void Up(MigrationBuilder m)
 {
  m.AddColumn<bool>(name:"IsAvailable",table:"WordPressContentRecords",type:"INTEGER",nullable:false,defaultValue:true);
  m.AddColumn<bool>(name:"IsAvailable",table:"WordPressCategoryRecords",type:"INTEGER",nullable:false,defaultValue:true);
  m.CreateTable(name:"WordPressTagRecords",columns:t=>new { Id=t.Column<Guid>(type:"TEXT",nullable:false),SiteId=t.Column<Guid>(type:"TEXT",nullable:false),WordPressId=t.Column<int>(type:"INTEGER",nullable:false),Name=t.Column<string>(type:"TEXT",maxLength:300,nullable:false),Slug=t.Column<string>(type:"TEXT",maxLength:300,nullable:false),PostCount=t.Column<int>(type:"INTEGER",nullable:false),IsAvailable=t.Column<bool>(type:"INTEGER",nullable:false),LastSynchronizedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false),CreatedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false),UpdatedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false),ConcurrencyToken=t.Column<byte[]>(type:"BLOB",rowVersion:true,nullable:false)},constraints:t=>{t.PrimaryKey("PK_WordPressTagRecords",x=>x.Id);t.ForeignKey("FK_WordPressTagRecords_Sites_SiteId",x=>x.SiteId,"Sites","Id",onDelete:ReferentialAction.Cascade);});
  m.CreateTable(name:"WordPressMediaRecords",columns:t=>new { Id=t.Column<Guid>(type:"TEXT",nullable:false),SiteId=t.Column<Guid>(type:"TEXT",nullable:false),WordPressId=t.Column<int>(type:"INTEGER",nullable:false),Title=t.Column<string>(type:"TEXT",maxLength:500,nullable:false),Slug=t.Column<string>(type:"TEXT",maxLength:300,nullable:false),MediaType=t.Column<string>(type:"TEXT",maxLength:80,nullable:false),MimeType=t.Column<string>(type:"TEXT",maxLength:150,nullable:false),SourceUrl=t.Column<string>(type:"TEXT",maxLength:2048,nullable:false),ModifiedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:true),IsAvailable=t.Column<bool>(type:"INTEGER",nullable:false),LastSynchronizedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false),CreatedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false),UpdatedAtUtc=t.Column<DateTime>(type:"TEXT",nullable:false),ConcurrencyToken=t.Column<byte[]>(type:"BLOB",rowVersion:true,nullable:false)},constraints:t=>{t.PrimaryKey("PK_WordPressMediaRecords",x=>x.Id);t.ForeignKey("FK_WordPressMediaRecords_Sites_SiteId",x=>x.SiteId,"Sites","Id",onDelete:ReferentialAction.Cascade);});
  m.CreateIndex("IX_WordPressTagRecords_SiteId_WordPressId","WordPressTagRecords",new[]{"SiteId","WordPressId"},unique:true); m.CreateIndex("IX_WordPressTagRecords_SiteId_Slug","WordPressTagRecords",new[]{"SiteId","Slug"},unique:true);
  m.CreateIndex("IX_WordPressMediaRecords_SiteId_WordPressId","WordPressMediaRecords",new[]{"SiteId","WordPressId"},unique:true); m.CreateIndex("IX_WordPressMediaRecords_ModifiedAtUtc","WordPressMediaRecords","ModifiedAtUtc");
 }
 protected override void Down(MigrationBuilder m) { m.DropTable("WordPressMediaRecords");m.DropTable("WordPressTagRecords");m.DropColumn("IsAvailable","WordPressContentRecords");m.DropColumn("IsAvailable","WordPressCategoryRecords"); }
}
