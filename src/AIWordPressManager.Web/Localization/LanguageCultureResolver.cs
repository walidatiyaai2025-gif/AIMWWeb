using System.Globalization;

namespace AIWordPressManager.Web.Localization;

public static class LanguageCultureResolver
{
    public const string CookieName = "aiwm-culture";

    public static string Normalize(string? culture)
    {
        return string.Equals(culture, "ar", StringComparison.OrdinalIgnoreCase)
            ? "ar"
            : "en";
    }

    public static CultureInfo GetCulture(string? culture)
    {
        return new CultureInfo(Normalize(culture));
    }

    public static bool IsRtl(string? culture)
    {
        return Normalize(culture) == "ar";
    }
}
