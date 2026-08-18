using System.Security.Claims;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class ApplicationAuthorizationTests
{
    [Fact]
    public void Permission_identifiers_are_unique_and_stable()
    {
        ApplicationPermissions.All.Should().OnlyHaveUniqueItems(x => x.Id);
        ApplicationPermissions.All.Should().OnlyContain(x =>
            !string.IsNullOrWhiteSpace(x.Id) &&
            x.Id == x.Id.ToLowerInvariant() &&
            x.Id.Contains('.'));
    }

    [Fact]
    public void Role_names_are_unique_and_normalized_case_insensitively()
    {
        ApplicationRoles.All.Should().OnlyHaveUniqueItems(x => x.Name);
        ApplicationRoles.Normalize(" administrator ").Should().Be(ApplicationRoles.Administrator);
        ApplicationRoles.Normalize("manager").Should().Be(ApplicationRoles.Manager);
        ApplicationRoles.Normalize("OPERATOR").Should().Be(ApplicationRoles.Operator);
        ApplicationRoles.Normalize("unknown").Should().BeEmpty();
    }

    [Fact]
    public void Administrator_has_every_permission()
    {
        foreach (var permission in ApplicationPermissions.All)
            ApplicationRoles.HasPermission(ApplicationRoles.Administrator, permission.Id).Should().BeTrue(permission.Id);
    }

    [Fact]
    public void Manager_can_review_users_but_cannot_manage_accounts_or_system_security()
    {
        ApplicationRoles.HasPermission(ApplicationRoles.Manager, ApplicationPermissions.UsersRead).Should().BeTrue();
        ApplicationRoles.HasPermission(ApplicationRoles.Manager, ApplicationPermissions.UsersManage).Should().BeFalse();
        ApplicationRoles.HasPermission(ApplicationRoles.Manager, ApplicationPermissions.SystemManage).Should().BeFalse();
        ApplicationRoles.HasPermission(ApplicationRoles.Manager, ApplicationPermissions.OperationsExecute).Should().BeTrue();
    }

    [Fact]
    public void Operator_can_execute_content_work_but_cannot_change_sensitive_configuration()
    {
        ApplicationRoles.HasPermission(ApplicationRoles.Operator, ApplicationPermissions.ContentManage).Should().BeTrue();
        ApplicationRoles.HasPermission(ApplicationRoles.Operator, ApplicationPermissions.OperationsExecute).Should().BeTrue();
        ApplicationRoles.HasPermission(ApplicationRoles.Operator, ApplicationPermissions.SitesManage).Should().BeFalse();
        ApplicationRoles.HasPermission(ApplicationRoles.Operator, ApplicationPermissions.AiProvidersManage).Should().BeFalse();
        ApplicationRoles.HasPermission(ApplicationRoles.Operator, ApplicationPermissions.BackupsManage).Should().BeFalse();
    }

    [Fact]
    public void Viewer_is_read_only()
    {
        ApplicationRoles.HasPermission(ApplicationRoles.Viewer, ApplicationPermissions.ContentRead).Should().BeTrue();
        ApplicationRoles.HasPermission(ApplicationRoles.Viewer, ApplicationPermissions.OperationsRead).Should().BeTrue();
        ApplicationRoles.HasPermission(ApplicationRoles.Viewer, ApplicationPermissions.ContentManage).Should().BeFalse();
        ApplicationRoles.HasPermission(ApplicationRoles.Viewer, ApplicationPermissions.OperationsExecute).Should().BeFalse();
    }

    [Fact]
    public void Legacy_user_keeps_operational_access_without_gaining_user_administration()
    {
        ApplicationRoles.HasPermission(ApplicationRoles.LegacyUser, ApplicationPermissions.SitesManage).Should().BeTrue();
        ApplicationRoles.HasPermission(ApplicationRoles.LegacyUser, ApplicationPermissions.AutomationManage).Should().BeTrue();
        ApplicationRoles.HasPermission(ApplicationRoles.LegacyUser, ApplicationPermissions.UsersRead).Should().BeFalse();
        ApplicationRoles.HasPermission(ApplicationRoles.LegacyUser, ApplicationPermissions.UsersManage).Should().BeFalse();
    }

    [Fact]
    public void Permission_policies_are_registered_for_every_permission()
    {
        var options = new AuthorizationOptions();
        ApplicationAuthorization.AddPermissionPolicies(options);

        foreach (var permission in ApplicationPermissions.All)
            options.GetPolicy(permission.Id).Should().NotBeNull(permission.Id);
    }

    [Fact]
    public void Current_user_context_enforces_permissions_server_side()
    {
        var userId = Guid.NewGuid();
        var manager = CreateContext(userId, ApplicationRoles.Manager);
        var managerContext = new CurrentUserContext(new TestHttpContextAccessor(manager));

        managerContext.RequirePermission(ApplicationPermissions.OperationsExecute).Should().Be(userId);
        var denied = () => managerContext.RequirePermission(ApplicationPermissions.UsersManage);
        denied.Should().Throw<UnauthorizedAccessException>();
    }

    [Fact]
    public void Anonymous_principal_never_has_application_permissions()
    {
        var principal = new ClaimsPrincipal(new ClaimsIdentity());
        ApplicationAuthorization.HasPermission(principal, ApplicationPermissions.SystemRead).Should().BeFalse();
    }

    private static HttpContext CreateContext(Guid userId, string role)
    {
        var identity = new ClaimsIdentity(
        [
            new Claim(ClaimTypes.NameIdentifier, userId.ToString()),
            new Claim(ClaimTypes.Name, "security.test"),
            new Claim(ClaimTypes.Role, role)
        ], "Test");

        return new DefaultHttpContext { User = new ClaimsPrincipal(identity) };
    }

    private sealed class TestHttpContextAccessor(HttpContext context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }
}
