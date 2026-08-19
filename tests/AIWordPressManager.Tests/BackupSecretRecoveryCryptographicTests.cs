using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using AIWordPressManager.Infrastructure.Security;
using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class BackupSecretRecoveryCryptographicTests : IDisposable
{
    private const string RecoverySecret = "archive-recovery-secret-2026";
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-backup-secret-crypto-{Guid.NewGuid():N}");

    [Fact]
    public void WrappedEnvelope_MustValidateCryptographicallyBeforeSecretRecoveryPassesPreflight()
    {
        var service = CreateService();
        File.WriteAllBytes(Path.Combine(service.DataDirectory, "application.db"), [1, 3, 3, 7]);
        var masterKey = RandomNumberGenerator.GetBytes(32);

        try
        {
            var envelope = SecretRecoveryEnvelopeCodec.Wrap(masterKey, RecoverySecret);
            var backup = service.CreateBackup(wrappedSecretKeyEnvelope: envelope);
            var inspection = service.Inspect(backup.FileName);

            SecretRecoveryEnvelopeCodec.Verify(
                inspection.WrappedSecretKeyEnvelope!,
                masterKey,
                RecoverySecret).Should().BeTrue();

            var validated = service.CheckRestoreReadiness(backup.FileName, secretRecoveryValidated: true);
            validated.Checks.Should().Contain(x => x.Name == "Protected secret recovery" && x.Passed);

            var tamperedEnvelope = TamperEnvelope(envelope);
            RewriteWrappedEnvelope(service, backup.FileName, tamperedEnvelope);
            var tamperedInspection = service.Inspect(backup.FileName);

            tamperedInspection.IsValid.Should().BeTrue("the archive layer intentionally performs structural envelope validation only");
            SecretRecoveryEnvelopeCodec.Verify(
                tamperedInspection.WrappedSecretKeyEnvelope!,
                masterKey,
                RecoverySecret).Should().BeFalse();

            var unvalidated = service.CheckRestoreReadiness(backup.FileName, secretRecoveryValidated: false);
            unvalidated.Checks.Should().Contain(x => x.Name == "Protected secret recovery" && !x.Passed);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(masterKey);
        }
    }

    private BackupManagementService CreateService()
    {
        Directory.CreateDirectory(_root);
        return new BackupManagementService(_root);
    }

    private static string TamperEnvelope(string envelope)
    {
        var payload = Convert.FromBase64String(envelope[SecretRecoveryEnvelopeCodec.Prefix.Length..]);
        try
        {
            payload[^1] ^= 0x6D;
            return SecretRecoveryEnvelopeCodec.Prefix + Convert.ToBase64String(payload);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(payload);
        }
    }

    private static void RewriteWrappedEnvelope(
        BackupManagementService service,
        string fileName,
        string wrappedEnvelope)
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
        writer.Write(JsonSerializer.Serialize(manifest with { WrappedSecretKeyEnvelope = wrappedEnvelope }));
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
