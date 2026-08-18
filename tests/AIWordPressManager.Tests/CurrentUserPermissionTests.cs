using System.Security.Claims;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class CurrentUserPermissionTests
{
    [Fact]
    public void Explicit_permission_claim_allows_required_permission()
    {
        var userId = Guid.NewGuid();
        var context = new DefaultHttpContext
        {
            User = Principal(
                new Claim(ClaimTypes.NameIdentifier, userId.ToString()),
                new Claim(ApplicationPermissionCatalog.ClaimType, ApplicationPermissionCatalog.UsersView))
        };
        var currentUser = new CurrentUserContext(new TestAccessor(context));

        currentUser.HasPermission(ApplicationPermissionCatalog.UsersView).Should().BeTrue();
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersView).Should().Be(userId);
        currentUser.HasPermission(ApplicationPermissionCatalog.UsersManage).Should().BeFalse();
    }

    [Fact]
    public void Missing_permission_is_rejected_even_for_authenticated_user()
    {
        var context = new DefaultHttpContext
        {
            User = Principal(new Claim(ClaimTypes.NameIdentifier, Guid.NewGuid().ToString()))
        };
        var currentUser = new CurrentUserContext(new TestAccessor(context));

        var action = () => currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        action.Should().Throw<UnauthorizedAccessException>();
    }

    [Fact]
    public void Background_owner_identity_cannot_be_promoted_to_application_permission()
    {
        using var lease = BackgroundExecutionIdentity.Push(Guid.NewGuid());
        var currentUser = new CurrentUserContext(new TestAccessor(null));

        currentUser.IsAuthenticated.Should().BeTrue();
        currentUser.HasPermission(ApplicationPermissionCatalog.UsersView).Should().BeFalse();
        var action = () => currentUser.RequirePermission(ApplicationPermissionCatalog.UsersView);
        action.Should().Throw<UnauthorizedAccessException>();
    }

    [Fact]
    public void Legacy_administrator_role_remains_compatible_with_permission_requirement()
    {
        var userId = Guid.NewGuid();
        var context = new DefaultHttpContext
        {
            User = Principal(
                new Claim(ClaimTypes.NameIdentifier, userId.ToString()),
                new Claim(ClaimTypes.Role, "Administrator"))
        };
        var currentUser = new CurrentUserContext(new TestAccessor(context));

        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage).Should().Be(userId);
    }

    private static ClaimsPrincipal Principal(params Claim[] claims) =>
        new(new ClaimsIdentity(claims, "Test"));

    private sealed class TestAccessor(HttpContext? context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }
}