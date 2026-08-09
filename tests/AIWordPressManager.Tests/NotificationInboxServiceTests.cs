using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Tests;

public sealed class NotificationInboxServiceTests
{
    [Fact]
    public void Legacy_schema_is_upgraded_without_assigning_legacy_rows_to_a_tenant()
    {
        var path = TempDatabasePath();
        try
        {
            CreateLegacyDatabase(path);
            var ownerA = Guid.NewGuid();
            var ownerB = Guid.NewGuid();
            var siteId = Guid.NewGuid();
            var jobId = Guid.NewGuid();

            var service = NotificationInboxService.ForDatabase(path);
            var created = service.Create(
                ownerA,
                "Completed",
                "Background operation completed.",
                NotificationSeverity.Success,
                relatedId: Guid.NewGuid(),
                siteId: siteId,
                executionJobId: jobId,
                source: "TestWorker");
            service.Create(ownerB, "Other tenant", "Must stay isolated.", NotificationSeverity.Warning);

            var first = service.Get(ownerA);
            first.Should().ContainSingle();
            first[0].Id.Should().Be(created.Id);
            first[0].OwnerUserId.Should().Be(ownerA);
            first[0].SiteId.Should().Be(siteId);
            first[0].ExecutionJobId.Should().Be(jobId);
            first[0].Source.Should().Be("TestWorker");
            first.Should().NotContain(x => x.Title == "Legacy system notification");

            var restarted = NotificationInboxService.ForDatabase(path);
            restarted.Get(ownerA).Should().ContainSingle(x => x.Id == created.Id);
            restarted.Get(ownerB).Should().ContainSingle(x => x.Title == "Other tenant");
        }
        finally
        {
            DeleteDatabase(path);
        }
    }

    [Fact]
    public void Read_and_dismiss_mutations_require_matching_owner()
    {
        var path = TempDatabasePath();
        try
        {
            var ownerA = Guid.NewGuid();
            var ownerB = Guid.NewGuid();
            var service = NotificationInboxService.ForDatabase(path);
            var item = service.Create(ownerA, "Private", "Owner A only.", NotificationSeverity.Information);

            service.MarkRead(ownerB, item.Id).Should().BeFalse();
            service.Get(ownerA, unreadOnly: true).Should().ContainSingle(x => x.Id == item.Id);

            service.MarkRead(ownerA, item.Id).Should().BeTrue();
            var read = service.Get(ownerA).Single();
            read.IsRead.Should().BeTrue();
            read.ReadAtUtc.Should().NotBeNull();

            service.Dismiss(ownerB, item.Id).Should().BeFalse();
            service.Get(ownerA).Should().ContainSingle();

            service.Dismiss(ownerA, item.Id).Should().BeTrue();
            service.Get(ownerA).Should().BeEmpty();
        }
        finally
        {
            DeleteDatabase(path);
        }
    }

    [Fact]
    public void Mark_all_read_and_retention_are_owner_scoped()
    {
        var path = TempDatabasePath();
        try
        {
            var ownerA = Guid.NewGuid();
            var ownerB = Guid.NewGuid();
            var service = NotificationInboxService.ForDatabase(path);

            var a1 = service.Create(ownerA, "A1", "First A notification.", NotificationSeverity.Information);
            var a2 = service.Create(ownerA, "A2", "Second A notification.", NotificationSeverity.Warning);
            var b1 = service.Create(ownerB, "B1", "B notification.", NotificationSeverity.Error);

            service.MarkAllRead(ownerA).Should().Be(2);
            service.Get(ownerA, unreadOnly: true).Should().BeEmpty();
            service.Get(ownerB, unreadOnly: true).Should().ContainSingle(x => x.Id == b1.Id);

            service.MarkRead(ownerB, b1.Id).Should().BeTrue();
            Backdate(path, a1.Id, DateTime.UtcNow.AddDays(-120));
            Backdate(path, a2.Id, DateTime.UtcNow.AddDays(-120));
            Backdate(path, b1.Id, DateTime.UtcNow.AddDays(-120));

            service.Prune(ownerA, TimeSpan.FromDays(90)).Should().Be(2);
            service.Get(ownerA).Should().BeEmpty();
            service.Get(ownerB).Should().ContainSingle(x => x.Id == b1.Id);
        }
        finally
        {
            DeleteDatabase(path);
        }
    }

    private static string TempDatabasePath()
    {
        var directory = Path.Combine(Path.GetTempPath(), "AIWordPressManager.Tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(directory);
        return Path.Combine(directory, "notifications.db");
    }

    private static void CreateLegacyDatabase(string path)
    {
        using var connection = Open(path);
        using var command = connection.CreateCommand();
        command.CommandText = """
            CREATE TABLE Notifications(
                Id TEXT PRIMARY KEY,
                UserId TEXT NOT NULL,
                Title TEXT NOT NULL,
                Message TEXT NOT NULL,
                Severity TEXT NOT NULL,
                RelatedId TEXT NULL,
                IsRead INTEGER NOT NULL DEFAULT 0,
                CreatedAtUtc TEXT NOT NULL
            );
            CREATE INDEX IX_Notifications_User_Read ON Notifications(UserId, IsRead, CreatedAtUtc);
            INSERT INTO Notifications(Id, UserId, Title, Message, Severity, RelatedId, IsRead, CreatedAtUtc)
            VALUES($id, 'System', 'Legacy system notification', 'Unowned legacy row.', 'Information', NULL, 0, $created);
            """;
        command.Parameters.AddWithValue("$id", Guid.NewGuid().ToString());
        command.Parameters.AddWithValue("$created", DateTime.UtcNow.AddDays(-1).ToString("O"));
        command.ExecuteNonQuery();
    }

    private static void Backdate(string path, Guid id, DateTime createdAtUtc)
    {
        using var connection = Open(path);
        using var command = connection.CreateCommand();
        command.CommandText = "UPDATE Notifications SET CreatedAtUtc=$created WHERE Id=$id;";
        command.Parameters.AddWithValue("$created", createdAtUtc.ToUniversalTime().ToString("O"));
        command.Parameters.AddWithValue("$id", id.ToString());
        command.ExecuteNonQuery();
    }

    private static SqliteConnection Open(string path)
    {
        var connection = new SqliteConnection(new SqliteConnectionStringBuilder
        {
            DataSource = path,
            Mode = SqliteOpenMode.ReadWriteCreate
        }.ToString());
        connection.Open();
        return connection;
    }

    private static void DeleteDatabase(string path)
    {
        try
        {
            SqliteConnection.ClearAllPools();
            var directory = Path.GetDirectoryName(path);
            if (!string.IsNullOrWhiteSpace(directory) && Directory.Exists(directory))
                Directory.Delete(directory, recursive: true);
        }
        catch
        {
            // Best-effort cleanup for test artifacts.
        }
    }
}
