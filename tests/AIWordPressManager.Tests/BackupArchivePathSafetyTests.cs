using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class BackupArchivePathSafetyTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-backup-path-tests-{Guid.NewGuid():N}");

    [Fact]
    public void Inspect_RejectsUnexpectedRootPayload()
    {
        var service = CreateService();
        File.WriteAllBytes(Path.Combine(service.DataDirectory, "application.db"), [1, 2, 3, 4]);
        var backup = service.CreateBackup();
        var path = Path.Combine(service.BackupDirectory, backup.FileName);

        using (var archive = ZipFile.Open(path, ZipArchiveMode.Update))
        {
            var entry = archive.CreateEntry("rogue.txt", CompressionLevel.NoCompression);
            using var writer = new StreamWriter(entry.Open());
            writer.Write("unexpected");
        }

        var inspection = service.Inspect(backup.FileName);

        inspection.IsValid.Should().BeFalse();
        inspection.Message.Should().Contain("Unexpected archive entry: rogue.txt");
    }

    [Fact]
    public void Inspect_RejectsAbsoluteLikeManifestPath()
    {
        var service = CreateService();
        var payload = new byte[] { 4, 3, 2, 1 };
        var fileName = "AIWM-Backup-unsafe-path.zip";
        CreateV3Archive(
            service,
            fileName,
            payload,
            new BackupManifestFile(
                "application.db",
                payload.Length,
                ComputeSha256(payload),
                "/application.db",
                nameof(BackupContentKind.Database)));

        var inspection = service.Inspect(fileName);

        inspection.IsValid.Should().BeFalse();
        inspection.Message.Should().Contain("Unsafe or missing manifest path");
    }

    [Fact]
    public void Inspect_RejectsManifestContentKindSpoofing()
    {
        var service = CreateService();
        var payload = new byte[] { 9, 8, 7, 6 };
        var fileName = "AIWM-Backup-kind-spoof.zip";
        CreateV3Archive(
            service,
            fileName,
            payload,
            new BackupManifestFile(
                "application.db",
                payload.Length,
                ComputeSha256(payload),
                "application.db",
                nameof(BackupContentKind.ManagedState)));

        var inspection = service.Inspect(fileName);

        inspection.IsValid.Should().BeFalse();
        inspection.Message.Should().Contain("Content kind mismatch: application.db");
    }

    private BackupManagementService CreateService()
    {
        Directory.CreateDirectory(_root);
        return new BackupManagementService(_root);
    }

    private static void CreateV3Archive(
        BackupManagementService service,
        string fileName,
        byte[] payload,
        BackupManifestFile declared)
    {
        var manifest = new BackupManifest(
            3,
            DateTime.UtcNow,
            null,
            "test-machine",
            [declared]);
        var path = Path.Combine(service.BackupDirectory, fileName);

        using var archive = ZipFile.Open(path, ZipArchiveMode.Create);
        var data = archive.CreateEntry("data/application.db", CompressionLevel.NoCompression);
        using (var stream = data.Open())
        {
            stream.Write(payload);
        }

        var manifestEntry = archive.CreateEntry("manifest.json", CompressionLevel.NoCompression);
        using var writer = new StreamWriter(manifestEntry.Open());
        writer.Write(JsonSerializer.Serialize(manifest));
    }

    private static string ComputeSha256(byte[] payload) => Convert.ToHexString(SHA256.HashData(payload));

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
