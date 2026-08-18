using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Authorization.Policy;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Preserves the application's default authorization behavior while allowing the embedded
/// Blazor bootstrap script to execute even though .NET 8 maps it through the Razor Components
/// endpoint data source that inherits the app-wide authorization convention.
/// </summary>
public sealed class BlazorFrameworkAuthorizationResultHandler : IAuthorizationMiddlewareResultHandler
{
    private readonly AuthorizationMiddlewareResultHandler _defaultHandler = new();

    public Task HandleAsync(
        RequestDelegate next,
        HttpContext context,
        AuthorizationPolicy policy,
        PolicyAuthorizationResult authorizeResult)
    {
        if (RazorComponentEndpointSecurity.ShouldBypassAuthorization(
                context.Request.Path.Value,
                context.GetEndpoint()?.DisplayName))
        {
            return next(context);
        }

        return _defaultHandler.HandleAsync(next, context, policy, authorizeResult);
    }
}
