using System.IO.Compression;
using System.Text.Json;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class BackupManagementServiceTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-backup-tests-{Guid.NewGuid():N}");

    [Fact]
    public void CreateBackup_IncludesManagedDataAndCryptographicallyVerifiesManifestV5()
    {
        var service = CreateService();
        var databasePath = Path.Combine(service.DataDirectory, "application.db");
        SqliteTestDatabase.Create(databasePath);
        var settingsDirectory = Path.Combine(service.DataDirectory, "settings");
        Directory.CreateDirectory(settingsDirectory);
        File.WriteAllText(Path.Combine(settingsDirectory, "application-state.json"), "{\"mode\":\"safe\"}");
        File.WriteAllText(Path.Combine(settingsDirectory, "ignored.tmp"), "transient");
        WriteSqliteConfiguration(service, databasePath);

        var backup = service.CreateBackup("before upgrade");
        var inspection = service.Inspect(backup.FileName);

        backup.IsValid.Should().BeTrue();
        inspection.IsValid.Should().BeTrue();
        inspection.ManifestVersion.Should().Be(5);
        inspection.DatabaseCount.Should().Be(1);
        inspection.DatabaseProvider.Should().Be("SQLite");
        inspection.SecretRecoveryMode.Should().Be("wrapped-key-required");
        inspection.WrappedSecretKeyEnvelope.Should().BeNull();
        inspection.Files.Should().Contain(x =>
            x.RelativePath == "Data/application.db" &&
            x.Kind == nameof(BackupContentKind.Database) &&
            !string.IsNullOrWhiteSpace(x.Sha256));
        inspection.Files.Should().Contain(x =>
            x.RelativePath == "Data/settings/application-state.json" &&
            x.Kind == nameof(BackupContentKind.ManagedState) &&
            !string.IsNullOrWhiteSpace(x.Sha256));
        inspection.Files.Should().Contain(x =>
            x.RelativePath == "Config/setup.database.json" &&
            x.Kind == nameof(BackupContentKind.Configuration) &&
            !string.IsNullOrWhiteSpace(x.Sha256));
        inspection.Files.Should().NotContain(x => x.RelativePath == "Data/settings/ignored.tmp");
        inspection.Message.Should().Contain("SHA-256");
    }

    [Fact]
    public void Inspect_RejectsSameSizePayloadTampering()
    {
        var service = CreateService();
        SqliteTestDatabase.Create(Path.Combine(service.DataDirectory, "application.db"));
        var backup = service.CreateBackup();
        var backupPath = Path.Combine(service.BackupDirectory, backup.FileName);

        ReplaceEntryWithSameLengthMutation(backupPath, "payload/Data/application.db");

        var inspection = service.Inspect(backup.FileName);

        inspection.IsValid.Should().BeFalse();
        inspection.Message.Should().Contain("SHA-256 mismatch");
    }

    [Fact]
    public void Inspect_RejectsUndeclaredManagedPayload()
    {
        var service = CreateService();
        SqliteTestDatabase.Create(Path.Combine(service.DataDirectory, "application.db"));
        var backup = service.CreateBackup();
        var backupPath = Path.Combine(service.BackupDirectory, backup.FileName);

        using (var archive = ZipFile.Open(backupPath, ZipArchiveMode.Update))
        {
            var rogue = archive.CreateEntry("payload/Data/rogue.json", CompressionLevel.NoCompression);
            using var writer = new StreamWriter(rogue.Open());
            writer.Write("{\"unexpected\":true}");
        }

        var inspection = service.Inspect(backup.FileName);

        inspection.IsValid.Should().BeFalse();
        inspection.Message.Should().Contain("Undeclared archive entry: Data/rogue.json");
    }

    [Fact]
    public void Inspect_KeepsLegacyV2BackupsInspectableWithoutRequiringMissingHashes()
    {
        var service = CreateService();
        var fileName = "AIWM-Backup-legacy-v2.zip";
        var backupPath = Path.Combine(service.BackupDirectory, fileName);
        var databaseBytes = CreatePayload(512, 0x43);
        var manifest = new BackupManifest(
            2,
            DateTime.UtcNow,
            "legacy",
            "test-machine",
            [new BackupManifestFile("legacy.db", databaseBytes.Length)]);

        using (var archive = ZipFile.Open(backupPath, ZipArchiveMode.Create))
        {
            var database = archive.CreateEntry("data/legacy.db", CompressionLevel.NoCompression);
            using (var stream = database.Open())
            {
                stream.Write(databaseBytes);
            }

            var manifestEntry = archive.CreateEntry("manifest.json", CompressionLevel.NoCompression);
            using var writer = new StreamWriter(manifestEntry.Open());
            writer.Write(JsonSerializer.Serialize(manifest));
        }

        var inspection = service.Inspect(fileName);

        inspection.IsValid.Should().BeTrue();
        inspection.ManifestVersion.Should().Be(2);
        inspection.DatabaseCount.Should().Be(1);
        inspection.Message.Should().Contain("legacy size/path compatibility verification");
    }

    [Fact]
    public void CreateBackup_RequiresApplicationDatabaseEvenWhenOtherManagedFilesExist()
    {
        var service = CreateService();
        File.WriteAllText(Path.Combine(service.DataDirectory, "application-state.json"), "{}");

        var action = () => service.CreateBackup();

        action.Should().Throw<InvalidOperationException>()
            .WithMessage("*database*");
    }

    private BackupManagementService CreateService()
    {
        Directory.CreateDirectory(_root);
        return new BackupManagementService(_root);
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

    private static byte[] CreatePayload(int length, byte seed)
    {
        var bytes = new byte[length];
        for (var i = 0; i < bytes.Length; i++) bytes[i] = (byte)(seed + (i % 29));
        return bytes;
    }

    private static void ReplaceEntryWithSameLengthMutation(string archivePath, string entryName)
    {
        using var archive = ZipFile.Open(archivePath, ZipArchiveMode.Update);
        var entry = archive.GetEntry(entryName) ?? throw new InvalidOperationException($"Missing test entry {entryName}.");
        byte[] bytes;
        using (var source = entry.Open())
        using (var buffer = new MemoryStream())
        {
            source.CopyTo(buffer);
            bytes = buffer.ToArray();
        }

        bytes[bytes.Length / 2] ^= 0x5A;
        entry.Delete();
        var replacement = archive.CreateEntry(entryName, CompressionLevel.NoCompression);
        using var target = replacement.Open();
        target.Write(bytes);
    }

    public void Dispose()
    {
        try
        {
            if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
        }
        catch
        {
            // Best-effort cleanup only. Test assertions must not be hidden by temp-directory cleanup.
        }
    }
}
