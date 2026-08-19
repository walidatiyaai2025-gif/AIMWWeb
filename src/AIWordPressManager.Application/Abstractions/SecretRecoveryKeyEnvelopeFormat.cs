namespace AIWordPressManager.Application.Abstractions;

public static class SecretRecoveryKeyEnvelopeFormat
{
    public const string WrappedKeyV1Mode = "wrapped-key-v1";
    public const string WrappedKeyV1Prefix = "aiwm-keywrap:v1:";
    public const int WrappedKeyV1PayloadBytes = 76;
    public const int MaximumEnvelopeLength = 256;
}
