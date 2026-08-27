using System.Security.Cryptography;
using System.Text.Json;
using AIWordPressManager.Infrastructure.Security;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;

namespace AIWordPressManager.Tests;

public sealed class OfflineApplicationRestoreServiceTests : IDisposable
{
    private const string RecoverySecret = "full-offline-restore-secret-2026";
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-full-restore-{Guid.NewGuid():N}");

    [Fact]
    public void RestoreFromBackup_ReplacesManagedStateAndInstallsMatchingSecretKey()
    {
        var fixture = CreateRecoverableBackup();
        try
        {
            var destinationRoot = Path.Combine(_root, "destination-app");
            var destinationLocal = Path.Combine(_root, "destination-local");
            Directory.CreateDirectory(Path.Combine(destinationRoot, "Data"));
            Directory.CreateDirectory(Path.Combine(destinationRoot, "Config"));
            File.WriteAllText(Path.Combine(destinationRoot, "Data", "stale.txt"), "stale");
            File.WriteAllText(Path.Combine(destinationRoot, "Config", "stale.json"), "{}");

            var result = new OfflineApplicationRestoreService().RestoreFromBackup(
                fixture.BackupPath,
                RecoverySecret,
                applicationRoot: destinationRoot,
                localApplicationData: destinationLocal);

            result.SecretKeyStatus.Should().Be(SecretRecoveryInstallStatus.Installed);
            result.ManagedFileCount.Should().BeGreaterThanOrEqualTo(3);
            File.Exists(Path.Combine(destinationRoot, "Data", "stale.txt")).Should().BeFalse();
            File.ReadAllText(Path.Combine(destinationRoot, "Data", "restored-state.json"))
                .Should().Contain("from-backup");
            File.Exists(Path.Combine(destinationRoot, "AIMW-LAST-OFFLINE-RESTORE.txt")).Should().BeTrue();

            var restoredDatabase = Path.Combine(destinationRoot, "Data", "application.db");
            ReadFixtureValue(restoredDatabase).Should().Be("verified");

            using (var document = JsonDocument.Parse(File.ReadAllText(Path.Combine(destinationRoot, "Config", "setup.database.json"))))
            {
                var database = document.RootElement.GetProperty("Database");
                database.GetProperty("SetupComplete").GetBoolean().Should().BeTrue();
                database.GetProperty("Provider").GetString().Should().Be("SQLite");
                var builder = new SqliteConnectionStringBuilder(database.GetProperty("ConnectionString").GetString());
                Path.GetFullPath(builder.DataSource).Should().Be(Path.GetFullPath(restoredDatabase));
            }

            var installedKey = SecretProtectionStorage.ReadAndValidateKey(SecretProtectionStorage.GetKeyPath(destinationLocal));
            try
            {
                CryptographicOperations.FixedTimeEquals(installedKey, fixture.MasterKey).Should().BeTrue();
            }
            finally
            {
                CryptographicOperations.ZeroMemory(installedKey);
            }
        }
        finally
        {
            CryptographicOperations.ZeroMemory(fixture.MasterKey);
        }
    }

    [Fact]
    public void RestoreFromBackup_WrongSecretFailsBeforeAnyManagedStateMutation()
    {
        var fixture = CreateRecoverableBackup();
        try
        {
            var destinationRoot = Path.Combine(_root, "wrong-secret-app");
            var destinationLocal = Path.Combine(_root, "wrong-secret-local");
            Directory.CreateDirectory(Path.Combine(destinationRoot, "Data"));
            Directory.CreateDirectory(Path.Combine(destinationRoot, "Config"));
            var oldData = Path.Combine(destinationRoot, "Data", "original.txt");
            var oldConfig = Path.Combine(destinationRoot, "Config", "original.json");
            File.WriteAllText(oldData, "original-data");
            File.WriteAllText(oldConfig, "{\"original\":true}");

            var action = () => new OfflineApplicationRestoreService().RestoreFromBackup(
                fixture.BackupPath,
                "wrong-full-offline-secret-2026",
                applicationRoot: destinationRoot,
                localApplicationData: destinationLocal);

            action.Should().Throw<CryptographicException>()
                .WithMessage("*incorrect*altered*");
            File.ReadAllText(oldData).Should().Be("original-data");
            File.ReadAllText(oldConfig).Should().Contain("original");
            File.Exists(Path.Combine(destinationRoot, "Data", "restored-state.json")).Should().BeFalse();
            File.Exists(SecretProtectionStorage.GetKeyPath(destinationLocal)).Should().BeFalse();
            File.Exists(Path.Combine(destinationRoot, "AIMW-LAST-OFFLINE-RESTORE.txt")).Should().BeFalse();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(fixture.MasterKey);
        }
    }

