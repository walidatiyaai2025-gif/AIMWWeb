using System.Globalization;

namespace AIWordPressManager.Web.Localization;

public sealed class LanguageCultureMiddleware
{
    private readonly RequestDelegate _next;

    public LanguageCultureMiddleware(RequestDelegate next)
    {
        _next = next;
    }

    public async Task InvokeAsync(HttpContext context, AppLanguageService languageService)
    {
        var cultureName = languageService.CurrentCulture == "ar" ? "ar" : "en";
        var culture = new CultureInfo(cultureName);

        CultureInfo.CurrentCulture = culture;
        CultureInfo.CurrentUICulture = culture;

        await _next(context);
    }
}
