using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class BackupManifestSemanticTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-backup-semantic-tests-{Guid.NewGuid():N}");

    [Fact]
    public void Inspect_DoesNotCountConfigurationDbFileAsDatabaseContent()
    {
        var service = CreateService();
        var payload = new byte[] { 1, 4, 1, 4 };
        var fileName = "AIWM-Backup-config-db-only.zip";
        var manifest = new BackupManifest(
            4,
            DateTime.UtcNow,
            null,
            "test-machine",
            [new BackupManifestFile(
                "fake.db",
                payload.Length,
                Convert.ToHexString(SHA256.HashData(payload)),
                "Config/fake.db",
                nameof(BackupContentKind.Configuration))],
            "SQLite",
            "wrapped-key-required");

        var path = Path.Combine(service.BackupDirectory, fileName);
        using (var archive = ZipFile.Open(path, ZipArchiveMode.Create))
        {
            var payloadEntry = archive.CreateEntry("payload/Config/fake.db", CompressionLevel.NoCompression);
            using (var stream = payloadEntry.Open())
            {
                stream.Write(payload);
            }

            var manifestEntry = archive.CreateEntry("manifest.json", CompressionLevel.NoCompression);
            using var writer = new StreamWriter(manifestEntry.Open());
            writer.Write(JsonSerializer.Serialize(manifest));
        }

        var inspection = service.Inspect(fileName);

        inspection.DatabaseCount.Should().Be(0);
        inspection.IsValid.Should().BeFalse();
        inspection.Message.Should().Contain("does not contain database files");
    }

    private BackupManagementService CreateService()
    {
        Directory.CreateDirectory(_root);
        return new BackupManagementService(_root);
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
