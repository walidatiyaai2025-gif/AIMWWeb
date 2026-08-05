using AIWordPressManager.Domain.Entities;

namespace AIWordPressManager.Tests;

public sealed class LoginAuditTests
{
    [Fact]
    public void Constructor_NormalizesMissingValues()
    {
        var now = new DateTime(2026, 8, 5, 10, 30, 0, DateTimeKind.Utc);

        var audit = new LoginAudit("  ", false, "", null, null, now);

        Assert.Equal("(empty)", audit.UserName);
        Assert.Equal("Failed", audit.Reason);
        Assert.Equal("Unknown", audit.IpAddress);
        Assert.Equal("Unknown", audit.UserAgent);
        Assert.Equal(now, audit.AttemptedAtUtc);
        Assert.False(audit.Succeeded);
    }

    [Fact]
    public void Constructor_PreservesSuccessfulAttemptDetails()
    {
        var now = DateTime.UtcNow;

        var audit = new LoginAudit(" Admin ", true, " Success ", "127.0.0.1", "Browser", now);

        Assert.Equal("Admin", audit.UserName);
        Assert.True(audit.Succeeded);
        Assert.Equal("Success", audit.Reason);
        Assert.Equal("127.0.0.1", audit.IpAddress);
        Assert.Equal("Browser", audit.UserAgent);
    }
}
