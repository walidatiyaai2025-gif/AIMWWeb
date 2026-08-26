using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;

namespace AIWordPressManager.Infrastructure.Security;

public sealed class OfflineSecretRecoveryInstaller
{
    private const int SupportedManifestVersion = 5;
    private const long MaximumManifestBytes = 64 * 1024;

    public SecretRecoveryInstallResult InstallFromBackup(
        string backupPath,
        string recoverySecret,
        bool replaceExisting = false,
        string? localApplicationData = null,
        RuntimeLockLease? existingRecoveryLease = null)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(backupPath);
        ArgumentException.ThrowIfNullOrWhiteSpace(recoverySecret);

        var fullBackupPath = Path.GetFullPath(backupPath);
        if (!File.Exists(fullBackupPath))
            throw new FileNotFoundException("The recovery backup archive was not found.", fullBackupPath);

        RuntimeLockLease? ownedLease = null;
        if (existingRecoveryLease is null)
            ownedLease = SecretProtectionStorage.AcquireRecoveryExclusiveLease(localApplicationData);

        try
        {
            var wrappedEnvelope = ReadWrappedEnvelope(fullBackupPath);
            var recoveredKey = SecretRecoveryEnvelopeCodec.Unwrap(wrappedEnvelope, recoverySecret);

            try
            {
                var keyPath = SecretProtectionStorage.GetKeyPath(localApplicationData);
                if (File.Exists(keyPath))
                {
                    byte[]? existingKey = null;
                    try
                    {
                        existingKey = SecretProtectionStorage.ReadAndValidateKey(keyPath);
                        if (CryptographicOperations.FixedTimeEquals(existingKey, recoveredKey))
                        {
                            return new SecretRecoveryInstallResult(
                                SecretRecoveryInstallStatus.AlreadyInstalled,
                                keyPath,
                                "The recovered key already matches the installed secret-protection key. No file was changed.");
                        }
                    }
                    catch (CryptographicException) when (replaceExisting)
                    {
                        // Explicit replacement is also the recovery path for a corrupted existing key file.
                    }
                    finally
                    {
                        if (existingKey is not null) CryptographicOperations.ZeroMemory(existingKey);
                    }

                    if (!replaceExisting)
                    {
                        throw new InvalidOperationException(
                            "A different secret-protection key is already installed. Re-run with explicit replacement only after confirming that the existing key is no longer authoritative.");
                    }

                    SecretProtectionStorage.WriteKeyAtomically(keyPath, recoveredKey, replaceExisting: true);
                    return new SecretRecoveryInstallResult(
                        SecretRecoveryInstallStatus.Replaced,
                        keyPath,
                        "The secret-protection key was replaced from authenticated wrapped recovery material.");
                }

                SecretProtectionStorage.WriteKeyAtomically(keyPath, recoveredKey, replaceExisting: false);
                return new SecretRecoveryInstallResult(
                    SecretRecoveryInstallStatus.Installed,
                    keyPath,
                    "The secret-protection key was installed from authenticated wrapped recovery material.");
            }
            finally
            {
                CryptographicOperations.ZeroMemory(recoveredKey);
            }
        }
        finally
        {
            ownedLease?.Dispose();
        }
    }

    internal static string ReadWrappedEnvelope(string backupPath)
    {
        using var archive = ZipFile.OpenRead(backupPath);
        var manifests = archive.Entries
            .Where(x => string.Equals(x.FullName, "manifest.json", StringComparison.OrdinalIgnoreCase))
            .ToList();
        if (manifests.Count != 1)
        {
            throw new InvalidDataException(
                manifests.Count == 0
                    ? "The recovery backup manifest is missing."
                    : "The recovery archive contains duplicate manifests.");
        }

        var manifestEntry = manifests[0];
        if (manifestEntry.Length <= 0 || manifestEntry.Length > MaximumManifestBytes)
            throw new InvalidDataException("The recovery backup manifest size is invalid or exceeds the safety limit.");

        using var stream = manifestEntry.Open();
        using var document = JsonDocument.Parse(stream, new JsonDocumentOptions
        {
            AllowTrailingCommas = false,
            CommentHandling = JsonCommentHandling.Disallow,
            MaxDepth = 32
        });
        var root = document.RootElement;
        if (!root.TryGetProperty("Version", out var versionElement) ||
            versionElement.ValueKind != JsonValueKind.Number ||
            !versionElement.TryGetInt32(out var version) ||
            version != SupportedManifestVersion)
        {
            throw new InvalidDataException($"Offline secret recovery currently supports backup manifest version {SupportedManifestVersion} only.");
        }

        if (!root.TryGetProperty("SecretRecoveryMode", out var modeElement) ||
            modeElement.ValueKind != JsonValueKind.String ||
            !string.Equals(modeElement.GetString(), SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Mode, StringComparison.Ordinal))
        {
            throw new InvalidDataException("The backup does not contain supported wrapped secret-recovery material.");
        }

        if (!root.TryGetProperty("WrappedSecretKeyEnvelope", out var envelopeElement) ||
            envelopeElement.ValueKind != JsonValueKind.String ||
            string.IsNullOrWhiteSpace(envelopeElement.GetString()))
        {
            throw new InvalidDataException("The wrapped secret-recovery envelope is missing from the backup manifest.");
        }

        var envelope = envelopeElement.GetString()!;
        if (envelope.Length > SecretRecoveryKeyEnvelopeFormat.MaximumEnvelopeLength ||
            !envelope.StartsWith(SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Prefix, StringComparison.Ordinal))
        {
            throw new InvalidDataException("The wrapped secret-recovery envelope is malformed or unsupported.");
        }

        return envelope;
    }
}

public enum SecretRecoveryInstallStatus
{
    Installed = 0,
    AlreadyInstalled = 1,
    Replaced = 2
}

public sealed record SecretRecoveryInstallResult(
    SecretRecoveryInstallStatus Status,
    string KeyPath,
    string Message);
