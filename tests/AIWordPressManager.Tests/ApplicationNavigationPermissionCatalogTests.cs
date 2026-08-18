using System.Security.Claims;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class ApplicationNavigationPermissionCatalogTests
{
    [Theory]
    [InlineData("/sites", ApplicationPermissionCatalog.SitesView)]
    [InlineData("/sites/11111111-1111-1111-1111-111111111111/explorer", ApplicationPermissionCatalog.SitesView)]
    [InlineData("/sites/connect", ApplicationPermissionCatalog.SitesManage)]
    [InlineData("/content", ApplicationPermissionCatalog.ContentView)]
    [InlineData("/content/editor/post/42", ApplicationPermissionCatalog.ContentView)]
    [InlineData("/module/media", ApplicationPermissionCatalog.ContentView)]
    [InlineData("/execution-center", ApplicationPermissionCatalog.OperationsView)]
    [InlineData("/module/execution", ApplicationPermissionCatalog.OperationsView)]
    [InlineData("/approvals", ApplicationPermissionCatalog.ApprovalsView)]
    [InlineData("/module/approvals", ApplicationPermissionCatalog.ApprovalsView)]
    public void Protected_navigation_paths_map_to_expected_permission(string path, string permission)
    {
        ApplicationNavigationPermissionCatalog.ForPath(path).Should().Be(permission);
    }

    [Fact]
    public void Most_specific_navigation_rule_wins()
    {
        ApplicationNavigationPermissionCatalog.ForPath("/sites/connect?returnUrl=%2Fsites")
            .Should().Be(ApplicationPermissionCatalog.SitesManage);
    }

    [Theory]
    [InlineData("/")]
    [InlineData("/welcome")]
    [InlineData("/about-build")]
    public void Unmapped_navigation_paths_remain_visible_to_authenticated_workspace_users(string path)
    {
        ApplicationNavigationPermissionCatalog.ForPath(path).Should().BeNull();
        ApplicationNavigationPermissionCatalog.CanAccess(Principal(), path).Should().BeTrue();
    }

    [Fact]
    public void View_only_content_principal_sees_content_destinations_but_not_site_or_operations_destinations()
    {
        var principal = Principal(ApplicationPermissionCatalog.ContentView);

        ApplicationNavigationPermissionCatalog.CanAccess(principal, "/content").Should().BeTrue();
        ApplicationNavigationPermissionCatalog.CanAccess(principal, "/module/posts").Should().BeTrue();
        ApplicationNavigationPermissionCatalog.CanAccess(principal, "/module/media").Should().BeTrue();
        ApplicationNavigationPermissionCatalog.CanAccess(principal, "/sites").Should().BeFalse();
        ApplicationNavigationPermissionCatalog.CanAccess(principal, "/execution-center").Should().BeFalse();
        ApplicationNavigationPermissionCatalog.CanAccess(principal, "/approvals").Should().BeFalse();
    }

    [Fact]
    public void Sites_view_does_not_expose_connect_site_without_sites_manage()
    {
        var principal = Principal(ApplicationPermissionCatalog.SitesView);

        ApplicationNavigationPermissionCatalog.CanAccess(principal, "/sites").Should().BeTrue();
        ApplicationNavigationPermissionCatalog.CanAccess(principal, "/sites/connect").Should().BeFalse();
    }

    [Fact]
    public void Anonymous_principal_fails_closed_for_mapped_navigation_destinations()
    {
        ApplicationNavigationPermissionCatalog.CanAccess(new ClaimsPrincipal(), "/content").Should().BeFalse();
        ApplicationNavigationPermissionCatalog.CanAccess(null, "/sites").Should().BeFalse();
    }

    [Fact]
    public void Main_layout_filters_sidebar_command_palette_recent_catalog_and_current_item()
    {
        var source = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Layout/MainLayout.razor");

        source.Should().Contain("ApplicationNavigationPermissionCatalog.CanAccess(_principal, item.Path)");
        source.Should().Contain("group.Items.Any(IsVisibleSidebarItem)");
        source.Should().Contain(".Where(CanNavigate)");
        source.Should().Contain("AppNavigationCatalog.VisibleItems(_isAdministrator)");
        source.Should().Contain("private ClaimsPrincipal _principal");
    }

    private static ClaimsPrincipal Principal(params string[] permissions)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, Guid.NewGuid().ToString())
        };
        claims.AddRange(permissions.Select(permission =>
            new Claim(ApplicationPermissionCatalog.ClaimType, permission)));

        return new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"));
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
