using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class RazorComponentEndpointSecurityTests
{
    [Theory]
    [InlineData(RazorComponentEndpointSecurity.BlazorWebBootstrapPath, RazorComponentEndpointSecurity.BlazorWebStaticFilesDisplayName)]
    [InlineData(RazorComponentEndpointSecurity.BlazorInitializersPath, RazorComponentEndpointSecurity.BlazorInitializersDisplayName)]
    public void Required_blazor_bootstrap_endpoints_are_exact_authorization_bypasses(string path, string displayName)
    {
        RazorComponentEndpointSecurity.ShouldBypassAuthorization(path, displayName)
            .Should().BeTrue();
    }

    [Theory]
    [InlineData(null, "Blazor web static files")]
    [InlineData("", "Blazor web static files")]
    [InlineData("/_framework/blazor.web.js/", "Blazor web static files")]
    [InlineData("/_framework/blazor.server.js", "Blazor web static files")]
    [InlineData("/_framework/blazor.web.js", null)]
    [InlineData("/_framework/blazor.web.js", "")]
    [InlineData("/_framework/blazor.web.js", "Blazor web static file")]
    [InlineData("/_framework/blazor.web.js", "Blazor hub")]
    [InlineData("/_blazor/initializers/", "Blazor initializers")]
    [InlineData("/_blazor/initializers", null)]
    [InlineData("/_blazor/initializers", "")]
    [InlineData("/_blazor/initializers", "Blazor initializer")]
    [InlineData("/_blazor/initializers", "Blazor hub")]
    [InlineData("/_blazor/negotiate", "Blazor initializers")]
    public void Other_paths_or_endpoints_remain_protected(string? path, string? displayName)
    {
        RazorComponentEndpointSecurity.ShouldBypassAuthorization(path, displayName)
            .Should().BeFalse();
    }

    [Fact]
    public void Bootstrap_bypass_matching_is_case_sensitive_to_avoid_broad_exemptions()
    {
        RazorComponentEndpointSecurity.ShouldBypassAuthorization(
                "/_FRAMEWORK/blazor.web.js",
                RazorComponentEndpointSecurity.BlazorWebStaticFilesDisplayName)
            .Should().BeFalse();

        RazorComponentEndpointSecurity.ShouldBypassAuthorization(
                RazorComponentEndpointSecurity.BlazorWebBootstrapPath,
                "blazor web static files")
            .Should().BeFalse();

        RazorComponentEndpointSecurity.ShouldBypassAuthorization(
                "/_BLAZOR/initializers",
                RazorComponentEndpointSecurity.BlazorInitializersDisplayName)
            .Should().BeFalse();

        RazorComponentEndpointSecurity.ShouldBypassAuthorization(
                RazorComponentEndpointSecurity.BlazorInitializersPath,
                "blazor initializers")
            .Should().BeFalse();
    }
}
