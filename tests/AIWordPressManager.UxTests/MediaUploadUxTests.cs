using System.Globalization;
using System.Net;
using System.Net.Sockets;
using System.Reflection;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class MediaUploadUxTests(UxTestHost host)
{
    private const string WordPressUser = "ux-wordpress-admin";
    private const string WordPressPassword = "UxWordPress123!";
    private const string FileName = "browser-media.png";
    private const string Title = "Browser Media Upload";
    private const string AltText = "Browser acceptance image";
    private const string Caption = "Uploaded through the real media browser workflow.";

    [Fact]
    public async Task Media_upload_reaches_WordPress_REST_and_reconciles_the_UI()
    {
        await using var wordpress = new WordPressFixture();
        var siteId = await SeedOwnedSiteAsync(wordpress.BaseUri);
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));

        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            await SaveCredentialsThroughUiAsync(page, siteId, wordpress);

            var response = await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}/media",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            var fileInput = page.Locator("input[type='file']");
            await fileInput.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Attached, Timeout = 10000 });
            await fileInput.SetInputFilesAsync(new FilePayload
            {
                Name = FileName,
                MimeType = "image/png",
                Buffer = Convert.FromBase64String("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2H0kAAAAASUVORK5CYII=")
            });

            var uploadPanel = fileInput.Locator("xpath=ancestor::*[.//button[contains(normalize-space(.), 'Upload batch to WordPress')]][1]");
            var inputs = uploadPanel.Locator("input:not([type='file'])");
            await inputs.Nth(0).FillAsync(Title);
            await inputs.Nth(1).FillAsync(AltText);
            await inputs.Nth(2).FillAsync(Caption);

            await uploadPanel.GetByRole(AriaRole.Button, new() { Name = "Upload batch to WordPress" })
                .ClickAsync(new LocatorClickOptions { Timeout = 5000 });

            await WaitUntilAsync(() => wordpress.Media is not null,
                "Upload did not reach the WordPress media REST endpoint.");
            await page.GetByText(Title, new PageGetByTextOptions { Exact = true }).WaitForAsync(
                new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 15000 });

            wordpress.Requests.Should().Contain(request =>
                request.Method == "POST" && request.Target == "/wp-json/wp/v2/media" &&
                request.Authorization.StartsWith("Basic ", StringComparison.Ordinal) &&
                request.Body.Contains(FileName, StringComparison.Ordinal) &&
                request.Body.Contains(Title, StringComparison.Ordinal) &&
                request.Body.Contains(AltText, StringComparison.Ordinal) &&
                request.Body.Contains(Caption, StringComparison.Ordinal));
            wordpress.FullSyncRequests.Should().BeGreaterThanOrEqualTo(5,
                "successful upload must reconcile through the production WordPress synchronization service");
            pageErrors.Should().BeEmpty("real media upload must not produce browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("media-upload");
        }
    }

    private async Task SaveCredentialsThroughUiAsync(IPage page, Guid siteId, WordPressFixture wordpress)
    {
        var response = await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}",
            new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);

        var form = page.Locator(".site-details-form-grid");
        await form.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
        await EnsureSiteDetailsInteractiveAsync(page);
        await form.Locator("input").Nth(0).FillAsync(WordPressUser);
        await form.Locator("input").Nth(1).FillAsync(WordPressPassword);
        await page.Locator(".site-details-form-actions button[type='submit']").First.ClickAsync();
        await WaitUntilAsync(() => wordpress.HasAuthenticatedConnectionTest,
            "Save/Test did not reach the WordPress connection tester.");
    }

    private static async Task EnsureSiteDetailsInteractiveAsync(IPage page)
    {
        var probe = page.Locator(".site-details-form-actions button[type='button']").First;
        var alert = page.Locator(".site-details-alert");
        var deadline = DateTime.UtcNow.AddSeconds(8);
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await probe.ClickAsync(new LocatorClickOptions { Timeout = 2000 });
                await alert.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 750 });
                return;
            }
            catch (TimeoutException) { await page.WaitForTimeoutAsync(150); }
        }
        throw new TimeoutException("Site Details did not become InteractiveServer-ready within 8 seconds.");
    }

    private async Task<Guid> SeedOwnedSiteAsync(Uri siteUri)
    {
        await using var db = CreateDbContext();
        var admin = await db.AuthUsers.SingleAsync(user => user.NormalizedUserName == "ADMIN");
        var site = new Site("Media REST UX site", siteUri, DateTime.UtcNow, admin.Id);
        db.Sites.Add(site);
        await db.SaveChangesAsync();
        return site.Id;
    }

    private AppDbContext CreateDbContext()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX fixture database context factory.");
        return (AppDbContext)(method.Invoke(host, null)
            ?? throw new InvalidOperationException("UX fixture database context factory returned null."));
    }

    private static async Task WaitUntilAsync(Func<bool> condition, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(15);
        while (DateTime.UtcNow < deadline)
        {
            if (condition()) return;
            await Task.Delay(100);
        }
        throw new TimeoutException(failure);
    }

    private sealed class WordPressFixture : IAsyncDisposable
    {
        private readonly TcpListener _listener = new(IPAddress.Loopback, 0);
        private readonly CancellationTokenSource _stop = new();
        private readonly object _sync = new();
        private readonly List<RecordedRequest> _requests = [];
        private readonly Task _loop;
        private MediaState? _media;

        public WordPressFixture()
        {
            _listener.Start();
            BaseUri = new Uri($"http://127.0.0.1:{((IPEndPoint)_listener.LocalEndpoint).Port}");
            _loop = AcceptAsync(_stop.Token);
        }

        public Uri BaseUri { get; }
        public MediaState? Media { get { lock (_sync) return _media; } }
        public IReadOnlyList<RecordedRequest> Requests { get { lock (_sync) return _requests.ToArray(); } }
        public int FullSyncRequests => Requests.Count(request => request.Method == "GET" &&
            (request.Target.StartsWith("/wp-json/wp/v2/posts?context=edit&per_page=100", StringComparison.Ordinal) ||
             request.Target.StartsWith("/wp-json/wp/v2/pages?context=edit&per_page=100", StringComparison.Ordinal) ||
             request.Target.StartsWith("/wp-json/wp/v2/categories?context=edit&per_page=100", StringComparison.Ordinal) ||
             request.Target.StartsWith("/wp-json/wp/v2/tags?context=edit&per_page=100", StringComparison.Ordinal) ||
             request.Target.StartsWith("/wp-json/wp/v2/media?context=edit&per_page=100", StringComparison.Ordinal)));
        public bool HasAuthenticatedConnectionTest => Requests.Any(request => request.Method == "GET" &&
            request.Target.StartsWith("/wp-json/wp/v2/users/me", StringComparison.Ordinal) &&
            request.Authorization.StartsWith("Basic ", StringComparison.Ordinal));

        public async ValueTask DisposeAsync()
        {
            _stop.Cancel();
            _listener.Stop();
            try { await _loop.WaitAsync(TimeSpan.FromSeconds(5)); } catch { }
            _stop.Dispose();
        }

        private async Task AcceptAsync(CancellationToken cancellationToken)
        {
            while (!cancellationToken.IsCancellationRequested)
            {
                try
                {
                    var client = await _listener.AcceptTcpClientAsync(cancellationToken);
                    _ = HandleAsync(client, cancellationToken);
                }
                catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested) { break; }
                catch (ObjectDisposedException) when (cancellationToken.IsCancellationRequested) { break; }
            }
        }

        private async Task HandleAsync(TcpClient client, CancellationToken cancellationToken)
        {
            using (client)
            await using (var stream = client.GetStream())
            {
                try
                {
                    var request = await ReadRequestAsync(stream, cancellationToken);
                    lock (_sync) _requests.Add(request);
                    var response = Route(request);
                    await WriteResponseAsync(stream, response.Status, response.Body, response.Headers, cancellationToken);
                }
                catch (Exception ex) when (ex is IOException or JsonException or OperationCanceledException)
                {
                    if (!cancellationToken.IsCancellationRequested)
                    {
                        try { await WriteResponseAsync(stream, 500, JsonSerializer.Serialize(new { error = ex.Message }), null, CancellationToken.None); }
                        catch (IOException) { }
                    }
                }
            }
        }

        private Response Route(RecordedRequest request)
        {
            var question = request.Target.IndexOf('?');
            var path = question >= 0 ? request.Target[..question] : request.Target;
            if (request.Method == "GET" && path == "/wp-json/")
                return Json(200, new { name = "UX WordPress fixture", home = BaseUri.ToString().TrimEnd('/'), language = "en_US", site_icon_url = "" },
                    new Dictionary<string, string> { ["X-WP-Version"] = "6.6-fixture" });
            if (request.Method == "GET" && path == "/wp-json/wp/v2/users/me") return Json(200, new { id = 1 });
            if (request.Method == "POST" && path == "/wp-json/wp/v2/media")
            {
                var media = new MediaState(8101, Title, FileName[..^4], AltText, Caption, "image", "image/png", $"{BaseUri.ToString().TrimEnd('/')}/uploads/{FileName}");
                lock (_sync) _media = media;
                return Json(201, ToWordPressMedia(media));
            }
            if (request.Method == "GET" && path == "/wp-json/wp/v2/media")
            {
                MediaState? media;
                lock (_sync) media = _media;
                return Paged(media is null ? Array.Empty<object>() : new[] { ToWordPressMedia(media) });
            }
            if (request.Method == "GET" && path is "/wp-json/wp/v2/posts" or "/wp-json/wp/v2/pages" or "/wp-json/wp/v2/categories" or "/wp-json/wp/v2/tags")
                return Paged(Array.Empty<object>());
            return Json(404, new { code = "rest_no_route", message = $"No fixture route for {request.Method} {request.Target}" });
        }

        private static object ToWordPressMedia(MediaState media) => new
        {
            id = media.Id,
            date = "2026-08-23T12:00:00",
            date_gmt = "2026-08-23T12:00:00",
            modified = "2026-08-23T12:00:00",
            modified_gmt = "2026-08-23T12:00:00",
            slug = media.Slug,
            status = "inherit",
            type = "attachment",
            link = media.SourceUrl,
            title = new { rendered = media.Title, raw = media.Title },
            author = 1,
            description = new { rendered = "", raw = "" },
            caption = new { rendered = media.Caption, raw = media.Caption },
            alt_text = media.AltText,
            media_type = media.MediaType,
            mime_type = media.MimeType,
            media_details = new { width = 1, height = 1, filesize = 68 },
            source_url = media.SourceUrl
        };

        private static Response Paged(Array items) => Json(200, items, new Dictionary<string, string>
        {
            ["X-WP-Total"] = items.Length.ToString(CultureInfo.InvariantCulture),
            ["X-WP-TotalPages"] = "1"
        });

        private static Response Json(int status, object value, IReadOnlyDictionary<string, string>? headers = null) =>
            new(status, JsonSerializer.Serialize(value), headers ?? new Dictionary<string, string>());

        private static async Task<RecordedRequest> ReadRequestAsync(NetworkStream stream, CancellationToken cancellationToken)
        {
            var headerBytes = new List<byte>();
            var one = new byte[1];
            while (headerBytes.Count < 32768)
            {
                if (await stream.ReadAsync(one, cancellationToken) == 0) throw new IOException("HTTP headers ended early.");
                headerBytes.Add(one[0]);
                var n = headerBytes.Count;
                if (n >= 4 && headerBytes[n - 4] == '\r' && headerBytes[n - 3] == '\n' && headerBytes[n - 2] == '\r' && headerBytes[n - 1] == '\n') break;
            }

            var lines = Encoding.ASCII.GetString(headerBytes.ToArray()).Split("\r\n", StringSplitOptions.None);
            var requestLine = lines[0].Split(' ', 3);
            var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            foreach (var line in lines.Skip(1))
            {
                var colon = line.IndexOf(':');
                if (colon > 0) headers[line[..colon].Trim()] = line[(colon + 1)..].Trim();
            }

            var body = headers.TryGetValue("Transfer-Encoding", out var transfer) && transfer.Contains("chunked", StringComparison.OrdinalIgnoreCase)
                ? await ReadChunkedAsync(stream, cancellationToken)
                : Encoding.UTF8.GetString(await ReadExactlyAsync(stream,
                    headers.TryGetValue("Content-Length", out var raw) && int.TryParse(raw, out var length) ? length : 0,
                    cancellationToken));

            return new RecordedRequest(requestLine[0], requestLine[1], body,
                headers.TryGetValue("Authorization", out var authorization) ? authorization : string.Empty);
        }

        private static async Task<string> ReadChunkedAsync(NetworkStream stream, CancellationToken cancellationToken)
        {
            using var output = new MemoryStream();
            while (true)
            {
                var sizeLine = await ReadLineAsync(stream, cancellationToken);
                var semicolon = sizeLine.IndexOf(';');
                if (semicolon >= 0) sizeLine = sizeLine[..semicolon];
                var size = int.Parse(sizeLine, NumberStyles.HexNumber, CultureInfo.InvariantCulture);
                if (size == 0)
                {
                    await ReadLineAsync(stream, cancellationToken);
                    break;
                }

                var chunk = await ReadExactlyAsync(stream, size, cancellationToken);
                await output.WriteAsync(chunk, cancellationToken);
                await ReadExactlyAsync(stream, 2, cancellationToken);
            }
            return Encoding.UTF8.GetString(output.ToArray());
        }

        private static async Task<string> ReadLineAsync(NetworkStream stream, CancellationToken cancellationToken)
        {
            var bytes = new List<byte>();
            var one = new byte[1];
            while (true)
            {
                if (await stream.ReadAsync(one, cancellationToken) == 0) throw new IOException("HTTP line ended early.");
                if (one[0] == '\n') break;
                if (one[0] != '\r') bytes.Add(one[0]);
            }
            return Encoding.ASCII.GetString(bytes.ToArray());
        }

        private static async Task<byte[]> ReadExactlyAsync(NetworkStream stream, int length, CancellationToken cancellationToken)
        {
            var bytes = new byte[length];
            var offset = 0;
            while (offset < length)
            {
                var read = await stream.ReadAsync(bytes.AsMemory(offset, length - offset), cancellationToken);
                if (read == 0) throw new IOException("HTTP body ended early.");
                offset += read;
            }
            return bytes;
        }

        private static async Task WriteResponseAsync(NetworkStream stream, int status, string body, IReadOnlyDictionary<string, string>? headers, CancellationToken cancellationToken)
        {
            var payload = Encoding.UTF8.GetBytes(body);
            var reason = status switch { 200 => "OK", 201 => "Created", 404 => "Not Found", _ => "Internal Server Error" };
            var text = new StringBuilder()
                .Append($"HTTP/1.1 {status} {reason}\r\n")
                .Append("Content-Type: application/json; charset=utf-8\r\n")
                .Append($"Content-Length: {payload.Length}\r\n")
                .Append("Connection: close\r\n");
            if (headers is not null)
                foreach (var header in headers) text.Append($"{header.Key}: {header.Value}\r\n");
            text.Append("\r\n");
            await stream.WriteAsync(Encoding.ASCII.GetBytes(text.ToString()), cancellationToken);
            await stream.WriteAsync(payload, cancellationToken);
            await stream.FlushAsync(cancellationToken);
        }

        public sealed record RecordedRequest(string Method, string Target, string Body, string Authorization);
        public sealed record MediaState(int Id, string Title, string Slug, string AltText, string Caption, string MediaType, string MimeType, string SourceUrl);
        private sealed record Response(int Status, string Body, IReadOnlyDictionary<string, string> Headers);
    }
}
