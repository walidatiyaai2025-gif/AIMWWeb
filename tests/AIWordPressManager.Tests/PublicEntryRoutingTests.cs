using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class PublicEntryRoutingTests
{
    [Theory]
    [InlineData("/", "GET", false, true)]
    [InlineData("/", "HEAD", false, true)]
    [InlineData("/", "GET", true, false)]
    [InlineData("/welcome", "GET", false, false)]
    [InlineData("/", "POST", false, false)]
    [InlineData("/", null, false, false)]
    [InlineData("/", "", false, false)]
    [InlineData("/", "   ", false, false)]
    public void ShouldRedirectToLanding_AppliesOnlyToAnonymousRootReads(
        string path,
        string? method,
        bool isAuthenticated,
        bool expected)
    {
        PublicEntryRouting.ShouldRedirectToLanding(path, method, isAuthenticated)
            .Should().Be(expected);
    }

    [Fact]
    public void LandingPath_Remains_PublicWelcome()
    {
        PublicEntryRouting.LandingPath.Should().Be("/welcome");
    }
}
