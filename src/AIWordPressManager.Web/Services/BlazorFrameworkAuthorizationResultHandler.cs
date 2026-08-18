using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Authorization.Policy;
using AIWordPressManager.Persistence;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Preserves the application's default authorization behavior while allowing the embedded
/// Blazor bootstrap script to execute even though .NET 8 maps it through the Razor Components
/// endpoint data source that inherits the app-wide authorization convention. Authenticated
/// protected requests are additionally revalidated against the server-side session registry.
/// </summary>
public sealed class BlazorFrameworkAuthorizationResultHandler : IAuthorizationMiddlewareResultHandler
{
    private readonly AuthorizationMiddlewareResultHandler _defaultHandler = new();

    public async Task HandleAsync(
        RequestDelegate next,
        HttpContext context,
        AuthorizationPolicy policy,
        PolicyAuthorizationResult authorizeResult)
    {
        if (RazorComponentEndpointSecurity.ShouldBypassAuthorization(
                context.Request.Path.Value,
                context.GetEndpoint()?.DisplayName))
        {
            await next(context);
            return;
        }

        if (context.User.Identity?.IsAuthenticated == true)
        {
            var dbContext = context.RequestServices.GetRequiredService<AppDbContext>();
            var validator = new ApplicationSessionRequestValidator(dbContext);
            var validation = await validator.ValidateAsync(context.User, context.RequestAborted);
            if (!validation.IsValid)
            {
                context.Response.Cookies.Delete("AIWM.Auth");
                await context.SignOutAsync(CookieAuthenticationDefaults.AuthenticationScheme);
                await context.ChallengeAsync(CookieAuthenticationDefaults.AuthenticationScheme);
                return;
            }

            if (context.Request.Path.Equals("/logout", StringComparison.OrdinalIgnoreCase))
                await validator.EndCurrentOnLogoutAsync(context.User, context.RequestAborted);
        }

        await _defaultHandler.HandleAsync(next, context, policy, authorizeResult);
    }
}