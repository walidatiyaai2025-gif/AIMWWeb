using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class Ux010DiscoveredRegressionTests
{
    [Theory]
    [InlineData("src/AIWordPressManager.Persistence/Migrations/20260803110000_AddSeoAuditSnapshots.cs")]
    [InlineData("src/AIWordPressManager.Persistence/Migrations/20260808011500_AddEmailSchedules.cs")]
    public void Previously_skipped_migrations_are_associated_with_app_db_context(string path)
    {
        var migration = ReadRepositoryFile(path);
        migration.Should().Contain("using Microsoft.EntityFrameworkCore.Infrastructure;");
        migration.Should().Contain("[DbContext(typeof(AppDbContext))]");
        migration.Should().Contain("[Migration(");
    }

    [Fact]
    public void Raw_login_page_declares_direction_and_associates_username_and_password_labels()
    {
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");
        program.Should().Contain("<html lang=\"en\" dir=\"ltr\">");
        program.Should().Contain("<label for=\"login-user-name\">Username</label>");
        program.Should().Contain("<input id=\"login-user-name\" name=\"userName\"");
        program.Should().Contain("<label for=\"login-password\">Password</label>");
        program.Should().Contain("<input id=\"login-password\" type=\"password\" name=\"password\"");
    }

    [Theory]
    [InlineData("src/AIWordPressManager.Web/Components/Pages/AIUsage.razor")]
    [InlineData("src/AIWordPressManager.Web/Components/Pages/ApprovalQueue.razor")]
    public void Authenticated_pages_rely_on_the_shell_for_the_single_h1(string path)
    {
        var page = ReadRepositoryFile(path);
        page.Should().NotContain("<h1");
    }

    [Fact]
    public void Approval_queue_filters_and_reviewer_notes_have_accessible_names()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/ApprovalQueue.razor");
        page.Should().Contain("aria-label=\"@(L.IsArabic ? \"البحث في قائمة الموافقات\" : \"Search approvals\")\"");
        page.Should().Contain("aria-label=\"@(L.IsArabic ? \"تصفية الموافقات حسب الحالة\" : \"Filter approvals by status\")\"");
        page.Should().Contain("aria-label=\"@(L.IsArabic ? \"تصفية الموافقات حسب مستوى المخاطر\" : \"Filter approvals by risk\")\"");
        page.Should().Contain("aria-label=\"@(L.IsArabic ? \"ملاحظات المراجع\" : \"Reviewer notes\")\"");
    }

    [Fact]
    public void Account_email_text_controls_have_explicit_accessible_names()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AccountEmailSettings.razor");
        page.Should().Contain("aria-label=\"SMTP Host\"");
        page.Should().Contain("aria-label=\"SMTP Port\"");
        page.Should().Contain("aria-label=\"@(L.IsArabic ? \"اسم مستخدم SMTP\" : \"SMTP username\")\"");
        page.Should().Contain("aria-label=\"@(L.IsArabic ? \"كلمة مرور SMTP\" : \"SMTP password\")\"");
        page.Should().Contain("aria-label=\"@(L.IsArabic ? \"بريد المستلم الجديد\" : \"New recipient email address\")\"");
        page.Should().Contain("aria-label=\"@(L.IsArabic ? \"تعديل بريد المستلم\" : \"Edit recipient email address\")\"");
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
                return File.ReadAllText(Path.Combine(current.FullName, relativePath));
            current = current.Parent;
        }
        throw new DirectoryNotFoundException("Could not locate repository root from test output.");
    }
}
