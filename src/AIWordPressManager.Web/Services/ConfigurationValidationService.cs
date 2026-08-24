using AIWordPressManager.Application.Abstractions;

namespace AIWordPressManager.Web.Services;

public sealed class ConfigurationValidationService
{
    private static readonly HashSet<string> SupportedProviders = new(StringComparer.OrdinalIgnoreCase)
    {
        "SQLite", "SqlServer", "PostgreSQL", "Postgres", "MySQL", "MariaDB"
    };

    private readonly IConfiguration _configuration;
    private readonly IWebHostEnvironment _environment;
    private readonly IApplicationPathService _paths;
    private readonly CurrentUserContext _currentUser;

    public ConfigurationValidationService(
        IConfiguration configuration,
        IWebHostEnvironment environment,
        IApplicationPathService paths,
        CurrentUserContext currentUser)
    {
        _configuration = configuration;
        _environment = environment;
        _paths = paths;
        _currentUser = currentUser;
    }

    public ConfigurationValidationReport Validate()
    {
        _currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);

        var checks = new List<ConfigurationValidationItem>();

        var dataPath = _paths.GetApplicationDataDirectory();
        var logsPath = _paths.GetLogsDirectory();
        var backupPath = _paths.GetBackupsDirectory();

        checks.Add(CheckDirectory("local-data", "Application data", "بيانات التطبيق", dataPath, true));
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

        var provider = (_configuration["Database:Provider"] ?? "SQLite").Trim();
        var setupComplete = _configuration.GetValue<bool>("Database:SetupComplete");
        var providerSupported = SupportedProviders.Contains(provider);
        var connectionConfigured = HasDatabaseConnectionConfiguration(provider);

        var databaseStatus = !providerSupported
            ? ValidationStatus.Error
            : setupComplete && connectionConfigured
                ? ValidationStatus.Valid
                : ValidationStatus.Warning;

        var databaseMessage = !providerSupported
            ? $"Database provider '{provider}' is not supported by the current build."
            : !setupComplete
                ? "Database first-run setup has not been completed."
                : connectionConfigured
                    ? "Database provider and connection settings are configured. Credentials are not included in this report."
                    : "Database setup is marked complete but its connection settings are missing.";

        var databaseMessageAr = !providerSupported
            ? $"مزود قاعدة البيانات '{provider}' غير مدعوم في الإصدار الحالي."
            : !setupComplete
                ? "لم يكتمل إعداد قاعدة البيانات لأول تشغيل."
                : connectionConfigured
                    ? "مزود قاعدة البيانات وإعدادات الاتصال موجودة. لا يتم عرض بيانات الاعتماد في هذا التقرير."
                    : "الإعداد مسجل كمكتمل لكن بيانات اتصال قاعدة البيانات غير موجودة.";

        checks.Add(new ConfigurationValidationItem(
            "database-setup",
            "Database setup",
            "إعداد قاعدة البيانات",
            databaseStatus,
            provider,
            databaseMessage,
            databaseMessageAr));

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

    private bool HasDatabaseConnectionConfiguration(string provider)
    {
        if (provider.Equals("SQLite", StringComparison.OrdinalIgnoreCase))
        {
            return !string.IsNullOrWhiteSpace(_configuration["Database:ConnectionString"])
                || File.Exists(_paths.GetDatabasePath());
        }

        return !string.IsNullOrWhiteSpace(_configuration["Database:ProtectedConnectionString"])
            || !string.IsNullOrWhiteSpace(_configuration["Database:ConnectionString"]);
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