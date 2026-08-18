using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class ApplicationCustomRoleUiContractTests
{
    [Fact]
    public void Permission_metadata_is_bilingual_and_reserves_security_administration()
    {
        ApplicationPermissionCatalog.Definitions.Should().OnlyHaveUniqueItems(x => x.Id);
        ApplicationPermissionCatalog.Definitions.Should().OnlyContain(x =>
            !string.IsNullOrWhiteSpace(x.EnglishName) && !string.IsNullOrWhiteSpace(x.ArabicName));

        ApplicationPermissionCatalog.IsCustomRoleAssignable(ApplicationPermissionCatalog.UsersManage).Should().BeFalse();
        ApplicationPermissionCatalog.IsCustomRoleAssignable(ApplicationPermissionCatalog.SettingsManage).Should().BeFalse();
        ApplicationPermissionCatalog.IsCustomRoleAssignable(ApplicationPermissionCatalog.ContentEdit).Should().BeTrue();
    }

    [Fact]
    public void Custom_role_page_requires_security_settings_permission_and_supports_bilingual_assignment()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/RolesPermissions.razor");

        page.Should().Contain("@page \"/admin/roles-permissions\"");
        page.Should().Contain("ApplicationPermissionCatalog.SettingsManage");
        page.Should().Contain("permission.ArabicName");
        page.Should().Contain("permission.EnglishName");
        page.Should().Contain("GetAssignableRolesAsync");
        page.Should().Contain("SaveAssignmentAsync");
    }

    [Fact]
    public void Application_user_editor_uses_server_defined_active_roles_instead_of_a_two_role_hard_code()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/ApplicationUsers.razor");

        page.Should().Contain("_roleOptions = await _service.GetAssignableRolesAsync()");
        page.Should().Contain("@foreach (var role in _roleOptions)");
        page.Should().Contain("IsRoleOptionAvailable(_role)");
        page.Should().Contain("href=\"/admin/roles-permissions\"");
        page.Should().NotContain("<option value=\"User\">");
        page.Should().NotContain("<option value=\"Administrator\">");
    }

    [Fact]
    public void Custom_role_navigation_is_administrator_only()
    {
        var item = AppNavigationCatalog.AllItems.Single(x => x.Path == "/admin/roles-permissions");

        item.AdministratorOnly.Should().BeTrue();
        AppNavigationCatalog.VisibleItems(false).Should().NotContain(x => x.Path == item.Path);
        AppNavigationCatalog.VisibleItems(true).Should().Contain(x => x.Path == item.Path);
    }

    [Fact]
    public void Login_resolves_active_role_before_issuing_server_side_permission_claims()
    {
        var authentication = ReadRepositoryFile("src/AIWordPressManager.Web/Services/LocalAuthenticationService.cs");

        authentication.Should().Contain("ResolveAssignableRoleNameAsync(user.Role");
        authentication.Should().Contain("Unavailable application role");
        authentication.Should().Contain("ResolvePermissionsAsync(resolvedRole");
        authentication.Should().Contain("new Claim(ApplicationPermissionCatalog.ClaimType, permission)");
    }

    [Fact]
    public void Persisted_custom_roles_use_existing_application_settings_instead_of_provider_specific_schema()
    {
        var store = ReadRepositoryFile("src/AIWordPressManager.Web/Services/ApplicationRoleStore.cs");

        store.Should().Contain("Security.CustomRoles");
        store.Should().Contain("dbContext.ApplicationSettings");
        store.Should().NotContain("ExecuteSqlRaw");
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
