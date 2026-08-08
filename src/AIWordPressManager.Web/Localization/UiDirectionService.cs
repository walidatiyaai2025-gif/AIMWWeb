namespace AIWordPressManager.Web.Localization;

public sealed class UiDirectionService
{
    private readonly AppLanguageService _languageService;

    public UiDirectionService(AppLanguageService languageService)
    {
        _languageService = languageService;
    }

    public string Language =>
        _languageService.CurrentCulture.Equals("ar", StringComparison.OrdinalIgnoreCase)
            ? "ar"
            : "en";

    public string Direction =>
        Language == "ar" ? "rtl" : "ltr";

    public bool IsRtl =>
        Direction == "rtl";
}
