using AIWordPressManager.Domain.Entities;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class AuthUserTests
{
    [Fact]
    public void Constructor_Normalizes_UserName_And_Enables_Account()
    {
        var now = DateTime.UtcNow;

        var user = new AuthUser("  Standard.User  ", "hash", now);

        user.UserName.Should().Be("Standard.User");
        user.NormalizedUserName.Should().Be("STANDARD.USER");
        user.IsActive.Should().BeTrue();
        user.Role.Should().Be("User");
    }

    [Fact]
    public void Constructor_Uses_Explicit_Administrator_Role()
    {
        var user = new AuthUser("Admin", "hash", DateTime.UtcNow, "Administrator");

        user.Role.Should().Be("Administrator");
    }

    [Fact]
    public void Five_Failed_Logins_Lock_Account_For_Fifteen_Minutes()
    {
        var now = new DateTime(2026, 8, 5, 10, 0, 0, DateTimeKind.Utc);
        var user = new AuthUser("Admin", "hash", now);

        for (var i = 0; i < 5; i++)
            user.RecordFailedLogin(now.AddSeconds(i));

        user.FailedAccessCount.Should().Be(5);
        user.LockedUntilUtc.Should().Be(now.AddSeconds(4).AddMinutes(15));
        user.IsLockedOut(now.AddMinutes(1)).Should().BeTrue();
    }

    [Fact]
    public void Lockout_Is_Not_Active_After_Expiry()
    {
        var now = new DateTime(2026, 8, 5, 10, 0, 0, DateTimeKind.Utc);
        var user = new AuthUser("Admin", "hash", now);
        for (var i = 0; i < 5; i++) user.RecordFailedLogin(now);

        user.IsLockedOut(now.AddMinutes(14)).Should().BeTrue();
        user.IsLockedOut(now.AddMinutes(15)).Should().BeFalse();
        user.IsLockedOut(now.AddMinutes(16)).Should().BeFalse();
    }

    [Fact]
    public void First_Failure_After_Expired_Lockout_Starts_A_New_Attempt_Window()
    {
        var now = new DateTime(2026, 8, 5, 10, 0, 0, DateTimeKind.Utc);
        var user = new AuthUser("Admin", "hash", now);
        for (var i = 0; i < 5; i++) user.RecordFailedLogin(now);

        user.RecordFailedLogin(now.AddMinutes(16));

        user.FailedAccessCount.Should().Be(1);
        user.LockedUntilUtc.Should().BeNull();
    }

    [Fact]
    public void Successful_Login_Clears_Failed_Count_And_Lock()
    {
        var now = DateTime.UtcNow;
        var user = new AuthUser("Admin", "hash", now);
        for (var i = 0; i < 5; i++) user.RecordFailedLogin(now);

        var successAt = now.AddMinutes(16);
        user.RecordSuccessfulLogin(successAt);

        user.FailedAccessCount.Should().Be(0);
        user.LockedUntilUtc.Should().BeNull();
        user.LastLoginAtUtc.Should().Be(successAt);
    }

    [Theory]
    [InlineData("/module/media")]
    [InlineData("/sites/123?tab=content")]
    [InlineData("/settings")]
    public void SetLastPage_Accepts_Safe_Local_Paths(string path)
    {
        var user = new AuthUser("Admin", "hash", DateTime.UtcNow);

        user.SetLastPage(path, DateTime.UtcNow);

        user.LastPage.Should().Be(path);
    }

    [Theory]
    [InlineData("https://evil.example")]
    [InlineData("//evil.example")]
    [InlineData("")]
    [InlineData(null)]
    public void SetLastPage_Rejects_Unsafe_Paths(string? path)
    {
        var user = new AuthUser("Admin", "hash", DateTime.UtcNow);

        user.SetLastPage(path, DateTime.UtcNow);

        user.LastPage.Should().Be("/");
    }
}
