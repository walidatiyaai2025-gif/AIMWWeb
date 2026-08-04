using System.Net;
using System.Text.Json;

namespace AIWordPressManager.Application.Abstractions.WordPress;

public interface IWordPressApiClient
{
    Task<WordPressApiResponse<JsonDocument>> GetAsync(
        Guid siteId,
        string relativePath,
        CancellationToken cancellationToken = default);

    Task<WordPressApiResponse<JsonDocument>> SendAsync(
        Guid siteId,
        HttpMethod method,
        string relativePath,
        object? payload = null,
        CancellationToken cancellationToken = default);

    Task<WordPressApiResponse<JsonDocument>> SendContentAsync(
        Guid siteId,
        HttpMethod method,
        string relativePath,
        HttpContent content,
        CancellationToken cancellationToken = default);
}

public sealed record WordPressApiResponse<T>(
    bool IsSuccess,
    HttpStatusCode StatusCode,
    T? Value,
    string ErrorMessage,
    IReadOnlyDictionary<string, string> Headers)
{
    public static WordPressApiResponse<T> Success(
        HttpStatusCode statusCode,
        T value,
        IReadOnlyDictionary<string, string> headers) =>
        new(true, statusCode, value, string.Empty, headers);

    public static WordPressApiResponse<T> Failure(
        HttpStatusCode statusCode,
        string errorMessage,
        IReadOnlyDictionary<string, string>? headers = null) =>
        new(false, statusCode, default, errorMessage,
            headers ?? new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase));
}
