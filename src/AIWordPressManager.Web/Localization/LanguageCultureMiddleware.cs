using System.Globalization;

namespace AIWordPressManager.Web.Localization;

public sealed class LanguageCultureMiddleware
{
    private readonly RequestDelegate _next;
    public const string CookieName = "aiwm-culture";

    public LanguageCultureMiddleware(RequestDelegate next)
    {
        _next = next;
    }

    public async Task InvokeAsync(HttpContext context)
    {
        var cultureName = context.Request.Cookies[CookieName];

        if (string.IsNullOrWhiteSpace(cultureName))
        {
            cultureName = context.Request.Headers.AcceptLanguage
                .FirstOrDefault()?
                .Split(',', StringSplitOptions.RemoveEmptyEntries)
                .FirstOrDefault();
        }

        cultureName = cultureName?.StartsWith("ar", StringComparison.OrdinalIgnoreCase) == true
            ? "ar"
            : "en";

        var culture = new CultureInfo(cultureName);
        CultureInfo.CurrentCulture = culture;
        CultureInfo.CurrentUICulture = culture;

        await _next(context);
    }
}
