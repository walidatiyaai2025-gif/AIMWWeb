using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class EmailSchedule : Entity
{
    public const string SiteScope = "Site";
    public const string AccountScope = "Account";

    public const string HourlyFrequency = "Hourly";
    public const string DailyFrequency = "Daily";
    public const string WeeklyFrequency = "Weekly";
    public const string MonthlyFrequency = "Monthly";

    private EmailSchedule()
    {
    }

    public EmailSchedule(Guid ownerUserId, Guid? siteId, string scope, string reportType, string templateKey, string timezoneId, DateTime utcNow)
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
        if (string.Equals(scope, SiteScope, StringComparison.OrdinalIgnoreCase) && (!siteId.HasValue || siteId == Guid.Empty))
            throw new ArgumentException("Site schedules require a site ID.", nameof(siteId));

        OwnerUserId = ownerUserId;
        SiteId = siteId;
        Scope = NormalizeScope(scope);
        ReportType = NormalizeRequired(reportType, 120, nameof(reportType));
        TemplateKey = NormalizeRequired(templateKey, 160, nameof(templateKey));
        TimezoneId = NormalizeRequired(timezoneId, 120, nameof(timezoneId));
        Frequency = DailyFrequency;
        TimeOfDay = new TimeSpan(8, 0, 0);
        RetryCount = 3;
        RetryDelayMinutes = 5;
        IsEnabled = false;
        LastStatus = "NeverRun";
        NextRunUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public Guid OwnerUserId { get; private set; }
    public Guid? SiteId { get; private set; }
    public string Scope { get; private set; } = SiteScope;
    public string ReportType { get; private set; } = string.Empty;
    public string TemplateKey { get; private set; } = string.Empty;
    public string TimezoneId { get; private set; } = "UTC";
    public string Frequency { get; private set; } = DailyFrequency;
    public TimeSpan TimeOfDay { get; private set; } = new(8, 0, 0);
    public int? Weekday { get; private set; }
    public int? MonthDay { get; private set; }
    public bool IsEnabled { get; private set; }
    public int RetryCount { get; private set; } = 3;
    public int RetryDelayMinutes { get; private set; } = 5;
    public DateTime NextRunUtc { get; private set; }
    public DateTime? LastRunUtc { get; private set; }
    public string LastStatus { get; private set; } = "NeverRun";
    public string? LastError { get; private set; }

    public void Configure(
        string timezoneId,
        string frequency,
        TimeSpan timeOfDay,
        int? weekday,
        int? monthDay,
        int retryCount,
        int retryDelayMinutes,
        bool enabled,
        DateTime nextRunUtc,
        DateTime utcNow)
    {
        var normalizedFrequency = NormalizeFrequency(frequency);
        var normalizedTimezone = NormalizeRequired(timezoneId, 120, nameof(timezoneId));
        if (timeOfDay < TimeSpan.Zero || timeOfDay >= TimeSpan.FromDays(1)) throw new ArgumentOutOfRangeException(nameof(timeOfDay));
        if (normalizedFrequency == WeeklyFrequency && (weekday is < 0 or > 6)) throw new ArgumentOutOfRangeException(nameof(weekday));
        if (normalizedFrequency == MonthlyFrequency && (monthDay is < 1 or > 31)) throw new ArgumentOutOfRangeException(nameof(monthDay));
        if (retryCount is < 0 or > 10) throw new ArgumentOutOfRangeException(nameof(retryCount));
        if (retryDelayMinutes is < 1 or > 1440) throw new ArgumentOutOfRangeException(nameof(retryDelayMinutes));

        TimezoneId = normalizedTimezone;
        Frequency = normalizedFrequency;
        TimeOfDay = timeOfDay;
        Weekday = normalizedFrequency == WeeklyFrequency ? weekday : null;
        MonthDay = normalizedFrequency == MonthlyFrequency ? monthDay : null;
        RetryCount = retryCount;
        RetryDelayMinutes = retryDelayMinutes;
        IsEnabled = enabled;
        NextRunUtc = nextRunUtc;
        MarkUpdated(utcNow);
    }

    public void RecordRun(string status, string? error, DateTime nextRunUtc, DateTime utcNow)
    {
        LastRunUtc = utcNow;
        LastStatus = NormalizeRequired(status, 32, nameof(status));
        LastError = string.IsNullOrWhiteSpace(error) ? null : error.Trim()[..Math.Min(error.Trim().Length, 1000)];
        NextRunUtc = nextRunUtc;
        MarkUpdated(utcNow);
    }

    private static string NormalizeScope(string value)
    {
        if (string.Equals(value, SiteScope, StringComparison.OrdinalIgnoreCase)) return SiteScope;
        if (string.Equals(value, AccountScope, StringComparison.OrdinalIgnoreCase)) return AccountScope;
        throw new ArgumentException("Email schedule scope must be Site or Account.", nameof(value));
    }

    private static string NormalizeFrequency(string value)
    {
        if (string.Equals(value, HourlyFrequency, StringComparison.OrdinalIgnoreCase)) return HourlyFrequency;
        if (string.Equals(value, DailyFrequency, StringComparison.OrdinalIgnoreCase)) return DailyFrequency;
        if (string.Equals(value, WeeklyFrequency, StringComparison.OrdinalIgnoreCase)) return WeeklyFrequency;
        if (string.Equals(value, MonthlyFrequency, StringComparison.OrdinalIgnoreCase)) return MonthlyFrequency;
        throw new ArgumentException("Unsupported email schedule frequency.", nameof(value));
    }

    private static string NormalizeRequired(string value, int maxLength, string parameterName)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value, parameterName);
        var clean = value.Trim();
        if (clean.Length > maxLength) throw new ArgumentException($"Value exceeds {maxLength} characters.", parameterName);
        return clean;
    }
}
