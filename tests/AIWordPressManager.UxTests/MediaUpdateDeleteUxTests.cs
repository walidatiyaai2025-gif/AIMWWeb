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
public sealed class MediaUpdateDeleteUxTests(UxTestHost host)
{
    private const string WordPressUser = "ux-wordpress-admin";
    private const string WordPressPassword = "UxWordPress123!";
    private const string OriginalTitle = "Existing Browser Media";
    private const string UpdatedTitle = "Updated Browser Media";
    private const string UpdatedSlug = "updated-browser-media";
    private const string UpdatedAlt = "Updated browser acceptance alt text";
    private const string UpdatedCaption = "Updated through the real Media Manager UI.";
    private const string UpdatedDescription = "Browser acceptance proves metadata persistence and reconciliation.";

    [Fact]
    public async Task Media_metadata_update_and_permanent_delete_reach_WordPress_and_reconcile_UI()
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

            await RefreshThroughInteractiveUiAsync(page, wordpress, 5);
            await page.GetByText(OriginalTitle, new PageGetByTextOptions { Exact = true }).Last.WaitForAsync(
                new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

            var originalRow = page.GetByText(OriginalTitle, new PageGetByTextOptions { Exact = true }).Last
                .Locator("xpath=ancestor::tr[1]");
            await originalRow.GetByRole(AriaRole.Button, new() { Name = "Optimize", Exact = true }).ClickAsync();

            var saveButton = page.GetByRole(AriaRole.Button, new() { Name = "Save optimization", Exact = true });
            await saveButton.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var form = page.Locator(".form-grid").First;
            var inputs = form.Locator("input");
            await inputs.Nth(0).FillAsync(UpdatedTitle);
            await inputs.Nth(1).FillAsync(UpdatedSlug);
            await inputs.Nth(2).FillAsync(UpdatedAlt);
            var textareas = form.Locator("textarea");
            await textareas.Nth(0).FillAsync(UpdatedCaption);
            await textareas.Nth(1).FillAsync(UpdatedDescription);
            await saveButton.ClickAsync();

            await WaitUntilAsync(() => wordpress.UpdateCount == 1,
                "Metadata save did not reach the WordPress media endpoint.");
            await page.GetByText(UpdatedTitle, new PageGetByTextOptions { Exact = true }).Last.WaitForAsync(
                new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 15000 });

            var updateRequest = wordpress.Requests.Single(request =>
                request.Method == "POST" && request.Target == "/wp-json/wp/v2/media/8101");
            updateRequest.Authorization.Should().StartWith("Basic ");
            using (var updateJson = JsonDocument.Parse(updateRequest.Body))
            {
                var root = updateJson.RootElement;
                root.GetProperty("title").GetString().Should().Be(UpdatedTitle);
                root.GetProperty("slug").GetString().Should().Be(UpdatedSlug);
                root.GetProperty("alt_text").GetString().Should().Be(UpdatedAlt);
                root.GetProperty("caption").GetString().Should().Be(UpdatedCaption);
                root.GetProperty("description").GetString().Should().Be(UpdatedDescription);
            }

