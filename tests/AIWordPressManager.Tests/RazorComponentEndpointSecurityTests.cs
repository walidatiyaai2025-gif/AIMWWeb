using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class RazorComponentEndpointSecurityTests
{
    [Fact]
    public void Exact_blazor_bootstrap_endpoint_is_the_only_authorization_bypass()
    {
        RazorComponentEndpointSecurity.ShouldBypassAuthorization(
                RazorComponentEndpointSecurity.BlazorWebBootstrapPath,
                RazorComponentEndpointSecurity.BlazorWebStaticFilesDisplayName)
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
    }
}
