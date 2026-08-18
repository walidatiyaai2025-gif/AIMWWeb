using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class AIProviderSettingsPermissionContractTests
{
    [Fact]
    public void AI_provider_settings_route_requires_SettingsManage_policy()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AIProviderSettings.razor");

        page.Should().Contain("Authorize(Policy = ApplicationPermissionCatalog.SettingsManage)");
        page.Should().NotContain("Authorize(Roles = \"Administrator\")");
    }

    [Fact]
    public void AI_provider_settings_page_routes_storage_calls_through_admin_facade_with_audit_context()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AIProviderSettings.razor");

        page.Should().Contain("AIProviderSettingsAdministrationService SettingsService");
        page.Should().Contain("new(RuntimeSettingsService, CurrentUser, DbContext, HttpContextAccessor)");
        page.Should().Contain("@inject AppDbContext DbContext");
        page.Should().Contain("@inject IHttpContextAccessor HttpContextAccessor");
        page.Should().NotContain("RuntimeSettingsService.");
    }

    [Fact]
    public void SettingsManage_remains_excluded_from_builtin_User_role()
    {
        ApplicationPermissionCatalog.RoleHasPermission("User", ApplicationPermissionCatalog.SettingsManage)
            .Should().BeFalse();
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