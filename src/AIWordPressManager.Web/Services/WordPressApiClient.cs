using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressApiClient(
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    IHttpClientFactory httpClientFactory,
    ISecretProtectionService secretProtectionService,
    ILogger<WordPressApiClient> logger) : IWordPressApiClient
{
    private const int MaximumErrorLength = 800;
    private const int MaximumReadAttempts = 3;

    public Task<WordPressApiResponse<JsonDocument>> GetAsync(
        Guid siteId,
        string relativePath,
        CancellationToken cancellationToken = default) =>
        SendAsync(siteId, HttpMethod.Get, relativePath, null, cancellationToken);

    public Task<WordPressApiResponse<JsonDocument>> SendAsync(
        Guid siteId,
        HttpMethod method,
        string relativePath,
        object? payload = null,
        CancellationToken cancellationToken = default)
    {
        HttpContent? content = payload is null ? null : JsonContent.Create(payload);
        return SendCoreAsync(siteId, method, relativePath, content, cancellationToken);
    }

    public Task<WordPressApiResponse<JsonDocument>> SendContentAsync(
        Guid siteId,
        HttpMethod method,
        string relativePath,
        HttpContent content,
        CancellationToken cancellationToken = default) =>
        SendCoreAsync(siteId, method, relativePath, content, cancellationToken);

    private async Task<WordPressApiResponse<JsonDocument>> SendCoreAsync(
        Guid siteId,
        HttpMethod method,
        string relativePath,
        HttpContent? content,
        CancellationToken cancellationToken)
    {
        var connection = await ResolveConnectionAsync(siteId, cancellationToken);
        var requestUri = BuildRequestUri(connection.SiteUrl, relativePath);
        var client = httpClientFactory.CreateClient(nameof(WordPressApiClient));
        client.Timeout = TimeSpan.FromMinutes(5);

        var maximumAttempts = IsSafeRead(method, content) ? MaximumReadAttempts : 1;

        for (var attempt = 1; attempt <= maximumAttempts; attempt++)
        {
            using var request = CreateRequest(method, requestUri, connection, content);

            try
            {
                logger.LogInformation(
                    "WordPress API {Method} {RequestUri} for site {SiteId}. Attempt {Attempt}/{MaximumAttempts}",
                    method,
                    requestUri,
                    siteId,
                    attempt,
                    maximumAttempts);

                using var response = await client.SendAsync(
                    request,
                    HttpCompletionOption.ResponseHeadersRead,
                    cancellationToken);

                var headers = ReadHeaders(response);
                var body = await response.Content.ReadAsStringAsync(cancellationToken);

                if (response.IsSuccessStatusCode)
                {
                    var document = string.IsNullOrWhiteSpace(body)
                        ? JsonDocument.Parse("{}")
                        : JsonDocument.Parse(body);

                    return WordPressApiResponse<JsonDocument>.Success(response.StatusCode, document, headers);
                }

                if (attempt < maximumAttempts && IsTransient(response.StatusCode))
                {
                    var delay = GetRetryDelay(response, attempt);
                    logger.LogWarning(
                        "Transient WordPress response {StatusCode}. Retrying after {DelayMs} ms. Site {SiteId}",
                        (int)response.StatusCode,
                        delay.TotalMilliseconds,
                        siteId);
                    await Task.Delay(delay, cancellationToken);
                    continue;
                }

                var message = CreateErrorMessage(response.StatusCode, body);
                logger.LogWarning(
                    "WordPress API request failed: {StatusCode} {Message}",
                    (int)response.StatusCode,
                    message);
                return WordPressApiResponse<JsonDocument>.Failure(response.StatusCode, message, headers);
            }
            catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
            {
                if (attempt < maximumAttempts)
                {
                    var delay = GetRetryDelay(null, attempt);
                    logger.LogWarning(
                        "WordPress API timeout. Retrying after {DelayMs} ms. Site {SiteId}",
                        delay.TotalMilliseconds,
                        siteId);
                    await Task.Delay(delay, cancellationToken);
                    continue;
                }

                const string message = "انتهت مهلة الاتصال بـ WordPress.";
                logger.LogWarning("WordPress API timeout for site {SiteId}: {RequestUri}", siteId, requestUri);
                return WordPressApiResponse<JsonDocument>.Failure(HttpStatusCode.RequestTimeout, message);
            }
            catch (HttpRequestException ex)
            {
                if (attempt < maximumAttempts)
                {
                    var delay = GetRetryDelay(null, attempt);
                    logger.LogWarning(
                        ex,
                        "WordPress API network error. Retrying after {DelayMs} ms. Site {SiteId}",
                        delay.TotalMilliseconds,
                        siteId);
                    await Task.Delay(delay, cancellationToken);
                    continue;
                }

                logger.LogError(ex, "WordPress API network error for site {SiteId}: {RequestUri}", siteId, requestUri);
                return WordPressApiResponse<JsonDocument>.Failure(
                    HttpStatusCode.ServiceUnavailable,
                    $"تعذر الاتصال بـ WordPress: {ex.Message}");
            }
            catch (JsonException ex)
            {
                logger.LogError(ex, "Invalid JSON returned by WordPress for site {SiteId}: {RequestUri}", siteId, requestUri);
                return WordPressApiResponse<JsonDocument>.Failure(
                    HttpStatusCode.BadGateway,
                    "أعاد WordPress استجابة JSON غير صالحة.");
            }
        }

        return WordPressApiResponse<JsonDocument>.Failure(
            HttpStatusCode.ServiceUnavailable,
            "تعذر إكمال طلب WordPress بعد عدة محاولات.");
    }

    private static HttpRequestMessage CreateRequest(
        HttpMethod method,
        Uri requestUri,
        WordPressConnectionData connection,
        HttpContent? content)
    {
        var request = new HttpRequestMessage(method, requestUri)
        {
            Content = content
        };

        request.Headers.Authorization = new AuthenticationHeaderValue(
            "Basic",
            Convert.ToBase64String(
                Encoding.UTF8.GetBytes($"{connection.UserName}:{connection.ApplicationPassword}")));
        request.Headers.UserAgent.ParseAdd("AIWordPressManager/155.77");
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        return request;
    }

    private async Task<WordPressConnectionData> ResolveConnectionAsync(
        Guid siteId,
        CancellationToken cancellationToken)
    {
        var ownerUserId = currentUser.RequireUserId();
        var site = await dbContext.Sites.AsNoTracking()
            .FirstOrDefaultAsync(
                x => x.Id == siteId && x.OwnerUserId == ownerUserId && !x.IsDeleted,
                cancellationToken)
            ?? throw new UnauthorizedAccessException("You do not have access to this WordPress site.");

        var credential = await dbContext.SiteCredentials.AsNoTracking()
            .FirstOrDefaultAsync(x => x.SiteId == siteId, cancellationToken)
            ?? throw new InvalidOperationException("احفظ بيانات اتصال WordPress واختبرها أولًا.");

        string password;
        try
        {
            password = await secretProtectionService.UnprotectAsync(
                credential.ProtectedApplicationPassword,
                cancellationToken);
        }
        catch (Exception ex)
        {
            throw new InvalidOperationException("تعذر قراءة كلمة المرور المشفرة. أعد حفظ بيانات الاتصال.", ex);
        }

        return new WordPressConnectionData(
            site.SiteUrl.TrimEnd('/'),
            credential.UserName,
            password.Replace(" ", string.Empty, StringComparison.Ordinal));
    }

    private static bool IsSafeRead(HttpMethod method, HttpContent? content) =>
        content is null && (method == HttpMethod.Get || method == HttpMethod.Head);

    private static bool IsTransient(HttpStatusCode statusCode) =>
        statusCode is HttpStatusCode.RequestTimeout or HttpStatusCode.TooManyRequests ||
        (int)statusCode >= 500;

    private static TimeSpan GetRetryDelay(HttpResponseMessage? response, int attempt)
    {
        if (response?.Headers.RetryAfter?.Delta is { } delta && delta > TimeSpan.Zero)
            return delta > TimeSpan.FromSeconds(30) ? TimeSpan.FromSeconds(30) : delta;

        if (response?.Headers.RetryAfter?.Date is { } date)
        {
            var calculated = date - DateTimeOffset.UtcNow;
            if (calculated > TimeSpan.Zero)
                return calculated > TimeSpan.FromSeconds(30) ? TimeSpan.FromSeconds(30) : calculated;
        }

        return TimeSpan.FromSeconds(Math.Pow(2, attempt - 1));
    }

    private static Uri BuildRequestUri(string siteUrl, string relativePath)
    {
        if (Uri.TryCreate(relativePath, UriKind.Absolute, out var absolute) &&
            absolute.Scheme is "http" or "https")
            return absolute;

        var baseUri = new Uri(siteUrl.EndsWith('/') ? siteUrl : siteUrl + "/", UriKind.Absolute);
        var normalized = relativePath.TrimStart('/');
        return new Uri(baseUri, normalized);
    }

    private static IReadOnlyDictionary<string, string> ReadHeaders(HttpResponseMessage response)
    {
        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var header in response.Headers)
            headers[header.Key] = string.Join(',', header.Value);
        foreach (var header in response.Content.Headers)
            headers[header.Key] = string.Join(',', header.Value);
        return headers;
    }

    private static string CreateErrorMessage(HttpStatusCode statusCode, string responseBody)
    {
        var trimmed = responseBody.Length > MaximumErrorLength
            ? responseBody[..MaximumErrorLength]
            : responseBody;

        return statusCode switch
        {
            HttpStatusCode.Unauthorized => "فشل تسجيل الدخول إلى WordPress. راجع اسم المستخدم وApplication Password.",
            HttpStatusCode.Forbidden => "لا يملك مستخدم WordPress الصلاحية المطلوبة لتنفيذ العملية.",
            HttpStatusCode.NotFound => "مسار WordPress REST API المطلوب غير موجود.",
            HttpStatusCode.TooManyRequests => "أوقف WordPress الطلب مؤقتًا بسبب كثرة الاستدعاءات. حاول لاحقًا.",
            _ => $"فشل طلب WordPress برمز {(int)statusCode}. {trimmed}"
        };
    }

    private sealed record WordPressConnectionData(
        string SiteUrl,
        string UserName,
        string ApplicationPassword);
}
