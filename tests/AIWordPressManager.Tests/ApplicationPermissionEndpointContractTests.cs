using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class ApplicationPermissionEndpointContractTests
{
    [Fact]
    public void Program_keeps_permission_policies_registered()
    {
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        program.Should().Contain("ApplicationPermissionCatalog.AddPolicies(options);");
        program.Should().Contain("options.FallbackPolicy = new AuthorizationPolicyBuilder().RequireAuthenticatedUser().Build();");
    }

    [Fact]
    public void Operational_read_endpoints_require_view_permissions()
    {
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        program.Should().Contain(".RequireAuthorization(ApplicationPermissionCatalog.OperationsView);");
        program.Should().Contain(".RequireAuthorization(ApplicationPermissionCatalog.ContentView);");
        program.Should().Contain(".RequireAuthorization(ApplicationPermissionCatalog.ApprovalsView);");
    }

    [Fact]
    public void Mutating_endpoints_require_edit_execute_or_decide_permissions()
    {
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        program.Should().Contain(".RequireAuthorization(ApplicationPermissionCatalog.ContentEdit);");
        program.Should().Contain(".RequireAuthorization(ApplicationPermissionCatalog.OperationsExecute);");
        program.Should().Contain(".RequireAuthorization(ApplicationPermissionCatalog.ApprovalsDecide);");
    }

    [Fact]
    public void Public_setup_login_and_liveness_contracts_are_unchanged()
    {
        var program = ReadRepositoryFile("src/AIWordPressManager.Web/Program.cs");

        program.Should().Contain("app.MapGet(\"/setup\"");
        program.Should().Contain("app.MapGet(\"/login\"");
        program.Should().Contain("app.MapHealthChecks(\"/health/live\").AllowAnonymous();");
    }

    [Theory]
    [InlineData(ApplicationPermissionCatalog.ContentView)]
    [InlineData(ApplicationPermissionCatalog.ContentEdit)]
    [InlineData(ApplicationPermissionCatalog.OperationsView)]
    [InlineData(ApplicationPermissionCatalog.OperationsExecute)]
    [InlineData(ApplicationPermissionCatalog.ApprovalsView)]
    [InlineData(ApplicationPermissionCatalog.ApprovalsDecide)]
    public void Existing_User_role_keeps_the_permissions_required_by_the_rollout(string permission)
    {
        ApplicationPermissionCatalog.RoleHasPermission("User", permission).Should().BeTrue(
            $"the rollout must not silently revoke the pre-IDN-009 operational access for existing User accounts ({permission})");
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
