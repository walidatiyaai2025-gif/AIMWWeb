namespace AIWordPressManager.Application.Abstractions.Billing;

public enum PayPalWebhookIntakeStatus
{
    Accepted = 1,
    Duplicate = 2,
    Rejected = 3,
    Unavailable = 4
}

public sealed record PayPalWebhookIntakeResult(
    PayPalWebhookIntakeStatus Status,
    string SanitizedSummary,
    string? ProviderEventId = null);

public interface IPayPalWebhookIntakeService
{
    Task<PayPalWebhookIntakeResult> HandleAsync(
        string body,
        IReadOnlyDictionary<string, string> headers,
        DateTime receivedAtUtc,
        CancellationToken cancellationToken = default);
}
