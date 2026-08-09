using AIWordPressManager.Web.Components.Pages;
using FluentAssertions;
using Microsoft.AspNetCore.Authorization;

namespace AIWordPressManager.Tests;

public sealed class PublicWelcomeLandingTests
{
    [Fact]
    public void WelcomePage_IsExplicitlyAnonymous()
    {
        typeof(Welcome)
            .GetCustomAttributes(typeof(AllowAnonymousAttribute), inherit: true)
            .Should()
            .NotBeEmpty("the public landing page must remain reachable without authentication even when a fallback authorization policy is enabled");
    }

    [Fact]
    public void WelcomePage_DoesNotRequireAuthorizeAttribute()
    {
        typeof(Welcome)
            .GetCustomAttributes(typeof(AuthorizeAttribute), inherit: true)
            .Should()
            .BeEmpty("the landing page is the public product entry point");
    }
}
