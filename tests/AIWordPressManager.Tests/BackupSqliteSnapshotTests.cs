using System.IO.Compression;
using System.Text.Json;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Tests;

public sealed class BackupSqliteSnapshotTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-sqlite-snapshot-{Guid.NewGuid():N}");

    [Fact]
    public void CreateBackup_CapturesCommittedWalStateIntoStandaloneVerifiedDatabase()
    {
        var service = new BackupManagementService(_root);
        var databasePath = Path.Combine(service.DataDirectory, "application.db");
        var builder = new SqliteConnectionStringBuilder
        {
            DataSource = databasePath,
            Mode = SqliteOpenMode.ReadWriteCreate
        };

        using var liveConnection = new SqliteConnection(builder.ToString());
        liveConnection.Open();
        using (var command = liveConnection.CreateCommand())
        {
            command.CommandText = "PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0; CREATE TABLE snapshot_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL); INSERT INTO snapshot_probe(value) VALUES ('committed-in-wal');";
            command.ExecuteNonQuery();
        }

        WriteSqliteConfiguration(service, databasePath);
        var backup = service.CreateBackup("wal-safe");
        var inspection = service.Inspect(backup.FileName);

        inspection.IsValid.Should().BeTrue();
        inspection.DatabaseCount.Should().Be(1);

        var archivePath = Path.Combine(service.BackupDirectory, backup.FileName);
        var extracted = Path.Combine(_root, "extracted-snapshot.db");
        using (var archive = ZipFile.OpenRead(archivePath))
        {
            archive.Entries.Should().NotContain(x =>
                x.FullName.EndsWith(".db-wal", StringComparison.OrdinalIgnoreCase) ||
                x.FullName.EndsWith(".db-shm", StringComparison.OrdinalIgnoreCase));

            var entry = archive.GetEntry("payload/Data/application.db")
                        ?? throw new InvalidOperationException("Standalone database snapshot was not found in backup.");
            entry.ExtractToFile(extracted, overwrite: false);
        }

        using var restored = new SqliteConnection($"Data Source={extracted};Mode=ReadOnly");
        restored.Open();
        using var verify = restored.CreateCommand();
        verify.CommandText = "SELECT value FROM snapshot_probe ORDER BY id LIMIT 1;";
        Convert.ToString(verify.ExecuteScalar()).Should().Be("committed-in-wal");

        using var quickCheck = restored.CreateCommand();
        quickCheck.CommandText = "PRAGMA quick_check;";
        Convert.ToString(quickCheck.ExecuteScalar()).Should().Be("ok");
    }

    private static void WriteSqliteConfiguration(BackupManagementService service, string databasePath)
    {
        var payload = new
        {
            Database = new
            {
                Provider = "SQLite",
                SetupComplete = true,
                ConnectionString = $"Data Source={databasePath};Foreign Keys=True;Pooling=True"
            }
        };
        File.WriteAllText(
            Path.Combine(service.ConfigurationDirectory, "setup.database.json"),
            JsonSerializer.Serialize(payload));
    }

    public void Dispose()
    {
        try
        {
            if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
        }
        catch
        {
            // Best-effort cleanup only.
        }
    }
}
