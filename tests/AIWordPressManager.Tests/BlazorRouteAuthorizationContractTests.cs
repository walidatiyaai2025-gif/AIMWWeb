using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class BlazorRouteAuthorizationContractTests
{
    [Fact]
    public void Router_uses_permission_aware_AuthorizeRouteView_for_matched_components()
    {
        var routes = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Routes.razor");
        var permissionRouteView = ReadRepositoryFile("src/AIWordPressManager.Web/Components/PermissionRouteView.razor");

        routes.Should().Contain("<PermissionRouteView RouteData=\"routeData\"");
        routes.Should().NotContain("<RouteView RouteData=\"routeData\"");
        permissionRouteView.Should().Contain("<AuthorizeRouteView RouteData=\"RouteData\"");
        permissionRouteView.Should().Contain("<AuthorizeView Policy=\"@permission\"");
        routes.Should().Contain("<NotAuthorized>");
        routes.Should().Contain("<Authorizing>");
    }

    [Fact]
    public void Minimum_module_route_permissions_are_mapped_fail_closed()
    {
        ApplicationRoutePermissionCatalog.For(typeof(Sites)).Should().Be(ApplicationPermissionCatalog.SitesView);
        ApplicationRoutePermissionCatalog.For(typeof(GlobalContentHub)).Should().Be(ApplicationPermissionCatalog.ContentView);
        ApplicationRoutePermissionCatalog.For(typeof(ExecutionCenter)).Should().Be(ApplicationPermissionCatalog.OperationsView);
        ApplicationRoutePermissionCatalog.For(typeof(ApprovalQueue)).Should().Be(ApplicationPermissionCatalog.ApprovalsView);
        ApplicationRoutePermissionCatalog.For(typeof(UnmappedPage)).Should().BeNull();
        ApplicationRoutePermissionCatalog.For(null).Should().BeNull();
    }

    [Fact]
    public void Web_host_registers_cascading_authentication_state()
    {
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        program.Should().Contain("builder.Services.AddCascadingAuthenticationState();");
        program.Should().Contain("ApplicationPermissionCatalog.AddPolicies(options);");
    }

    [Fact]
    public void Router_denial_copy_does_not_render_the_protected_route_component()
    {
        var routes = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Routes.razor");
        var deniedStart = routes.IndexOf("<NotAuthorized>", StringComparison.Ordinal);
        var deniedEnd = routes.IndexOf("</NotAuthorized>", StringComparison.Ordinal);

        deniedStart.Should().BeGreaterThanOrEqualTo(0);
        deniedEnd.Should().BeGreaterThan(deniedStart);
        var deniedContent = routes[(deniedStart + "<NotAuthorized>".Length)..deniedEnd];
        deniedContent.Should().NotContain("RouteData");
        deniedContent.Should().NotContain("DynamicComponent");
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

    private sealed class Sites;
    private sealed class GlobalContentHub;
    private sealed class ExecutionCenter;
    private sealed class ApprovalQueue;
    private sealed class UnmappedPage;
}