    [Fact]
    public void RestoreFromBackup_KeyConflictAfterDataSwapRollsBackDataConfigurationAndKey()
    {
        var fixture = CreateRecoverableBackup();
        var existingKey = RandomNumberGenerator.GetBytes(SecretProtectionStorage.MasterKeySize);
        try
        {
            var destinationRoot = Path.Combine(_root, "rollback-app");
            var destinationLocal = Path.Combine(_root, "rollback-local");
            Directory.CreateDirectory(Path.Combine(destinationRoot, "Data"));
            Directory.CreateDirectory(Path.Combine(destinationRoot, "Config"));
            var oldData = Path.Combine(destinationRoot, "Data", "original.txt");
            var oldConfig = Path.Combine(destinationRoot, "Config", "original.json");
            File.WriteAllText(oldData, "original-data");
            File.WriteAllText(oldConfig, "{\"original\":true}");
            var keyPath = SecretProtectionStorage.GetKeyPath(destinationLocal);
            SecretProtectionStorage.WriteKeyAtomically(keyPath, existingKey, replaceExisting: false);

            var action = () => new OfflineApplicationRestoreService().RestoreFromBackup(
                fixture.BackupPath,
                RecoverySecret,
                replaceExistingKey: false,
                applicationRoot: destinationRoot,
                localApplicationData: destinationLocal);

            action.Should().Throw<InvalidOperationException>()
                .WithMessage("*different secret-protection key*explicit replacement*");
            File.ReadAllText(oldData).Should().Be("original-data");
            File.ReadAllText(oldConfig).Should().Contain("original");
            File.Exists(Path.Combine(destinationRoot, "Data", "restored-state.json")).Should().BeFalse();
            File.Exists(Path.Combine(destinationRoot, "Config", "setup.database.json")).Should().BeFalse();
            File.Exists(Path.Combine(destinationRoot, "AIMW-LAST-OFFLINE-RESTORE.txt")).Should().BeFalse();

            var persisted = SecretProtectionStorage.ReadAndValidateKey(keyPath);
            try
            {
                CryptographicOperations.FixedTimeEquals(persisted, existingKey).Should().BeTrue();
            }
            finally
            {
                CryptographicOperations.ZeroMemory(persisted);
            }
        }
        finally
        {
            CryptographicOperations.ZeroMemory(fixture.MasterKey);
            CryptographicOperations.ZeroMemory(existingKey);
        }
    }

    [Fact]
    public void RestoreFromBackup_LiveWebLeaseBlocksRestoreWithoutChangingTarget()
    {
        var fixture = CreateRecoverableBackup();
        try
        {
            var destinationRoot = Path.Combine(_root, "locked-app");
            var destinationLocal = Path.Combine(_root, "locked-local");
            Directory.CreateDirectory(Path.Combine(destinationRoot, "Data"));
            var marker = Path.Combine(destinationRoot, "Data", "original.txt");
            File.WriteAllText(marker, "still-live");
            using var webLease = SecretProtectionStorage.AcquireWebRuntimeLease(destinationLocal);

            var action = () => new OfflineApplicationRestoreService().RestoreFromBackup(
                fixture.BackupPath,
                RecoverySecret,
                applicationRoot: destinationRoot,
                localApplicationData: destinationLocal);

            action.Should().Throw<InvalidOperationException>()
                .WithMessage("*Stop every web application worker*");
            File.ReadAllText(marker).Should().Be("still-live");
            File.Exists(Path.Combine(destinationRoot, "Data", "restored-state.json")).Should().BeFalse();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(fixture.MasterKey);
        }
    }

    private RestoreFixture CreateRecoverableBackup()
    {
        var sourceRoot = Path.Combine(_root, $"source-{Guid.NewGuid():N}");
        var service = new BackupManagementService(sourceRoot);
        var databasePath = Path.Combine(service.DataDirectory, "application.db");
        SqliteTestDatabase.Create(databasePath);
        File.WriteAllText(Path.Combine(service.DataDirectory, "restored-state.json"), "{\"source\":\"from-backup\"}");
        WriteSqliteConfiguration(service, databasePath);

        var masterKey = RandomNumberGenerator.GetBytes(SecretProtectionStorage.MasterKeySize);
        var envelope = SecretRecoveryEnvelopeCodec.Wrap(masterKey, RecoverySecret);
        var backup = service.CreateBackup("full-restore-fixture", envelope);
        return new RestoreFixture(Path.Combine(service.BackupDirectory, backup.FileName), masterKey);
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

    private static string? ReadFixtureValue(string databasePath)
    {
        using var connection = new SqliteConnection($"Data Source={databasePath}");
        connection.Open();
        using var command = connection.CreateCommand();
        command.CommandText = "SELECT value FROM restore_fixture ORDER BY id LIMIT 1;";
        return Convert.ToString(command.ExecuteScalar());
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

    private sealed record RestoreFixture(string BackupPath, byte[] MasterKey);
}
