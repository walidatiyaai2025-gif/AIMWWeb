using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class LoginRedirectTests
{
    [Theory]
    [InlineData("/module/posts", "/sites", "/module/posts")]
    [InlineData("/sites?page=2", "/", "/sites?page=2")]
    [InlineData(null, "/module/media", "/module/media")]
    [InlineData("https://evil.example", "/module/pages", "/module/pages")]
    [InlineData("//evil.example", "/module/pages", "/module/pages")]
    [InlineData("/login", "/module/pages", "/module/pages")]
    [InlineData("/logout", "/login", "/")]
    [InlineData(null, null, "/")]
    public void ResolveRedirectPath_Returns_Only_Safe_Local_Destinations(
        string? requestedPath,
        string? lastPage,
        string expected)
    {
        LocalAuthenticationService.ResolveRedirectPath(requestedPath, lastPage)
            .Should().Be(expected);
    }

    [Theory]
    [InlineData("/sites/connect", "/sites/connect")]
    [InlineData("/module/seo-audit", "/module/seo-audit")]
    [InlineData(null, "/welcome")]
    [InlineData("https://evil.example", "/welcome")]
    [InlineData("//evil.example", "/welcome")]
    [InlineData("/login", "/welcome")]
    public void FirstRunRedirect_Uses_Explicit_Safe_Target_Or_Welcome(
        string? requestedPath,
        string expected)
    {
        LocalAuthenticationService.ResolveRedirectPath(
                requestedPath,
                LocalAuthenticationService.FirstRunLandingPath)
            .Should().Be(expected);
    }

    [Fact]
    public void FirstRunLandingPath_Points_To_Welcome_Experience()
    {
        LocalAuthenticationService.FirstRunLandingPath.Should().Be("/welcome");
    }
}
