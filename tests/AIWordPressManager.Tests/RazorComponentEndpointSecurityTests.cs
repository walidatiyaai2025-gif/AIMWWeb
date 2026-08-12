using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class RazorComponentEndpointSecurityTests
{
    [Fact]
    public void Blazor_web_static_files_endpoint_is_the_only_public_component_endpoint()
    {
        RazorComponentEndpointSecurity.ShouldAllowAnonymous(
                RazorComponentEndpointSecurity.BlazorWebStaticFilesDisplayName)
            .Should().BeTrue();
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("/")]
    [InlineData("Blazor web static file")]
    [InlineData("Blazor web static files ")]
    [InlineData("Blazor hub")]
    public void Other_component_endpoints_remain_protected(string? displayName)
    {
        RazorComponentEndpointSecurity.ShouldAllowAnonymous(displayName)
            .Should().BeFalse();
    }

    [Fact]
    public void Framework_endpoint_matching_is_case_sensitive_to_avoid_broad_exemptions()
    {
        RazorComponentEndpointSecurity.ShouldAllowAnonymous("blazor web static files")
            .Should().BeFalse();
    }
}
