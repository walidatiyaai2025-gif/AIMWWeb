namespace AIWordPressManager.Application.Changes;

public sealed class AiProviderException(
    string provider,
    int? statusCode,
    string userMessage,
    string technicalMessage,
    Exception? innerException = null) : Exception(technicalMessage, innerException)
{
    public string Provider { get; } = provider;
    public int? StatusCode { get; } = statusCode;
    public string UserMessage { get; } = userMessage;
    public bool IsRetryable => StatusCode is null || StatusCode == 408 || StatusCode == 429 || StatusCode >= 500;
}
