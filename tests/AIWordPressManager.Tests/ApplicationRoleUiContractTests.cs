using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class ApplicationRoleUiContractTests
{
    [Fact]
    public void Roles_workspace_is_administrator_only_and_bilingual()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/RolesPermissions.razor");

        page.Should().Contain("@page \"/admin/roles-permissions\"");
        page.Should().Contain("Authorize(Roles = \"Administrator\")");
        page.Should().Contain("role.ArabicName");
        page.Should().Contain("role.EnglishName");
        page.Should().Contain("permission.ArabicName");
        page.Should().Contain("permission.EnglishName");
    }

    [Fact]
    public void Application_user_editor_uses_the_canonical_role_catalog()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/ApplicationUsers.razor");

        page.Should().Contain("ApplicationRoles.All.Where(x => !x.IsLegacy)");
        page.Should().Contain("_role = ApplicationRoles.Operator");
        page.Should().Contain("ApplicationRoles.Find(role)");
        page.Should().NotContain("this screen manages Administrator/User roles");
    }

    [Fact]
    public void Roles_workspace_is_discoverable_only_in_administrator_navigation()
    {
        var item = AppNavigationCatalog.AllItems.Single(x => x.Path == "/admin/roles-permissions");

        item.AdministratorOnly.Should().BeTrue();
        AppNavigationCatalog.VisibleItems(false).Should().NotContain(x => x.Path == item.Path);
        AppNavigationCatalog.VisibleItems(true).Should().Contain(x => x.Path == item.Path);
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
