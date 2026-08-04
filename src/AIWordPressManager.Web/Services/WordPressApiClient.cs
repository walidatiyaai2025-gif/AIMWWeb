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
    IHttpClientFactory httpClientFactory,
    ISecretProtectionService secretProtectionService,
    ILogger<WordPressApiClient> logger) : IWordPressApiClient
{
    private const int MaximumErrorLength = 800;

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

        using var request = new HttpRequestMessage(method, requestUri)
        {
            Content = content
        };
        request.Headers.Authorization = new AuthenticationHeaderValue(
            "Basic",
            Convert.ToBase64String(Encoding.UTF8.GetBytes($"{connection.UserName}:{connection.ApplicationPassword}")));
        request.Headers.UserAgent.ParseAdd("AIWordPressManager/154.1");
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));

        var client = httpClientFactory.CreateClient(nameof(WordPressApiClient));
        client.Timeout = TimeSpan.FromMinutes(5);

        try
        {
            logger.LogInformation("WordPress API {Method} {RequestUri} for site {SiteId}", method, requestUri, siteId);
            using var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
            var headers = ReadHeaders(response);
            var body = await response.Content.ReadAsStringAsync(cancellationToken);

            if (!response.IsSuccessStatusCode)
            {
                var message = CreateErrorMessage(response.StatusCode, body);
                logger.LogWarning("WordPress API request failed: {StatusCode} {Message}", (int)response.StatusCode, message);
                return WordPressApiResponse<JsonDocument>.Failure(response.StatusCode, message, headers);
            }

            var document = string.IsNullOrWhiteSpace(body)
                ? JsonDocument.Parse("{}")
                : JsonDocument.Parse(body);

            return WordPressApiResponse<JsonDocument>.Success(response.StatusCode, document, headers);
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            const string message = "انتهت مهلة الاتصال بـ WordPress.";
            logger.LogWarning("WordPress API timeout for site {SiteId}: {RequestUri}", siteId, requestUri);
            return WordPressApiResponse<JsonDocument>.Failure(HttpStatusCode.RequestTimeout, message);
        }
        catch (HttpRequestException ex)
        {
            logger.LogError(ex, "WordPress API network error for site {SiteId}: {RequestUri}", siteId, requestUri);
            return WordPressApiResponse<JsonDocument>.Failure(HttpStatusCode.ServiceUnavailable, $"تعذر الاتصال بـ WordPress: {ex.Message}");
        }
        catch (JsonException ex)
        {
            logger.LogError(ex, "Invalid JSON returned by WordPress for site {SiteId}: {RequestUri}", siteId, requestUri);
            return WordPressApiResponse<JsonDocument>.Failure(HttpStatusCode.BadGateway, "أعاد WordPress استجابة JSON غير صالحة.");
        }
    }

    private async Task<WordPressConnectionData> ResolveConnectionAsync(Guid siteId, CancellationToken cancellationToken)
    {
        var site = await dbContext.Sites.AsNoTracking()
            .FirstOrDefaultAsync(x => x.Id == siteId && !x.IsDeleted, cancellationToken)
            ?? throw new InvalidOperationException("الموقع غير موجود.");

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

    private static Uri BuildRequestUri(string siteUrl, string relativePath)
    {
        if (Uri.TryCreate(relativePath, UriKind.Absolute, out var absolute))
            return absolute;

        var normalized = relativePath.StartsWith('/') ? relativePath : $"/{relativePath}";
        return new Uri($"{siteUrl}{normalized}", UriKind.Absolute);
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
