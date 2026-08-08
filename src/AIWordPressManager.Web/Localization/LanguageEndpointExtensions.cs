using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Web.Localization;

public static class LanguageEndpointExtensions
{
    public static IEndpointRouteBuilder MapLanguageEndpoints(this IEndpointRouteBuilder endpoints)
    {
        endpoints.MapGet("/api/language/set/{culture}", (HttpContext context, string culture) =>
        {
            var normalized = LanguageCookieOptions.Normalize(culture);

            context.Response.Cookies.Append(
                LanguageCookieOptions.CookieName,
                normalized,
                new CookieOptions
                {
                    HttpOnly = false,
                    SameSite = SameSiteMode.Lax,
                    Expires = DateTimeOffset.UtcNow.AddYears(1)
                });

            return Results.Ok(new
            {
                culture = normalized,
                rtl = LanguageCookieOptions.IsRtl(normalized)
            });
        });

        return endpoints;
    }
}
