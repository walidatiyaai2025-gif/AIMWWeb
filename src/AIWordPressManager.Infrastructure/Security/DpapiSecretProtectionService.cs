using System.Security.Cryptography;
using System.Text;
using AIWordPressManager.Application.Abstractions;

namespace AIWordPressManager.Infrastructure.Security;

/// <summary>
/// Cross-platform secret protection for the web application.
/// The historical class name is retained to avoid breaking dependency registration.
/// </summary>
public sealed class DpapiSecretProtectionService : ISecretProtectionService, ISecretRecoveryKeyService, IDisposable
{
    private const string Prefix = "aesgcm:v1:";
    private const int NonceSize = 12;
    private const int TagSize = 16;
    private static readonly object KeyLock = new();

    private readonly RuntimeLockLease _runtimeLease;
    private readonly byte[] _key;
    private bool _disposed;

    public DpapiSecretProtectionService()
    {
        _runtimeLease = SecretProtectionStorage.AcquireWebRuntimeLease();
        try
        {
            _key = LoadOrCreateKey();
        }
        catch
        {
            _runtimeLease.Dispose();
            throw;
        }
    }

    public Task<string> ProtectAsync(string plainText, CancellationToken cancellationToken = default)
    {
        ThrowIfDisposed();
        ArgumentException.ThrowIfNullOrWhiteSpace(plainText);
        cancellationToken.ThrowIfCancellationRequested();

        var clearBytes = Encoding.UTF8.GetBytes(plainText);
        var nonce = RandomNumberGenerator.GetBytes(NonceSize);
        var cipherBytes = new byte[clearBytes.Length];
        var tag = new byte[TagSize];

        try
        {
            using var aes = new AesGcm(_key, TagSize);
            aes.Encrypt(nonce, clearBytes, cipherBytes, tag);

            var payload = new byte[NonceSize + TagSize + cipherBytes.Length];
            Buffer.BlockCopy(nonce, 0, payload, 0, NonceSize);
            Buffer.BlockCopy(tag, 0, payload, NonceSize, TagSize);
            Buffer.BlockCopy(cipherBytes, 0, payload, NonceSize + TagSize, cipherBytes.Length);

            return Task.FromResult(Prefix + Convert.ToBase64String(payload));
        }
        finally
        {
            CryptographicOperations.ZeroMemory(clearBytes);
            CryptographicOperations.ZeroMemory(cipherBytes);
        }
    }

    public Task<string> UnprotectAsync(string protectedValue, CancellationToken cancellationToken = default)
    {
        ThrowIfDisposed();
        ArgumentException.ThrowIfNullOrWhiteSpace(protectedValue);
        cancellationToken.ThrowIfCancellationRequested();

        if (!protectedValue.StartsWith(Prefix, StringComparison.Ordinal))
        {
            throw new CryptographicException(
                "The stored secret uses an unsupported legacy encryption format. Please enter and save the credential again.");
        }

        var payload = Convert.FromBase64String(protectedValue[Prefix.Length..]);
        if (payload.Length < NonceSize + TagSize)
            throw new CryptographicException("The encrypted value is invalid.");

        var nonce = payload.AsSpan(0, NonceSize);
        var tag = payload.AsSpan(NonceSize, TagSize);
        var cipherBytes = payload.AsSpan(NonceSize + TagSize);
        var clearBytes = new byte[cipherBytes.Length];

        try
        {
            using var aes = new AesGcm(_key, TagSize);
            aes.Decrypt(nonce, cipherBytes, tag, clearBytes);
            return Task.FromResult(Encoding.UTF8.GetString(clearBytes));
        }
        finally
        {
            CryptographicOperations.ZeroMemory(clearBytes);
            CryptographicOperations.ZeroMemory(payload);
        }
    }

    public Task<string> ExportWrappedKeyAsync(
        string recoverySecret,
        CancellationToken cancellationToken = default)
    {
        ThrowIfDisposed();
        cancellationToken.ThrowIfCancellationRequested();
        return Task.FromResult(SecretRecoveryEnvelopeCodec.Wrap(_key, recoverySecret));
    }

    public Task<bool> ValidateWrappedKeyAsync(
        string wrappedKeyEnvelope,
        string recoverySecret,
        CancellationToken cancellationToken = default)
    {
        ThrowIfDisposed();
        cancellationToken.ThrowIfCancellationRequested();
        return Task.FromResult(SecretRecoveryEnvelopeCodec.Verify(wrappedKeyEnvelope, _key, recoverySecret));
    }

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        CryptographicOperations.ZeroMemory(_key);
        _runtimeLease.Dispose();
    }

    private static byte[] LoadOrCreateKey()
    {
        var stableDirectory = SecretProtectionStorage.GetSecurityDirectory();
        var stableKeyPath = SecretProtectionStorage.GetKeyPath();
        var legacyKeyPath = Path.Combine(AppContext.BaseDirectory, "Data", SecretProtectionStorage.KeyFileName);

        lock (KeyLock)
        {
            Directory.CreateDirectory(stableDirectory);

            if (File.Exists(stableKeyPath))
                return SecretProtectionStorage.ReadAndValidateKey(stableKeyPath);

            if (File.Exists(legacyKeyPath))
            {
                var legacyKey = SecretProtectionStorage.ReadAndValidateKey(legacyKeyPath);
                SecretProtectionStorage.WriteKeyAtomically(stableKeyPath, legacyKey, replaceExisting: false);
                return legacyKey;
            }

            var newKey = RandomNumberGenerator.GetBytes(SecretProtectionStorage.MasterKeySize);
            SecretProtectionStorage.WriteKeyAtomically(stableKeyPath, newKey, replaceExisting: false);
            return newKey;
        }
    }

    private void ThrowIfDisposed()
    {
        ObjectDisposedException.ThrowIf(_disposed, this);
    }
}
