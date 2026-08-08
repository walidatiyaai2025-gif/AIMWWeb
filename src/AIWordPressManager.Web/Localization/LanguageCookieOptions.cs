namespace AIWordPressManager.Web.Localization;

public static class LanguageCookieOptions
{
    public const string CookieName = "aiwm-culture";

    public const string DefaultCulture = "en";

    public static bool IsSupported(string? culture)
    {
        return string.Equals(culture, "en", StringComparison.OrdinalIgnoreCase)
            || string.Equals(culture, "ar", StringComparison.OrdinalIgnoreCase);
    }

    public static string Normalize(string? culture)
    {
        return IsSupported(culture) && !string.IsNullOrWhiteSpace(culture)
            ? culture!.ToLowerInvariant()
            : DefaultCulture;
    }

    public static bool IsRtl(string? culture)
    {
        return Normalize(culture) == "ar";
    }
}
