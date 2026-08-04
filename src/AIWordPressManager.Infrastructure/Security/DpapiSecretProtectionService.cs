using System.Security.Cryptography;
using System.Text;
using AIWordPressManager.Application.Abstractions;

namespace AIWordPressManager.Infrastructure.Security;

/// <summary>
/// Cross-platform secret protection for the web application.
/// The historical class name is retained to avoid breaking dependency registration.
/// </summary>
public sealed class DpapiSecretProtectionService : ISecretProtectionService
{
    private const string Prefix = "aesgcm:v1:";
    private const int KeySize = 32;
    private const int NonceSize = 12;
    private const int TagSize = 16;
    private static readonly object KeyLock = new();

    private readonly byte[] _key;

    public DpapiSecretProtectionService()
    {
        _key = LoadOrCreateKey();
    }

    public Task<string> ProtectAsync(string plainText, CancellationToken cancellationToken = default)
    {
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
        ArgumentException.ThrowIfNullOrWhiteSpace(protectedValue);
        cancellationToken.ThrowIfCancellationRequested();

        if (!protectedValue.StartsWith(Prefix, StringComparison.Ordinal))
        {
            throw new CryptographicException("The stored secret uses an unsupported legacy encryption format. Please enter and save the credential again.");
        }

        var payload = Convert.FromBase64String(protectedValue[Prefix.Length..]);
        if (payload.Length < NonceSize + TagSize)
        {
            throw new CryptographicException("The encrypted value is invalid.");
        }

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
        }
    }

    private static byte[] LoadOrCreateKey()
    {
        var dataDirectory = Path.Combine(AppContext.BaseDirectory, "Data");
        var keyPath = Path.Combine(dataDirectory, ".secret-key");

        lock (KeyLock)
        {
            Directory.CreateDirectory(dataDirectory);

            if (File.Exists(keyPath))
            {
                var existingKey = Convert.FromBase64String(File.ReadAllText(keyPath).Trim());
                if (existingKey.Length != KeySize)
                {
                    throw new CryptographicException("The application encryption key has an invalid length.");
                }

                return existingKey;
            }

            var newKey = RandomNumberGenerator.GetBytes(KeySize);
            File.WriteAllText(keyPath, Convert.ToBase64String(newKey));
            return newKey;
        }
    }
}
