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
}
