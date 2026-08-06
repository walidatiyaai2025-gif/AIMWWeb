namespace AIWordPressManager.Web.Services;

public sealed class ConfigurationValidationService
{
    private readonly IConfiguration _configuration;
    private readonly IWebHostEnvironment _environment;

    public ConfigurationValidationService(IConfiguration configuration, IWebHostEnvironment environment)
    {
        _configuration = configuration;
        _environment = environment;
    }

    public ConfigurationValidationReport Validate()
    {
        var checks = new List<ConfigurationValidationItem>();
        var localRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager");
        var logsPath = Path.Combine(localRoot, "Logs");
        var backupPath = Path.Combine(localRoot, "Backups");

        checks.Add(CheckDirectory("local-data", "Local application data", "بيانات التطبيق المحلية", localRoot, true));
        checks.Add(CheckDirectory("logs", "Logs directory", "مجلد السجلات", logsPath, true));
        checks.Add(CheckDirectory("backups", "Backup directory", "مجلد النسخ الاحتياطية", backupPath, true));

        var environmentName = _environment.EnvironmentName;
        checks.Add(new ConfigurationValidationItem(
            "environment",
            "Hosting environment",
            "بيئة الاستضافة",
            string.IsNullOrWhiteSpace(environmentName) ? ValidationStatus.Error : ValidationStatus.Valid,
            environmentName,
            string.IsNullOrWhiteSpace(environmentName) ? "Set ASPNETCORE_ENVIRONMENT." : "Environment name is available.",
            string.IsNullOrWhiteSpace(environmentName) ? "قم بتعيين ASPNETCORE_ENVIRONMENT." : "اسم البيئة متاح."));

        var sqliteConnection = _configuration.GetConnectionString("DefaultConnection")
            ?? _configuration["ConnectionStrings:Sqlite"]
            ?? _configuration["ConnectionStrings:Default"];
        checks.Add(new ConfigurationValidationItem(
            "sqlite-connection",
            "SQLite connection",
            "اتصال SQLite",
            string.IsNullOrWhiteSpace(sqliteConnection) ? ValidationStatus.Warning : ValidationStatus.Valid,
            string.IsNullOrWhiteSpace(sqliteConnection) ? "Code default" : Mask(sqliteConnection),
            string.IsNullOrWhiteSpace(sqliteConnection) ? "No explicit connection string was found; the application will use its code default." : "SQLite connection configuration is present.",
            string.IsNullOrWhiteSpace(sqliteConnection) ? "لم يتم العثور على Connection String صريح؛ سيستخدم التطبيق القيمة الافتراضية من الكود." : "إعداد اتصال SQLite موجود."));

        var allowedHosts = _configuration["AllowedHosts"];
        checks.Add(new ConfigurationValidationItem(
            "allowed-hosts",
            "Allowed hosts",
            "النطاقات المسموحة",
            string.IsNullOrWhiteSpace(allowedHosts) ? ValidationStatus.Warning : ValidationStatus.Valid,
            string.IsNullOrWhiteSpace(allowedHosts) ? "Not configured" : allowedHosts,
            string.IsNullOrWhiteSpace(allowedHosts) ? "Configure AllowedHosts before production deployment." : "AllowedHosts is configured.",
            string.IsNullOrWhiteSpace(allowedHosts) ? "قم بإعداد AllowedHosts قبل النشر على بيئة الإنتاج." : "تم إعداد AllowedHosts."));

        var detailedErrors = _configuration.GetValue<bool?>("DetailedErrors");
        var detailedStatus = _environment.IsDevelopment() || detailedErrors != true ? ValidationStatus.Valid : ValidationStatus.Warning;
        checks.Add(new ConfigurationValidationItem(
            "detailed-errors",
            "Detailed errors",
            "تفاصيل الأخطاء",
            detailedStatus,
            detailedErrors?.ToString() ?? "Default",
            detailedStatus == ValidationStatus.Valid ? "Detailed error exposure is acceptable for the current environment." : "Disable DetailedErrors in production.",
            detailedStatus == ValidationStatus.Valid ? "عرض تفاصيل الأخطاء مناسب للبيئة الحالية." : "عطّل DetailedErrors في بيئة الإنتاج."));

        var critical = checks.Count(x => x.Status == ValidationStatus.Error);
        var warnings = checks.Count(x => x.Status == ValidationStatus.Warning);
        return new ConfigurationValidationReport(DateTime.UtcNow, checks, critical, warnings);
    }

    private static ConfigurationValidationItem CheckDirectory(string key, string title, string titleAr, string path, bool create)
    {
        try
        {
            if (create) Directory.CreateDirectory(path);
            var probe = Path.Combine(path, $".probe-{Guid.NewGuid():N}.tmp");
            File.WriteAllText(probe, "ok");
            File.Delete(probe);
            return new ConfigurationValidationItem(key, title, titleAr, ValidationStatus.Valid, path, "Directory exists and is writable.", "المجلد موجود وقابل للكتابة.");
        }
        catch (Exception ex)
        {
            return new ConfigurationValidationItem(key, title, titleAr, ValidationStatus.Error, path, $"Directory is not writable: {ex.Message}", $"المجلد غير قابل للكتابة: {ex.Message}");
        }
    }

    private static string Mask(string value)
    {
        if (value.Length <= 12) return "Configured";
        return value[..6] + "..." + value[^4..];
    }
}

public sealed record ConfigurationValidationReport(
    DateTime CheckedAtUtc,
    IReadOnlyList<ConfigurationValidationItem> Items,
    int CriticalCount,
    int WarningCount)
{
    public bool IsHealthy => CriticalCount == 0;
}

public sealed record ConfigurationValidationItem(
    string Key,
    string Title,
    string TitleAr,
    ValidationStatus Status,
    string Value,
    string Message,
    string MessageAr);

public enum ValidationStatus
{
    Valid,
    Warning,
    Error
}
