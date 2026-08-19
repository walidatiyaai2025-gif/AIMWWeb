using System.Security.Claims;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Components.Authorization;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class CurrentUserContextCircuitTests
{
    [Fact]
    public void UserId_And_Permissions_Use_Circuit_Principal_When_HttpContext_Is_Missing()
    {
        var userId = Guid.NewGuid();
        var principal = CreatePrincipal(userId, "User");
        var auth = new StaticAuthenticationStateProvider(principal);
        var accessor = new HttpContextAccessor { HttpContext = null };
        var context = new CurrentUserContext(accessor, auth);

        context.UserId.Should().Be(userId);
        context.IsAuthenticated.Should().BeTrue();
        context.HasPermission(ApplicationPermissionCatalog.SitesManage).Should().BeTrue();
        context.RequirePermission(ApplicationPermissionCatalog.SitesManage).Should().Be(userId);
        context.UserName.Should().Be("circuit-user");
    }

    [Fact]
    public void Elevated_Permission_Fails_Closed_When_No_Http_Or_Circuit_Principal_Exists()
    {
        var auth = new StaticAuthenticationStateProvider(new ClaimsPrincipal(new ClaimsIdentity()));
        var accessor = new HttpContextAccessor { HttpContext = null };
        var context = new CurrentUserContext(accessor, auth);

        var action = () => context.RequirePermission(ApplicationPermissionCatalog.SitesManage);

        action.Should().Throw<UnauthorizedAccessException>();
    }

    private static ClaimsPrincipal CreatePrincipal(Guid userId, string role)
    {
        var identity = new ClaimsIdentity(
        [
            new Claim(ClaimTypes.NameIdentifier, userId.ToString("D")),
            new Claim(ClaimTypes.Name, "circuit-user"),
            new Claim(ClaimTypes.Role, role)
        ], "TestCookie");

        return new ClaimsPrincipal(identity);
    }

    private sealed class StaticAuthenticationStateProvider(ClaimsPrincipal principal) : AuthenticationStateProvider
    {
        public override Task<AuthenticationState> GetAuthenticationStateAsync() =>
            Task.FromResult(new AuthenticationState(principal));
    }
}
