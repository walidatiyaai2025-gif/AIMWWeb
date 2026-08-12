using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Builder;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Keeps the Razor Components application protected while allowing the framework bootstrap
/// asset endpoint to remain reachable before authentication.
/// </summary>
public static class RazorComponentEndpointSecurity
{
    public const string BlazorWebStaticFilesDisplayName = "Blazor web static files";

    public static bool ShouldAllowAnonymous(string? displayName)
        => string.Equals(displayName, BlazorWebStaticFilesDisplayName, StringComparison.Ordinal);
}

/// <summary>
/// Specializes authorization for Razor Components so framework bootstrap assets remain public
/// without relaxing authorization for the component page endpoints themselves.
/// </summary>
public static class RazorComponentAuthorizationExtensions
{
    public static RazorComponentsEndpointConventionBuilder RequireAuthorization(
        this RazorComponentsEndpointConventionBuilder builder)
    {
        ArgumentNullException.ThrowIfNull(builder);

        Microsoft.AspNetCore.Builder.AuthorizationEndpointConventionBuilderExtensions
            .RequireAuthorization(builder);

        builder.Add(endpointBuilder =>
        {
            if (RazorComponentEndpointSecurity.ShouldAllowAnonymous(endpointBuilder.DisplayName))
            {
                endpointBuilder.Metadata.Add(new AllowAnonymousAttribute());
            }
        });

        return builder;
    }
}
