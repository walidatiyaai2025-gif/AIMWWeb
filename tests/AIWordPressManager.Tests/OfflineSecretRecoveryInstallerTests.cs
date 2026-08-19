using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Infrastructure.Security;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class OfflineSecretRecoveryInstallerTests : IDisposable
{
    private const string RecoverySecret = "offline-recovery-secret-2026";
    private readonly string _root = Path.Combine(Path.GetTempPath(), $"aiwm-offline-recovery-{Guid.NewGuid():N}");

    [Fact]
    public void InstallFromBackup_InstallsAuthenticatedKeyAndSecondRunIsIdempotent()
    {
        var localApplicationData = Path.Combine(_root, "local");
        var masterKey = RandomNumberGenerator.GetBytes(SecretProtectionStorage.MasterKeySize);
        try
        {
            var backup = CreateRecoveryBackup(masterKey);
            var installer = new OfflineSecretRecoveryInstaller();

            var installed = installer.InstallFromBackup(backup, RecoverySecret, localApplicationData: localApplicationData);
            var repeated = installer.InstallFromBackup(backup, RecoverySecret, localApplicationData: localApplicationData);

            installed.Status.Should().Be(SecretRecoveryInstallStatus.Installed);
            repeated.Status.Should().Be(SecretRecoveryInstallStatus.AlreadyInstalled);
            var persisted = SecretProtectionStorage.ReadAndValidateKey(installed.KeyPath);
            try
            {
                CryptographicOperations.FixedTimeEquals(persisted, masterKey).Should().BeTrue();
            }
            finally
            {
                CryptographicOperations.ZeroMemory(persisted);
            }
        }
        finally
        {
            CryptographicOperations.ZeroMemory(masterKey);
        }
    }

    [Fact]
    public void InstallFromBackup_RuntimeLockBlocksKeyMutationBeforeEnvelopeDecryption()
    {
        var localApplicationData = Path.Combine(_root, "locked-local");
        var masterKey = RandomNumberGenerator.GetBytes(SecretProtectionStorage.MasterKeySize);
        try
        {
            var backup = CreateRecoveryBackup(masterKey);
            using var runtimeLease = SecretProtectionStorage.AcquireRuntimeLock(localApplicationData);
            var installer = new OfflineSecretRecoveryInstaller();

            var action = () => installer.InstallFromBackup(
                backup,
                RecoverySecret,
                replaceExisting: true,
                localApplicationData: localApplicationData);

            action.Should().Throw<InvalidOperationException>()
                .WithMessage("*runtime lock*Stop the web application*");
            File.Exists(SecretProtectionStorage.GetKeyPath(localApplicationData)).Should().BeFalse();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(masterKey);
        }
    }

    [Fact]
    public void InstallFromBackup_DifferentExistingKeyRequiresExplicitReplacement()
    {
        var localApplicationData = Path.Combine(_root, "replace-local");
        var recoveredKey = RandomNumberGenerator.GetBytes(SecretProtectionStorage.MasterKeySize);
        var existingKey = RandomNumberGenerator.GetBytes(SecretProtectionStorage.MasterKeySize);
        try
        {
            var backup = CreateRecoveryBackup(recoveredKey);
            var keyPath = SecretProtectionStorage.GetKeyPath(localApplicationData);
            SecretProtectionStorage.WriteKeyAtomically(keyPath, existingKey, replaceExisting: false);
            var installer = new OfflineSecretRecoveryInstaller();

            var denied = () => installer.InstallFromBackup(
                backup,
                RecoverySecret,
                replaceExisting: false,
                localApplicationData: localApplicationData);
            denied.Should().Throw<InvalidOperationException>()
                .WithMessage("*different secret-protection key*explicit replacement*");

            var unchanged = SecretProtectionStorage.ReadAndValidateKey(keyPath);
            try
            {
                CryptographicOperations.FixedTimeEquals(unchanged, existingKey).Should().BeTrue();
            }
            finally
            {
                CryptographicOperations.ZeroMemory(unchanged);
            }

            var replaced = installer.InstallFromBackup(
                backup,
                RecoverySecret,
                replaceExisting: true,
                localApplicationData: localApplicationData);
            replaced.Status.Should().Be(SecretRecoveryInstallStatus.Replaced);

            var persisted = SecretProtectionStorage.ReadAndValidateKey(keyPath);
            try
            {
                CryptographicOperations.FixedTimeEquals(persisted, recoveredKey).Should().BeTrue();
            }
            finally
            {
                CryptographicOperations.ZeroMemory(persisted);
            }
        }
        finally
        {
            CryptographicOperations.ZeroMemory(recoveredKey);
            CryptographicOperations.ZeroMemory(existingKey);
        }
    }

    [Fact]
    public void InstallFromBackup_WrongSecretFailsClosedWithoutCreatingKey()
    {
        var localApplicationData = Path.Combine(_root, "wrong-secret-local");
        var masterKey = RandomNumberGenerator.GetBytes(SecretProtectionStorage.MasterKeySize);
        try
        {
            var backup = CreateRecoveryBackup(masterKey);
            var installer = new OfflineSecretRecoveryInstaller();

            var action = () => installer.InstallFromBackup(
                backup,
                "wrong-offline-recovery-secret-2026",
                localApplicationData: localApplicationData);

            action.Should().Throw<CryptographicException>()
                .WithMessage("*incorrect*altered*");
            File.Exists(SecretProtectionStorage.GetKeyPath(localApplicationData)).Should().BeFalse();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(masterKey);
        }
    }

    [Fact]
    public void InstallFromBackup_RejectsDuplicateOrUnsupportedManifest()
    {
        var localApplicationData = Path.Combine(_root, "manifest-local");
        var duplicate = Path.Combine(_root, "duplicate-manifest.zip");
        Directory.CreateDirectory(_root);
        using (var archive = ZipFile.Open(duplicate, ZipArchiveMode.Create))
        {
            WriteManifestEntry(archive, "manifest.json", 5, SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Mode, "x");
            WriteManifestEntry(archive, "MANIFEST.JSON", 5, SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Mode, "x");
        }

        var installer = new OfflineSecretRecoveryInstaller();
        var duplicateAction = () => installer.InstallFromBackup(
            duplicate,
            RecoverySecret,
            localApplicationData: localApplicationData);
        duplicateAction.Should().Throw<InvalidDataException>()
            .WithMessage("*duplicate manifests*");
    }

    private string CreateRecoveryBackup(byte[] masterKey)
    {
        Directory.CreateDirectory(_root);
        var path = Path.Combine(_root, $"AIWM-Backup-{Guid.NewGuid():N}.zip");
        var envelope = SecretRecoveryEnvelopeCodec.Wrap(masterKey, RecoverySecret);
        using var archive = ZipFile.Open(path, ZipArchiveMode.Create);
        WriteManifestEntry(
            archive,
            "manifest.json",
            5,
            SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Mode,
            envelope);
        return path;
    }

    private static void WriteManifestEntry(
        ZipArchive archive,
        string name,
        int version,
        string mode,
        string envelope)
    {
        var entry = archive.CreateEntry(name, CompressionLevel.NoCompression);
        using var writer = new StreamWriter(entry.Open());
        writer.Write(JsonSerializer.Serialize(new
        {
            Version = version,
            SecretRecoveryMode = mode,
            WrappedSecretKeyEnvelope = envelope
        }));
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
