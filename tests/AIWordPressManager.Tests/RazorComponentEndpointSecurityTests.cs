using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class RazorComponentEndpointSecurityTests
{
    [Theory]
    [InlineData(RazorComponentEndpointSecurity.BlazorWebBootstrapPath)]
    [InlineData(RazorComponentEndpointSecurity.BlazorInitializersPath)]
    public void Required_blazor_bootstrap_paths_are_exact_authorization_bypasses(string path)
    {
        RazorComponentEndpointSecurity.ShouldBypassAuthorization(path, null).Should().BeTrue();
        RazorComponentEndpointSecurity.ShouldBypassAuthorization(path, string.Empty).Should().BeTrue();
        RazorComponentEndpointSecurity.ShouldBypassAuthorization(path, "runtime-specific display name").Should().BeTrue();
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("/_framework/blazor.web.js/")]
    [InlineData("/_framework/blazor.server.js")]
    [InlineData("/_blazor/initializers/")]
    [InlineData("/_blazor/negotiate")]
    [InlineData("/_blazor")]
    [InlineData("/welcome")]
    [InlineData("/")]
    public void Other_paths_remain_protected_regardless_of_endpoint_display_name(string? path)
    {
        RazorComponentEndpointSecurity.ShouldBypassAuthorization(path, null).Should().BeFalse();
        RazorComponentEndpointSecurity.ShouldBypassAuthorization(path, "Blazor initializers").Should().BeFalse();
        RazorComponentEndpointSecurity.ShouldBypassAuthorization(path, "Blazor web static files").Should().BeFalse();
    }

    [Fact]
    public void Bootstrap_path_matching_is_case_sensitive_to_avoid_broad_exemptions()
    {
        RazorComponentEndpointSecurity.ShouldBypassAuthorization("/_FRAMEWORK/blazor.web.js", null)
            .Should().BeFalse();
        RazorComponentEndpointSecurity.ShouldBypassAuthorization("/_BLAZOR/initializers", null)
            .Should().BeFalse();
    }
}
