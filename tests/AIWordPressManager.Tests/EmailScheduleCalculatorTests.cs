using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class EmailScheduleCalculatorTests
{
    [Fact]
    public void Daily_Schedule_Uses_Next_Local_Occurrence()
    {
        var now = new DateTime(2026, 8, 8, 7, 30, 0, DateTimeKind.Utc);

        var next = EmailScheduleCalculator.CalculateNextRunUtc(
            "UTC", EmailSchedule.DailyFrequency, new TimeSpan(8, 0, 0), null, null, now);

        next.Should().Be(new DateTime(2026, 8, 8, 8, 0, 0, DateTimeKind.Utc));
    }

    [Fact]
    public void Daily_Schedule_Rolls_To_Tomorrow_When_Time_Has_Passed()
    {
        var now = new DateTime(2026, 8, 8, 9, 0, 0, DateTimeKind.Utc);

        var next = EmailScheduleCalculator.CalculateNextRunUtc(
            "UTC", EmailSchedule.DailyFrequency, new TimeSpan(8, 0, 0), null, null, now);

        next.Should().Be(new DateTime(2026, 8, 9, 8, 0, 0, DateTimeKind.Utc));
    }

    [Fact]
    public void Weekly_Schedule_Rolls_To_Target_Weekday()
    {
        var now = new DateTime(2026, 8, 8, 9, 0, 0, DateTimeKind.Utc); // Saturday

        var next = EmailScheduleCalculator.CalculateNextRunUtc(
            "UTC", EmailSchedule.WeeklyFrequency, new TimeSpan(10, 0, 0), (int)DayOfWeek.Monday, null, now);

        next.Should().Be(new DateTime(2026, 8, 10, 10, 0, 0, DateTimeKind.Utc));
    }

    [Fact]
    public void Monthly_Schedule_Clamps_Day_To_End_Of_Short_Month()
    {
        var now = new DateTime(2026, 2, 1, 0, 0, 0, DateTimeKind.Utc);

        var next = EmailScheduleCalculator.CalculateNextRunUtc(
            "UTC", EmailSchedule.MonthlyFrequency, new TimeSpan(8, 0, 0), null, 31, now);

        next.Should().Be(new DateTime(2026, 2, 28, 8, 0, 0, DateTimeKind.Utc));
    }

    [Fact]
    public void Hourly_Schedule_Uses_Configured_Minute_And_Second()
    {
        var now = new DateTime(2026, 8, 8, 9, 15, 30, DateTimeKind.Utc);

        var next = EmailScheduleCalculator.CalculateNextRunUtc(
            "UTC", EmailSchedule.HourlyFrequency, new TimeSpan(0, 20, 0), null, null, now);

        next.Should().Be(new DateTime(2026, 8, 8, 9, 20, 0, DateTimeKind.Utc));
    }

    [Fact]
    public void Unknown_Timezone_Is_Rejected()
    {
        var action = () => EmailScheduleCalculator.CalculateNextRunUtc(
            "Not/A/Real-Timezone", EmailSchedule.DailyFrequency, TimeSpan.Zero, null, null, DateTime.UtcNow);

        action.Should().Throw<ArgumentException>();
    }
}
