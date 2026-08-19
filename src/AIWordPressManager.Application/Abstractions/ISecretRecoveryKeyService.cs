namespace AIWordPressManager.Application.Abstractions;

/// <summary>
/// Exposes disaster-recovery operations for the application secret-protection key without ever
/// returning the raw master key to the application or UI layers.
/// </summary>
public interface ISecretRecoveryKeyService
{
    Task<string> ExportWrappedKeyAsync(
        string recoverySecret,
        CancellationToken cancellationToken = default);

    Task<bool> ValidateWrappedKeyAsync(
        string wrappedKeyEnvelope,
        string recoverySecret,
        CancellationToken cancellationToken = default);
}
