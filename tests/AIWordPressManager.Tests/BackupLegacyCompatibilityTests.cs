using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class BackupLegacyCompatibilityTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-backup-legacy-tests-{Guid.NewGuid():N}");

    [Fact]
    public void Inspect_ValidV3BackupRemainsCryptographicallyVerified()
    {
        var service = CreateService();
        var payload = new byte[] { 11, 22, 33, 44, 55, 66 };
        var fileName = "AIWM-Backup-valid-v3.zip";
        var manifest = new BackupManifest(
            3,
            DateTime.UtcNow,
            "v3 compatibility",
            "legacy-machine",
            [new BackupManifestFile(
                "application.db",
                payload.Length,
                Convert.ToHexString(SHA256.HashData(payload)),
                "application.db",
                nameof(BackupContentKind.Database))]);

        CreateArchive(service, fileName, "data/application.db", payload, manifest);

        var inspection = service.Inspect(fileName);

        inspection.IsValid.Should().BeTrue();
        inspection.ManifestVersion.Should().Be(3);
        inspection.DatabaseCount.Should().Be(1);
        inspection.Message.Should().Contain("SHA-256 verification");
    }

    [Fact]
    public void RestoreReadiness_LegacyV2NeverClaimsConfigurationOrSecretRecovery()
    {
        var service = CreateService();
        var payload = new byte[] { 7, 6, 5, 4 };
        var fileName = "AIWM-Backup-readiness-v2.zip";
        var manifest = new BackupManifest(
            2,
            DateTime.UtcNow,
            null,
            "legacy-machine",
            [new BackupManifestFile("legacy.db", payload.Length)]);

        CreateArchive(service, fileName, "data/legacy.db", payload, manifest);

        var readiness = service.CheckRestoreReadiness(fileName);

        readiness.IsReady.Should().BeFalse();
        readiness.Checks.Should().Contain(x => x.Name == "Configuration state" && !x.Passed);
        readiness.Checks.Should().Contain(x => x.Name == "Protected secret recovery" && !x.Passed);
    }

    [Fact]
    public void CreateBackup_InvalidDatabaseConfigurationFailsClosedBeforeArchiveCreation()
    {
        var service = CreateService();
        File.WriteAllBytes(Path.Combine(service.DataDirectory, "application.db"), [1, 2, 3, 4]);
        File.WriteAllText(Path.Combine(service.ConfigurationDirectory, "setup.database.json"), "{not-json");

        var action = () => service.CreateBackup();

        action.Should().Throw<InvalidDataException>()
            .WithMessage("*invalid JSON*blocked*");
        Directory.EnumerateFiles(service.BackupDirectory, "AIWM-Backup-*.zip").Should().BeEmpty();
    }

    [Fact]
    public void CreateBackup_MissingConfiguredProviderFailsClosedBeforeArchiveCreation()
    {
        var service = CreateService();
        File.WriteAllBytes(Path.Combine(service.DataDirectory, "application.db"), [1, 2, 3, 4]);
        File.WriteAllText(
            Path.Combine(service.ConfigurationDirectory, "setup.database.json"),
            "{\"Database\":{\"SetupComplete\":true}}");

        var action = () => service.CreateBackup();

        action.Should().Throw<InvalidDataException>()
            .WithMessage("*valid provider*blocked*");
        Directory.EnumerateFiles(service.BackupDirectory, "AIWM-Backup-*.zip").Should().BeEmpty();
    }

    private BackupManagementService CreateService()
    {
        Directory.CreateDirectory(_root);
        return new BackupManagementService(_root);
    }

    private static void CreateArchive(
        BackupManagementService service,
        string fileName,
        string payloadPath,
        byte[] payload,
        BackupManifest manifest)
    {
        var path = Path.Combine(service.BackupDirectory, fileName);
        using var archive = ZipFile.Open(path, ZipArchiveMode.Create);
        var payloadEntry = archive.CreateEntry(payloadPath, CompressionLevel.NoCompression);
        using (var stream = payloadEntry.Open())
        {
            stream.Write(payload);
        }

        var manifestEntry = archive.CreateEntry("manifest.json", CompressionLevel.NoCompression);
        using var writer = new StreamWriter(manifestEntry.Open());
        writer.Write(JsonSerializer.Serialize(manifest));
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
