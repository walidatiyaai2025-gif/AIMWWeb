using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class BlazorRouteAuthorizationContractTests
{
    [Fact]
    public void Router_uses_AuthorizeRouteView_for_matched_components()
    {
        var routes = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Routes.razor");

        routes.Should().Contain("<AuthorizeRouteView RouteData=\"routeData\"");
        routes.Should().NotContain("<RouteView RouteData=\"routeData\"");
        routes.Should().Contain("<NotAuthorized>");
        routes.Should().Contain("<Authorizing>");
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
}
