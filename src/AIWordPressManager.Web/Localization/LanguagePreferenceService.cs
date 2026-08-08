using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Web.Localization;

/// <summary>
/// Persists the selected UI language for the current browser.
/// Supported cultures: en, ar.
/// </summary>
public sealed class LanguagePreferenceService
{
    private const string CookieName = "AIWM.Language";
    private const string DefaultLanguage = "en";

    private readonly IHttpContextAccessor _httpContextAccessor;

    public LanguagePreferenceService(IHttpContextAccessor httpContextAccessor)
    {
        _httpContextAccessor = httpContextAccessor;
    }

    public string GetLanguage()
    {
        var context = _httpContextAccessor.HttpContext;
        var value = context?.Request.Cookies[CookieName];

        return IsSupported(value) ? value! : DefaultLanguage;
    }

    public void SetLanguage(string language)
    {
        var normalized = IsSupported(language) ? language : DefaultLanguage;
        var context = _httpContextAccessor.HttpContext;

        if (context == null)
            return;

        context.Response.Cookies.Append(
            CookieName,
            normalized,
            new CookieOptions
            {
                Expires = DateTimeOffset.UtcNow.AddYears(1),
                HttpOnly = false,
                SameSite = SameSiteMode.Lax,
                Secure = context.Request.IsHttps
            });
    }

    private static bool IsSupported(string? language)
        => string.Equals(language, "ar", StringComparison.OrdinalIgnoreCase)
           || string.Equals(language, "en", StringComparison.OrdinalIgnoreCase);
}
