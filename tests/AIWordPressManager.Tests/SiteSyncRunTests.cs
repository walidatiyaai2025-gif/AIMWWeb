using AIWordPressManager.Domain.Entities;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class SiteSyncRunTests
{
    [Fact]
    public void Constructor_StartsRunningForExpectedSite()
    {
        var siteId = Guid.NewGuid();
        var startedAt = new DateTime(2026, 8, 9, 9, 45, 0, DateTimeKind.Utc);

        var run = new SiteSyncRun(siteId, startedAt);

        run.SiteId.Should().Be(siteId);
        run.Status.Should().Be("Running");
        run.StartedAtUtc.Should().Be(startedAt);
        run.CompletedAtUtc.Should().BeNull();
        run.WasSkipped.Should().BeFalse();
        run.DownloadedRecords.Should().Be(0);
    }

    [Fact]
    public void Complete_NormalRun_RecordsCompletedStatusAndDownloadedCount()
    {
        var run = new SiteSyncRun(Guid.NewGuid(), DateTime.UtcNow.AddMinutes(-2));
        var completedAt = DateTime.UtcNow;

        run.Complete("Downloaded fresh content.", 37, false, completedAt);

        run.Status.Should().Be("Completed");
        run.WasSkipped.Should().BeFalse();
        run.DownloadedRecords.Should().Be(37);
        run.CompletedAtUtc.Should().Be(completedAt);
        run.Message.Should().Be("Downloaded fresh content.");
    }

    [Fact]
    public void Complete_SkippedRun_RecordsSkippedStatus()
    {
        var run = new SiteSyncRun(Guid.NewGuid(), DateTime.UtcNow.AddSeconds(-5));
        var completedAt = DateTime.UtcNow;

        run.Complete("No remote changes.", 0, true, completedAt);

        run.Status.Should().Be("Skipped");
        run.WasSkipped.Should().BeTrue();
        run.DownloadedRecords.Should().Be(0);
        run.CompletedAtUtc.Should().Be(completedAt);
    }

    [Fact]
    public void Fail_RecordsFailureWithoutPersistingUnboundedMessage()
    {
        var run = new SiteSyncRun(Guid.NewGuid(), DateTime.UtcNow.AddSeconds(-5));
        var completedAt = DateTime.UtcNow;
        var error = new string('x', 2500);

        run.Fail(error, completedAt);

        run.Status.Should().Be("Failed");
        run.WasSkipped.Should().BeFalse();
        run.DownloadedRecords.Should().Be(0);
        run.CompletedAtUtc.Should().Be(completedAt);
        run.Message.Should().HaveLength(2000);
    }
}