            var updatedRow = page.GetByText(UpdatedTitle, new PageGetByTextOptions { Exact = true }).Last
                .Locator("xpath=ancestor::tr[1]");
            await updatedRow.GetByRole(AriaRole.Button, new() { Name = "Delete", Exact = true }).ClickAsync();
            var confirm = page.GetByRole(AriaRole.Button, new() { Name = "Delete permanently", Exact = true });
            await confirm.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 5000 });
            await confirm.ClickAsync();

            await WaitUntilAsync(() => wordpress.DeleteCount == 1,
                "Permanent delete did not reach the WordPress media endpoint.");
            await page.GetByText(UpdatedTitle, new PageGetByTextOptions { Exact = true }).Last.WaitForAsync(
                new LocatorWaitForOptions { State = WaitForSelectorState.Hidden, Timeout = 15000 });

            wordpress.Requests.Should().Contain(request =>
                request.Method == "DELETE" && request.Target == "/wp-json/wp/v2/media/8101?force=true" &&
                request.Authorization.StartsWith("Basic ", StringComparison.Ordinal));
            wordpress.FullSyncRequests.Should().BeGreaterThanOrEqualTo(15,
                "refresh, successful metadata update, and permanent delete must each reconcile through production synchronization");
            pageErrors.Should().BeEmpty("real media mutations must not produce browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("media-update-delete");
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
                await probe.ClickAsync(new LocatorClickOptions { Timeout = 1500 });
                await alert.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 750 });
                return;
            }
            catch (TimeoutException) { await page.WaitForTimeoutAsync(100); }
        }
        throw new TimeoutException("Site Details did not become InteractiveServer-ready within 8 seconds.");
    }

    private static async Task RefreshThroughInteractiveUiAsync(IPage page, WordPressFixture wordpress, int expectedAdditionalSyncRequests)
    {
        var refresh = page.Locator(".media-manager-workspace button.app-button[aria-label='Refresh']").First;
        var baseline = wordpress.FullSyncRequests;
        var deadline = DateTime.UtcNow.AddSeconds(8);
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await refresh.ClickAsync(new LocatorClickOptions { Timeout = 1500 });
                var sideEffectDeadline = DateTime.UtcNow.AddMilliseconds(1500);
                while (DateTime.UtcNow < sideEffectDeadline)
                {
                    if (wordpress.FullSyncRequests >= baseline + expectedAdditionalSyncRequests) return;
                    await page.WaitForTimeoutAsync(100);
                }
            }
            catch (TimeoutException) { await page.WaitForTimeoutAsync(100); }
        }
        throw new TimeoutException("Media Manager did not become InteractiveServer-ready within 8 seconds.");
    }

    private async Task<Guid> SeedOwnedSiteAsync(Uri siteUri)
    {
        await using var db = CreateDbContext();
        var admin = await db.AuthUsers.SingleAsync(user => user.NormalizedUserName == "ADMIN");
        var site = new Site("Media mutation UX site", siteUri, DateTime.UtcNow, admin.Id);
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
        private MediaState? _media = new(8101, OriginalTitle, "existing-browser-media", "Original alt", "Original caption", "Original description", "2026-08-23T12:00:00", "image", "image/png");
        private int _updateCount;
        private int _deleteCount;

        public WordPressFixture()
        {
            _listener.Start();
            BaseUri = new Uri($"http://127.0.0.1:{((IPEndPoint)_listener.LocalEndpoint).Port}");
            _loop = AcceptAsync(_stop.Token);
        }

        public Uri BaseUri { get; }
        public IReadOnlyList<RecordedRequest> Requests { get { lock (_sync) return _requests.ToArray(); } }
        public int UpdateCount { get { lock (_sync) return _updateCount; } }
        public int DeleteCount { get { lock (_sync) return _deleteCount; } }
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
            var queryIndex = request.Target.IndexOf('?');
            var path = queryIndex >= 0 ? request.Target[..queryIndex] : request.Target;
            if (request.Method == "GET" && path == "/wp-json/")
                return Json(200, new { name = "UX WordPress fixture", home = BaseUri.ToString().TrimEnd('/'), language = "en_US", site_icon_url = "" },
                    new Dictionary<string, string> { ["X-WP-Version"] = "6.6-fixture" });
            if (request.Method == "GET" && path == "/wp-json/wp/v2/users/me") return Json(200, new { id = 1 });
            if (request.Method == "GET" && path == "/wp-json/wp/v2/media/8101")
            {
                lock (_sync) return _media is null ? Json(404, new { code = "rest_post_invalid_id" }) : Json(200, ToWordPressMedia(_media));
            }
            if (request.Method == "POST" && path == "/wp-json/wp/v2/media/8101")
            {
                using var json = JsonDocument.Parse(request.Body);
                var root = json.RootElement;
                lock (_sync)
                {
                    _media = new MediaState(8101,
                        root.GetProperty("title").GetString() ?? string.Empty,
                        root.GetProperty("slug").GetString() ?? string.Empty,
                        root.GetProperty("alt_text").GetString() ?? string.Empty,
                        root.GetProperty("caption").GetString() ?? string.Empty,
                        root.GetProperty("description").GetString() ?? string.Empty,
                        "2026-08-23T12:05:00", "image", "image/png");
                    _updateCount++;
                    return Json(200, ToWordPressMedia(_media));
                }
            }
            if (request.Method == "DELETE" && request.Target == "/wp-json/wp/v2/media/8101?force=true")
            {
                lock (_sync)
                {
                    var previous = _media;
                    _media = null;
                    _deleteCount++;
                    return Json(200, new { deleted = true, previous = previous is null ? null : ToWordPressMedia(previous) });
                }
            }
            if (request.Method == "GET" && path == "/wp-json/wp/v2/media")
            {
                lock (_sync) return Paged(_media is null ? Array.Empty<object>() : new[] { ToWordPressMedia(_media) });
            }
            if (request.Method == "GET" && path is "/wp-json/wp/v2/posts" or "/wp-json/wp/v2/pages" or "/wp-json/wp/v2/categories" or "/wp-json/wp/v2/tags")
                return Paged(Array.Empty<object>());
            return Json(404, new { code = "rest_no_route", message = $"No fixture route for {request.Method} {request.Target}" });
        }

        private object ToWordPressMedia(MediaState media) => new
        {
            id = media.Id,
            date = "2026-08-23T12:00:00",
            date_gmt = "2026-08-23T12:00:00",
            modified = media.ModifiedGmt,
            modified_gmt = media.ModifiedGmt,
            slug = media.Slug,
            status = "inherit",
            type = "attachment",
            link = $"{BaseUri.ToString().TrimEnd('/')}/uploads/browser-media.png",
            title = new { rendered = media.Title, raw = media.Title },
            author = 1,
            description = new { rendered = media.Description, raw = media.Description },
            caption = new { rendered = media.Caption, raw = media.Caption },
            alt_text = media.AltText,
            media_type = media.MediaType,
            mime_type = media.MimeType,
            media_details = new { width = 1200, height = 800, filesize = 4096 },
            source_url = $"{BaseUri.ToString().TrimEnd('/')}/uploads/browser-media.png"
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

            var length = headers.TryGetValue("Content-Length", out var raw) && int.TryParse(raw, out var parsed) ? parsed : 0;
            var body = Encoding.UTF8.GetString(await ReadExactlyAsync(stream, length, cancellationToken));
            return new RecordedRequest(requestLine[0], requestLine[1], body,
                headers.TryGetValue("Authorization", out var authorization) ? authorization : string.Empty);
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
            var reason = status switch { 200 => "OK", 404 => "Not Found", _ => "Internal Server Error" };
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
        private sealed record MediaState(int Id, string Title, string Slug, string AltText, string Caption, string Description, string ModifiedGmt, string MediaType, string MimeType);
        private sealed record Response(int Status, string Body, IReadOnlyDictionary<string, string> Headers);
    }
}
