using AIWordPressManager.Domain.Entities;

namespace AIWordPressManager.Web.Services;

public static class EmailScheduleCalculator
{
    public static DateTime CalculateNextRunUtc(
        string timezoneId,
        string frequency,
        TimeSpan timeOfDay,
        int? weekday,
        int? monthDay,
        DateTime utcNow)
    {
        var zone = ResolveTimeZone(timezoneId);
        var nowUtc = DateTime.SpecifyKind(utcNow, DateTimeKind.Utc);
        var localNow = TimeZoneInfo.ConvertTimeFromUtc(nowUtc, zone);

        DateTime localCandidate = frequency switch
        {
            EmailSchedule.HourlyFrequency => NextHourly(localNow, timeOfDay),
            EmailSchedule.DailyFrequency => NextDaily(localNow, timeOfDay),
            EmailSchedule.WeeklyFrequency => NextWeekly(localNow, timeOfDay, weekday),
            EmailSchedule.MonthlyFrequency => NextMonthly(localNow, timeOfDay, monthDay),
            _ => throw new ArgumentException("Unsupported email schedule frequency.", nameof(frequency))
        };

        localCandidate = NormalizeInvalidLocalTime(localCandidate, zone);

        if (zone.IsAmbiguousTime(localCandidate))
        {
            var offsets = zone.GetAmbiguousTimeOffsets(localCandidate);
            var chosenOffset = offsets.Max();
            return new DateTimeOffset(DateTime.SpecifyKind(localCandidate, DateTimeKind.Unspecified), chosenOffset).UtcDateTime;
        }

        return TimeZoneInfo.ConvertTimeToUtc(DateTime.SpecifyKind(localCandidate, DateTimeKind.Unspecified), zone);
    }

    public static TimeZoneInfo ResolveTimeZone(string timezoneId)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(timezoneId);
        try
        {
            return TimeZoneInfo.FindSystemTimeZoneById(timezoneId.Trim());
        }
        catch (TimeZoneNotFoundException ex)
        {
            throw new ArgumentException($"Unknown timezone '{timezoneId}'.", nameof(timezoneId), ex);
        }
        catch (InvalidTimeZoneException ex)
        {
            throw new ArgumentException($"Invalid timezone '{timezoneId}'.", nameof(timezoneId), ex);
        }
    }

    private static DateTime NextHourly(DateTime localNow, TimeSpan minuteSecond)
    {
        var minute = Math.Clamp(minuteSecond.Minutes, 0, 59);
        var second = Math.Clamp(minuteSecond.Seconds, 0, 59);
        var candidate = new DateTime(localNow.Year, localNow.Month, localNow.Day, localNow.Hour, minute, second, DateTimeKind.Unspecified);
        if (candidate <= localNow) candidate = candidate.AddHours(1);
        return candidate;
    }

    private static DateTime NextDaily(DateTime localNow, TimeSpan timeOfDay)
    {
        var candidate = localNow.Date.Add(timeOfDay);
        if (candidate <= localNow) candidate = candidate.AddDays(1);
        return DateTime.SpecifyKind(candidate, DateTimeKind.Unspecified);
    }

    private static DateTime NextWeekly(DateTime localNow, TimeSpan timeOfDay, int? weekday)
    {
        if (weekday is < 0 or > 6) throw new ArgumentOutOfRangeException(nameof(weekday));
        var target = (DayOfWeek)weekday!.Value;
        var days = ((int)target - (int)localNow.DayOfWeek + 7) % 7;
        var candidate = localNow.Date.AddDays(days).Add(timeOfDay);
        if (candidate <= localNow) candidate = candidate.AddDays(7);
        return DateTime.SpecifyKind(candidate, DateTimeKind.Unspecified);
    }

    private static DateTime NextMonthly(DateTime localNow, TimeSpan timeOfDay, int? monthDay)
    {
        if (monthDay is < 1 or > 31) throw new ArgumentOutOfRangeException(nameof(monthDay));
        var year = localNow.Year;
        var month = localNow.Month;

        for (var i = 0; i < 24; i++)
        {
            var day = Math.Min(monthDay!.Value, DateTime.DaysInMonth(year, month));
            var candidate = new DateTime(year, month, day, 0, 0, 0, DateTimeKind.Unspecified).Add(timeOfDay);
            if (candidate > localNow) return candidate;
            month++;
            if (month == 13) { month = 1; year++; }
        }

        throw new InvalidOperationException("Unable to calculate the next monthly email schedule occurrence.");
    }

    private static DateTime NormalizeInvalidLocalTime(DateTime localCandidate, TimeZoneInfo zone)
    {
        if (!zone.IsInvalidTime(localCandidate)) return localCandidate;
        for (var i = 0; i < 180; i++)
        {
            localCandidate = localCandidate.AddMinutes(1);
            if (!zone.IsInvalidTime(localCandidate)) return localCandidate;
        }
        throw new InvalidOperationException("Unable to normalize an invalid local schedule time around a daylight-saving transition.");
    }
}
