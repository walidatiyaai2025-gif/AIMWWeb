using System.Security.Claims;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class BackgroundExecutionIdentityTests
{
    [Fact]
    public void Background_owner_is_available_without_granting_administrator_role()
    {
        var ownerId = Guid.NewGuid();
        var currentUser = new CurrentUserContext(new HttpContextAccessor());

        using (BackgroundExecutionIdentity.Push(ownerId))
        {
            currentUser.IsAuthenticated.Should().BeTrue();
            currentUser.UserId.Should().Be(ownerId);
            currentUser.IsInRole("Administrator").Should().BeFalse();
            currentUser.Invoking(x => x.RequireAdministrator()).Should().Throw<UnauthorizedAccessException>();
        }

        currentUser.TryGetUserId(out _).Should().BeFalse();
    }

    [Fact]
    public void Http_identity_always_wins_over_background_owner()
    {
        var httpUserId = Guid.NewGuid();
        var backgroundOwnerId = Guid.NewGuid();
        var accessor = new HttpContextAccessor
        {
            HttpContext = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity(
                [
                    new Claim(ClaimTypes.NameIdentifier, httpUserId.ToString()),
                    new Claim(ClaimTypes.Name, "admin"),
                    new Claim(ClaimTypes.Role, "Administrator")
                ], "test"))
            }
        };
        var currentUser = new CurrentUserContext(accessor);

        using (BackgroundExecutionIdentity.Push(backgroundOwnerId))
        {
            currentUser.UserId.Should().Be(httpUserId);
            currentUser.RequireAdministrator().Should().Be(httpUserId);
        }
    }

    [Fact]
    public void Nested_background_identity_lease_restores_previous_owner()
    {
        var firstOwner = Guid.NewGuid();
        var secondOwner = Guid.NewGuid();

        using (BackgroundExecutionIdentity.Push(firstOwner))
        {
            BackgroundExecutionIdentity.TryGetOwnerUserId(out var current).Should().BeTrue();
            current.Should().Be(firstOwner);

            using (BackgroundExecutionIdentity.Push(secondOwner))
            {
                BackgroundExecutionIdentity.TryGetOwnerUserId(out current).Should().BeTrue();
                current.Should().Be(secondOwner);
            }

            BackgroundExecutionIdentity.TryGetOwnerUserId(out current).Should().BeTrue();
            current.Should().Be(firstOwner);
        }

        BackgroundExecutionIdentity.TryGetOwnerUserId(out _).Should().BeFalse();
    }

    [Fact]
    public void Empty_background_owner_is_rejected()
    {
        Action action = () => BackgroundExecutionIdentity.Push(Guid.Empty);

        action.Should().Throw<ArgumentException>();
    }
}
