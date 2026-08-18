using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class ApplicationPermissionWiringContractTests
{
    [Fact]
    public void Program_registers_the_permission_catalog_with_authorization()
    {
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        program.Should().Contain("ApplicationAuthorization.AddPermissionPolicies(options);");
        program.Should().Contain("options.FallbackPolicy = new AuthorizationPolicyBuilder().RequireAuthenticatedUser().Build();");
    }

    [Fact]
    public void High_risk_endpoint_groups_require_named_permissions()
    {
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        program.Should().Contain(".RequireAuthorization(ApplicationPermissions.SystemRead);");
        program.Should().Contain(".RequireAuthorization(ApplicationPermissions.AutomationRead);");
        program.Should().Contain(".RequireAuthorization(ApplicationPermissions.AiUse);");
        program.Should().Contain(".RequireAuthorization(ApplicationPermissions.OperationsRead);");
        program.Should().Contain(".RequireAuthorization(ApplicationPermissions.OperationsExecute);");
        program.Should().Contain(".RequireAuthorization(ApplicationPermissions.ContentRead);");
        program.Should().Contain(".RequireAuthorization(ApplicationPermissions.ContentManage);");
    }

    [Fact]
    public void Public_setup_login_and_liveness_routes_remain_explicitly_anonymous()
    {
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        program.Should().Contain("app.MapGet(\"/setup\"");
        program.Should().Contain("app.MapGet(\"/login\"");
        program.Should().Contain("app.MapHealthChecks(\"/health/live\").AllowAnonymous();");
        program.Should().Contain("}).AllowAnonymous();");
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
