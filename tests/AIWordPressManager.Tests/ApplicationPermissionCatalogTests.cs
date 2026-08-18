using System.Security.Claims;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Authorization;

namespace AIWordPressManager.Tests;

public sealed class ApplicationPermissionCatalogTests
{
    [Fact]
    public void Administrator_receives_every_known_permission()
    {
        ApplicationPermissionCatalog.ForRole("Administrator")
            .Should().BeEquivalentTo(ApplicationPermissionCatalog.All);
    }

    [Fact]
    public void User_receives_tenant_work_permissions_but_not_user_administration()
    {
        var permissions = ApplicationPermissionCatalog.ForRole("User");

        permissions.Should().Contain(ApplicationPermissionCatalog.SitesView);
        permissions.Should().Contain(ApplicationPermissionCatalog.ContentEdit);
        permissions.Should().Contain(ApplicationPermissionCatalog.ApprovalsDecide);
        permissions.Should().NotContain(ApplicationPermissionCatalog.UsersView);
        permissions.Should().NotContain(ApplicationPermissionCatalog.UsersManage);
        permissions.Should().NotContain(ApplicationPermissionCatalog.SettingsManage);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("Auditor")]
    [InlineData("administrator-extra")]
    public void Unknown_roles_fail_closed(string? role)
    {
        ApplicationPermissionCatalog.ForRole(role).Should().BeEmpty();
        ApplicationPermissionCatalog.RoleHasPermission(role, ApplicationPermissionCatalog.UsersView).Should().BeFalse();
    }

    [Fact]
    public void Explicit_permission_claim_is_honored_for_authenticated_principal()
    {
        var principal = Principal(
            new Claim(ClaimTypes.NameIdentifier, Guid.NewGuid().ToString()),
            new Claim(ApplicationPermissionCatalog.ClaimType, ApplicationPermissionCatalog.UsersView));

        ApplicationPermissionCatalog.PrincipalHasPermission(principal, ApplicationPermissionCatalog.UsersView).Should().BeTrue();
        ApplicationPermissionCatalog.PrincipalHasPermission(principal, ApplicationPermissionCatalog.UsersManage).Should().BeFalse();
    }

    [Fact]
    public void Legacy_administrator_cookie_is_bridged_to_permission_mapping()
    {
        var principal = Principal(
            new Claim(ClaimTypes.NameIdentifier, Guid.NewGuid().ToString()),
            new Claim(ClaimTypes.Role, "Administrator"));

        ApplicationPermissionCatalog.PrincipalHasPermission(principal, ApplicationPermissionCatalog.UsersManage).Should().BeTrue();
    }

    [Fact]
    public void Unauthenticated_or_unknown_permission_is_denied()
    {
        var anonymous = new ClaimsPrincipal(new ClaimsIdentity());
        var authenticated = Principal(new Claim(ClaimTypes.Role, "Administrator"));

        ApplicationPermissionCatalog.PrincipalHasPermission(anonymous, ApplicationPermissionCatalog.SitesView).Should().BeFalse();
        ApplicationPermissionCatalog.PrincipalHasPermission(authenticated, "Unknown.Permission").Should().BeFalse();
    }

    [Fact]
    public void Authorization_options_register_a_policy_for_every_permission()
    {
        var options = new AuthorizationOptions();
        ApplicationPermissionCatalog.AddPolicies(options);

        foreach (var permission in ApplicationPermissionCatalog.All)
            options.GetPolicy(permission).Should().NotBeNull(permission);
    }

    private static ClaimsPrincipal Principal(params Claim[] claims) =>
        new(new ClaimsIdentity(claims, "Test"));
}