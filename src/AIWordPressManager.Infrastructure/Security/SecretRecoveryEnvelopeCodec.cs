using System.Security.Cryptography;
using System.Text;
using AIWordPressManager.Application.Abstractions;

namespace AIWordPressManager.Infrastructure.Security;

public static class SecretRecoveryEnvelopeCodec
{
    public const string Prefix = SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1Prefix;
    public const int RecoverySecretMinimumLength = 16;
    public const int RecoverySecretMaximumLength = 1024;
    public const int IterationCount = 600_000;

    private const int MasterKeySize = 32;
    private const int SaltSize = 16;
    private const int NonceSize = 12;
    private const int TagSize = 16;
    private const int PayloadSize = SaltSize + NonceSize + TagSize + MasterKeySize;
    private const int WrappingKeySize = 32;
    private static readonly byte[] AssociatedData = Encoding.UTF8.GetBytes("AIWordPressManager.SecretRecovery.v1");

    public static string Wrap(ReadOnlySpan<byte> masterKey, string recoverySecret)
    {
        ValidateMasterKey(masterKey);
        ValidateRecoverySecret(recoverySecret);

        var recoverySecretBytes = Encoding.UTF8.GetBytes(recoverySecret);
        var salt = RandomNumberGenerator.GetBytes(SaltSize);
        var nonce = RandomNumberGenerator.GetBytes(NonceSize);
        var wrappingKey = new byte[WrappingKeySize];
        var cipherText = new byte[MasterKeySize];
        var tag = new byte[TagSize];

        try
        {
            Rfc2898DeriveBytes.Pbkdf2(
                recoverySecretBytes,
                salt,
                wrappingKey,
                IterationCount,
                HashAlgorithmName.SHA256);

            using var aes = new AesGcm(wrappingKey, TagSize);
            aes.Encrypt(nonce, masterKey, cipherText, tag, AssociatedData);

            var payload = new byte[PayloadSize];
            Buffer.BlockCopy(salt, 0, payload, 0, SaltSize);
            Buffer.BlockCopy(nonce, 0, payload, SaltSize, NonceSize);
            Buffer.BlockCopy(tag, 0, payload, SaltSize + NonceSize, TagSize);
            Buffer.BlockCopy(cipherText, 0, payload, SaltSize + NonceSize + TagSize, MasterKeySize);
            return Prefix + Convert.ToBase64String(payload);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(recoverySecretBytes);
            CryptographicOperations.ZeroMemory(wrappingKey);
            CryptographicOperations.ZeroMemory(cipherText);
        }
    }

    public static bool Verify(
        string wrappedKeyEnvelope,
        ReadOnlySpan<byte> expectedMasterKey,
        string recoverySecret)
    {
        ValidateMasterKey(expectedMasterKey);
        ValidateRecoverySecret(recoverySecret);

        if (string.IsNullOrWhiteSpace(wrappedKeyEnvelope) ||
            wrappedKeyEnvelope.Length > SecretRecoveryKeyEnvelopeFormat.MaximumEnvelopeLength ||
            !wrappedKeyEnvelope.StartsWith(Prefix, StringComparison.Ordinal))
        {
            return false;
        }

        byte[] payload;
        try
        {
            payload = Convert.FromBase64String(wrappedKeyEnvelope[Prefix.Length..]);
        }
        catch (FormatException)
        {
            return false;
        }

        if (payload.Length != SecretRecoveryKeyEnvelopeFormat.WrappedKeyV1PayloadBytes || payload.Length != PayloadSize)
        {
            CryptographicOperations.ZeroMemory(payload);
            return false;
        }

        var recoverySecretBytes = Encoding.UTF8.GetBytes(recoverySecret);
        var wrappingKey = new byte[WrappingKeySize];
        var recoveredKey = new byte[MasterKeySize];

        try
        {
            var salt = payload.AsSpan(0, SaltSize);
            var nonce = payload.AsSpan(SaltSize, NonceSize);
            var tag = payload.AsSpan(SaltSize + NonceSize, TagSize);
            var cipherText = payload.AsSpan(SaltSize + NonceSize + TagSize, MasterKeySize);

            Rfc2898DeriveBytes.Pbkdf2(
                recoverySecretBytes,
                salt,
                wrappingKey,
                IterationCount,
                HashAlgorithmName.SHA256);

            try
            {
                using var aes = new AesGcm(wrappingKey, TagSize);
                aes.Decrypt(nonce, cipherText, tag, recoveredKey, AssociatedData);
            }
            catch (CryptographicException)
            {
                return false;
            }

            return CryptographicOperations.FixedTimeEquals(recoveredKey, expectedMasterKey);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(payload);
            CryptographicOperations.ZeroMemory(recoverySecretBytes);
            CryptographicOperations.ZeroMemory(wrappingKey);
            CryptographicOperations.ZeroMemory(recoveredKey);
        }
    }

    private static void ValidateMasterKey(ReadOnlySpan<byte> masterKey)
    {
        if (masterKey.Length != MasterKeySize)
            throw new ArgumentException($"The secret-protection master key must be exactly {MasterKeySize} bytes.", nameof(masterKey));
    }

    private static void ValidateRecoverySecret(string recoverySecret)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(recoverySecret);
        if (recoverySecret.Length < RecoverySecretMinimumLength || recoverySecret.Length > RecoverySecretMaximumLength)
        {
            throw new ArgumentOutOfRangeException(
                nameof(recoverySecret),
                $"The recovery secret must contain between {RecoverySecretMinimumLength} and {RecoverySecretMaximumLength} characters.");
        }
    }
}
