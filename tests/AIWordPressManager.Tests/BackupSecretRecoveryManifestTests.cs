using System.IO.Compression;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class BackupSecretRecoveryManifestTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-backup-secret-tests-{Guid.NewGuid():N}");

    [Fact]
    public void CreateBackup_PersistsWrappedEnvelopeButPreflightRequiresCryptographicValidation()
    {
        var service = CreateService();
        File.WriteAllBytes(Path.Combine(service.DataDirectory, "application.db"), [1, 2, 3, 4]);
        var envelope = CreateStructurallyValidEnvelope(0x41);

        var backup = service.CreateBackup("recoverable", envelope);
        var inspection = service.Inspect(backup.FileName);
        var unvalidated = service.CheckRestoreReadiness(backup.FileName);
        var validated = service.CheckRestoreReadiness(backup.FileName, secretRecoveryValidated: true);

        inspection.IsValid.Should().BeTrue();
        inspection.ManifestVersion.Should().Be(5);
        inspection.SecretRecoveryMode.Should().Be(SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Mode);
        inspection.WrappedSecretKeyEnvelope.Should().Be(envelope);
        unvalidated.Checks.Should().Contain(x =>
            x.Name == "Protected secret recovery" &&
            !x.Passed &&
            x.Message.Contains("not been cryptographically validated", StringComparison.OrdinalIgnoreCase));
        validated.Checks.Should().Contain(x => x.Name == "Protected secret recovery" && x.Passed);
    }

    [Fact]
    public void CreateBackup_RejectsMalformedWrappedEnvelopeBeforeWritingArchive()
    {
        var service = CreateService();
        File.WriteAllBytes(Path.Combine(service.DataDirectory, "application.db"), [1, 2, 3, 4]);

        var action = () => service.CreateBackup(
            wrappedSecretKeyEnvelope: SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Prefix + "not-base64!");

        action.Should().Throw<ArgumentException>()
            .WithMessage("*wrapped secret-recovery envelope*malformed*");
        Directory.EnumerateFiles(service.BackupDirectory, "AIWM-Backup-*.zip").Should().BeEmpty();
    }

    [Fact]
    public void Inspect_RejectsWrappedModeWithoutEnvelope()
    {
        var service = CreateService();
        File.WriteAllBytes(Path.Combine(service.DataDirectory, "application.db"), [4, 3, 2, 1]);
        var backup = service.CreateBackup();
        RewriteManifest(service, backup.FileName, manifest => manifest with
        {
            SecretRecoveryMode = SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Mode,
            WrappedSecretKeyEnvelope = null
        });

        var inspection = service.Inspect(backup.FileName);

        inspection.IsValid.Should().BeFalse();
        inspection.Message.Should().Contain("wrapped secret-recovery envelope is missing or malformed");
    }

    [Fact]
    public void Inspect_RejectsBlockedModeThatCarriesEnvelope()
    {
        var service = CreateService();
        File.WriteAllBytes(Path.Combine(service.DataDirectory, "application.db"), [4, 3, 2, 1]);
        var backup = service.CreateBackup();
        RewriteManifest(service, backup.FileName, manifest => manifest with
        {
            SecretRecoveryMode = "wrapped-key-required",
            WrappedSecretKeyEnvelope = CreateStructurallyValidEnvelope(0x52)
        });

        var inspection = service.Inspect(backup.FileName);

        inspection.IsValid.Should().BeFalse();
        inspection.Message.Should().Contain("blocked secret-recovery manifest cannot contain a wrapped key envelope");
    }

    [Fact]
    public void Inspect_KeepsManifestV4CompatibilityWithoutRequiringEnvelopeField()
    {
        var service = CreateService();
        var fileName = "AIWM-Backup-v4-compat.zip";
        var payload = new byte[] { 7, 8, 9, 10 };
        var manifest = new BackupManifest(
            4,
            DateTime.UtcNow,
            null,
            "legacy-v4",
            [new BackupManifestFile(
                "application.db",
                payload.Length,
                Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(payload)),
                "Data/application.db",
                nameof(BackupContentKind.Database))],
            "SQLite",
            "wrapped-key-required");

        var path = Path.Combine(service.BackupDirectory, fileName);
        using (var archive = ZipFile.Open(path, ZipArchiveMode.Create))
        {
            var data = archive.CreateEntry("payload/Data/application.db", CompressionLevel.NoCompression);
            using (var stream = data.Open()) stream.Write(payload);
            var manifestEntry = archive.CreateEntry("manifest.json", CompressionLevel.NoCompression);
            using var writer = new StreamWriter(manifestEntry.Open());
            writer.Write(JsonSerializer.Serialize(manifest));
        }

        var inspection = service.Inspect(fileName);

        inspection.IsValid.Should().BeTrue();
        inspection.ManifestVersion.Should().Be(4);
        inspection.WrappedSecretKeyEnvelope.Should().BeNull();
    }

    private BackupManagementService CreateService()
    {
        Directory.CreateDirectory(_root);
        return new BackupManagementService(_root);
    }

    private static string CreateStructurallyValidEnvelope(byte seed)
    {
        var payload = Enumerable.Range(0, SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1PayloadBytes)
            .Select(i => (byte)(seed + (i % 17)))
            .ToArray();
        return SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Prefix + Convert.ToBase64String(payload);
    }

    private static void RewriteManifest(
        BackupManagementService service,
        string fileName,
        Func<BackupManifest, BackupManifest> mutate)
    {
        var path = Path.Combine(service.BackupDirectory, fileName);
        using var archive = ZipFile.Open(path, ZipArchiveMode.Update);
        var entry = archive.GetEntry("manifest.json") ?? throw new InvalidOperationException("Missing manifest.");
        BackupManifest manifest;
        using (var reader = new StreamReader(entry.Open()))
        {
            manifest = JsonSerializer.Deserialize<BackupManifest>(reader.ReadToEnd())
                       ?? throw new InvalidOperationException("Invalid manifest.");
        }

        entry.Delete();
        var replacement = archive.CreateEntry("manifest.json", CompressionLevel.NoCompression);
        using var writer = new StreamWriter(replacement.Open());
        writer.Write(JsonSerializer.Serialize(mutate(manifest)));
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
