using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

namespace AIWordPressManager.Tests.Fixtures;

public sealed class MockWordPressHttpHandler : HttpMessageHandler
{
    private readonly List<MockWordPressRoute> _routes = [];
    private readonly List<RecordedWordPressRequest> _requests = [];

    public IReadOnlyList<RecordedWordPressRequest> Requests => _requests;

    public MockWordPressHttpHandler AddJson(
        HttpMethod method,
        string pathAndQuery,
        HttpStatusCode statusCode,
        object? payload,
        IReadOnlyDictionary<string, string>? headers = null,
        string? expectedBasicUserName = null,
        string? expectedBasicPassword = null)
    {
        var json = payload is string raw ? raw : JsonSerializer.Serialize(payload);
        _routes.Add(new MockWordPressRoute(
            method,
            NormalizePath(pathAndQuery),
            statusCode,
            json,
            "application/json",
            headers ?? new Dictionary<string, string>(),
            expectedBasicUserName,
            expectedBasicPassword));
        return this;
    }

    public MockWordPressHttpHandler AddWordPressPage<T>(
        string endpoint,
        int page,
        int totalPages,
        int totalItems,
        IReadOnlyList<T> items)
    {
        return AddJson(
            HttpMethod.Get,
            $"{endpoint}?page={page}",
            HttpStatusCode.OK,
            items,
            new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
            {
                ["X-WP-Total"] = totalItems.ToString(),
                ["X-WP-TotalPages"] = totalPages.ToString()
            });
    }

    protected override async Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request,
        CancellationToken cancellationToken)
    {
        var body = request.Content is null
            ? null
            : await request.Content.ReadAsStringAsync(cancellationToken);

        var recorded = new RecordedWordPressRequest(
            request.Method,
            request.RequestUri ?? new Uri("http://localhost/"),
            request.Headers.Authorization?.Scheme,
            request.Headers.Authorization?.Parameter,
            body);
        _requests.Add(recorded);

        var path = NormalizePath(request.RequestUri?.PathAndQuery ?? "/");
        var route = _routes.FirstOrDefault(x => x.Method == request.Method && x.PathAndQuery == path);
        if (route is null)
        {
            return JsonResponse(
                HttpStatusCode.NotFound,
                JsonSerializer.Serialize(new { code = "rest_no_route", message = $"No mock route for {request.Method} {path}" }));
        }

        if (!CredentialsMatch(request.Headers.Authorization, route.ExpectedBasicUserName, route.ExpectedBasicPassword))
        {
            return JsonResponse(
                HttpStatusCode.Unauthorized,
                JsonSerializer.Serialize(new { code = "rest_not_logged_in", message = "Invalid application password." }));
        }

        var response = new HttpResponseMessage(route.StatusCode)
        {
            Content = new StringContent(route.Body, Encoding.UTF8, route.ContentType),
            RequestMessage = request
        };
        foreach (var header in route.Headers)
        {
            if (!response.Headers.TryAddWithoutValidation(header.Key, header.Value))
                response.Content.Headers.TryAddWithoutValidation(header.Key, header.Value);
        }
        return response;
    }

    private static bool CredentialsMatch(AuthenticationHeaderValue? authorization, string? userName, string? password)
    {
        if (userName is null && password is null) return true;
        if (!string.Equals(authorization?.Scheme, "Basic", StringComparison.OrdinalIgnoreCase) ||
            string.IsNullOrWhiteSpace(authorization.Parameter)) return false;

        try
        {
            var value = Encoding.UTF8.GetString(Convert.FromBase64String(authorization.Parameter));
            return string.Equals(value, $"{userName}:{password}", StringComparison.Ordinal);
        }
        catch (FormatException)
        {
            return false;
        }
    }

    private static HttpResponseMessage JsonResponse(HttpStatusCode statusCode, string json) => new(statusCode)
    {
        Content = new StringContent(json, Encoding.UTF8, "application/json")
    };

    private static string NormalizePath(string value)
    {
        if (string.IsNullOrWhiteSpace(value)) return "/";
        if (Uri.TryCreate(value, UriKind.Absolute, out var absolute)) value = absolute.PathAndQuery;
        return value.StartsWith('/') ? value : $"/{value}";
    }
}

public sealed record RecordedWordPressRequest(
    HttpMethod Method,
    Uri Uri,
    string? AuthorizationScheme,
    string? AuthorizationParameter,
    string? Body);

internal sealed record MockWordPressRoute(
    HttpMethod Method,
    string PathAndQuery,
    HttpStatusCode StatusCode,
    string Body,
    string ContentType,
    IReadOnlyDictionary<string, string> Headers,
    string? ExpectedBasicUserName,
    string? ExpectedBasicPassword);