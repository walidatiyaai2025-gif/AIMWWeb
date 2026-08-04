using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;

namespace AIWordPressManager.Web.Services;

public sealed class WordPressConnectionTester(IHttpClientFactory httpClientFactory) : IWordPressConnectionTester
{
    public async Task<WordPressConnectionResult> TestAsync(
        WordPressConnectionRequest request,
        CancellationToken cancellationToken = default)
    {
        if (!Uri.TryCreate(request.SiteUrl, UriKind.Absolute, out var siteUri) ||
            siteUri.Scheme is not ("http" or "https"))
        {
            return new(false, "رابط الموقع غير صحيح.");
        }

        var rootUrl = siteUri.GetLeftPart(UriPartial.Authority).TrimEnd('/');
        var client = httpClientFactory.CreateClient(nameof(WordPressConnectionTester));
        client.Timeout = TimeSpan.FromSeconds(25);
        client.DefaultRequestHeaders.UserAgent.ParseAdd("AIWordPressManager/1.0");

        try
        {
            using var discoveryResponse = await client.GetAsync($"{rootUrl}/wp-json/", cancellationToken);
            if (!discoveryResponse.IsSuccessStatusCode)
            {
                return new(
                    false,
                    "تعذر الوصول إلى WordPress REST API.",
                    Diagnostics: $"GET /wp-json returned {(int)discoveryResponse.StatusCode} {discoveryResponse.ReasonPhrase}");
            }

            await using var discoveryStream = await discoveryResponse.Content.ReadAsStreamAsync(cancellationToken);
            using var discoveryJson = await JsonDocument.ParseAsync(discoveryStream, cancellationToken: cancellationToken);
            var root = discoveryJson.RootElement;

            var siteName = GetString(root, "name");
            var homeUrl = GetString(root, "home");
            var language = GetString(root, "site_icon_url") is not null
                ? GetString(root, "language")
                : GetString(root, "language");
            var version = discoveryResponse.Headers.TryGetValues("X-WP-Version", out var versionValues)
                ? versionValues.FirstOrDefault()
                : null;

            if (string.IsNullOrWhiteSpace(request.UserName) || string.IsNullOrWhiteSpace(request.ApplicationPassword))
            {
                return new(
                    true,
                    "تم الوصول إلى WordPress REST API. لم يتم اختبار تسجيل الدخول لأن بيانات الاعتماد غير مكتملة.",
                    siteName,
                    homeUrl,
                    version,
                    language);
            }

            using var authRequest = new HttpRequestMessage(HttpMethod.Get, $"{rootUrl}/wp-json/wp/v2/users/me?context=edit");
            var rawCredential = $"{request.UserName}:{request.ApplicationPassword.Replace(" ", string.Empty, StringComparison.Ordinal)}";
            authRequest.Headers.Authorization = new AuthenticationHeaderValue(
                "Basic",
                Convert.ToBase64String(Encoding.UTF8.GetBytes(rawCredential)));

            using var authResponse = await client.SendAsync(authRequest, cancellationToken);
            if (authResponse.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden)
            {
                return new(
                    false,
                    "تم الوصول إلى الموقع لكن اسم المستخدم أو Application Password غير صحيح، أو أن REST API محظور.",
                    siteName,
                    homeUrl,
                    version,
                    language,
                    Diagnostics: $"Authentication returned {(int)authResponse.StatusCode} {authResponse.ReasonPhrase}");
            }

            if (!authResponse.IsSuccessStatusCode)
            {
                return new(
                    false,
                    "وصل التطبيق إلى الموقع ولكن فشل اختبار المستخدم الحالي.",
                    siteName,
                    homeUrl,
                    version,
                    language,
                    Diagnostics: $"GET users/me returned {(int)authResponse.StatusCode} {authResponse.ReasonPhrase}");
            }

            var currentUser = await authResponse.Content.ReadFromJsonAsync<CurrentUserResponse>(cancellationToken: cancellationToken);
            return new(
                true,
                "تم الاتصال وتسجيل الدخول إلى WordPress بنجاح.",
                siteName,
                homeUrl,
                version,
                language,
                currentUser?.Id);
        }
        catch (TaskCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            return new(false, "انتهت مهلة الاتصال بالموقع. تأكد من الرابط والجدار الناري.");
        }
        catch (HttpRequestException ex)
        {
            return new(false, "تعذر الاتصال بالموقع عبر الشبكة.", Diagnostics: ex.Message);
        }
        catch (JsonException ex)
        {
            return new(false, "استجابة WordPress REST API غير صالحة.", Diagnostics: ex.Message);
        }
        catch (Exception ex)
        {
            return new(false, "حدث خطأ غير متوقع أثناء اختبار الاتصال.", Diagnostics: ex.Message);
        }
    }

    private static string? GetString(JsonElement root, string propertyName) =>
        root.TryGetProperty(propertyName, out var value) && value.ValueKind == JsonValueKind.String
            ? value.GetString()
            : null;

    private sealed record CurrentUserResponse(int Id);
}
