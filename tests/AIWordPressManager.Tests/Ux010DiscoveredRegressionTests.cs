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
