namespace AIWordPressManager.Application.Abstractions;

public interface ISecretProtectionService
{
    Task<string> ProtectAsync(string plainText, CancellationToken cancellationToken = default);

    Task<string> UnprotectAsync(string protectedValue, CancellationToken cancellationToken = default);
}
