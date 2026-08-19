using System.Security.Cryptography;
using AIWordPressManager.Infrastructure.Security;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class SecretRecoveryEnvelopeCodecTests
{
    private const string RecoverySecret = "correct-horse-battery-staple-2026";

    [Fact]
    public void WrapAndVerify_RoundTripsWithoutExposingRawMasterKey()
    {
        var masterKey = RandomNumberGenerator.GetBytes(32);
        try
        {
            var envelope = SecretRecoveryEnvelopeCodec.Wrap(masterKey, RecoverySecret);

            envelope.Should().StartWith(SecretRecoveryEnvelopeCodec.Prefix);
            envelope.Should().NotContain(Convert.ToBase64String(masterKey));
            envelope.Should().NotContain(RecoverySecret);
            SecretRecoveryEnvelopeCodec.Verify(envelope, masterKey, RecoverySecret).Should().BeTrue();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(masterKey);
        }
    }

    [Fact]
    public void Verify_WrongRecoverySecretFailsClosed()
    {
        var masterKey = RandomNumberGenerator.GetBytes(32);
        try
        {
            var envelope = SecretRecoveryEnvelopeCodec.Wrap(masterKey, RecoverySecret);

            SecretRecoveryEnvelopeCodec.Verify(
                envelope,
                masterKey,
                "wrong-recovery-secret-2026").Should().BeFalse();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(masterKey);
        }
    }

    [Fact]
    public void Verify_TamperedEnvelopeFailsClosed()
    {
        var masterKey = RandomNumberGenerator.GetBytes(32);
        try
        {
            var envelope = SecretRecoveryEnvelopeCodec.Wrap(masterKey, RecoverySecret);
            var payload = Convert.FromBase64String(envelope[SecretRecoveryEnvelopeCodec.Prefix.Length..]);
            payload[^1] ^= 0x5A;
            var tampered = SecretRecoveryEnvelopeCodec.Prefix + Convert.ToBase64String(payload);

            SecretRecoveryEnvelopeCodec.Verify(tampered, masterKey, RecoverySecret).Should().BeFalse();
            CryptographicOperations.ZeroMemory(payload);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(masterKey);
        }
    }

    [Fact]
    public void Wrap_UsesFreshSaltAndNonceForEveryEnvelope()
    {
        var masterKey = RandomNumberGenerator.GetBytes(32);
        try
        {
            var first = SecretRecoveryEnvelopeCodec.Wrap(masterKey, RecoverySecret);
            var second = SecretRecoveryEnvelopeCodec.Wrap(masterKey, RecoverySecret);

            second.Should().NotBe(first);
            SecretRecoveryEnvelopeCodec.Verify(first, masterKey, RecoverySecret).Should().BeTrue();
            SecretRecoveryEnvelopeCodec.Verify(second, masterKey, RecoverySecret).Should().BeTrue();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(masterKey);
        }
    }

    [Fact]
    public void Verify_MalformedOrOversizedEnvelopeFailsWithoutDecodingUnboundedInput()
    {
        var masterKey = RandomNumberGenerator.GetBytes(32);
        try
        {
            SecretRecoveryEnvelopeCodec.Verify(
                SecretRecoveryEnvelopeCodec.Prefix + "not-base64!",
                masterKey,
                RecoverySecret).Should().BeFalse();

            SecretRecoveryEnvelopeCodec.Verify(
                SecretRecoveryEnvelopeCodec.Prefix + new string('A', 300),
                masterKey,
                RecoverySecret).Should().BeFalse();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(masterKey);
        }
    }

    [Fact]
    public void Wrap_RejectsWeakRecoverySecretAndInvalidMasterKeyLength()
    {
        var masterKey = RandomNumberGenerator.GetBytes(32);
        try
        {
            var weakSecret = () => SecretRecoveryEnvelopeCodec.Wrap(masterKey, "too-short");
            weakSecret.Should().Throw<ArgumentOutOfRangeException>();

            var invalidKey = () => SecretRecoveryEnvelopeCodec.Wrap(new byte[31], RecoverySecret);
            invalidKey.Should().Throw<ArgumentException>();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(masterKey);
        }
    }
}
