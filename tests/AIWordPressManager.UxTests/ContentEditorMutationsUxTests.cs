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
public sealed class ContentEditorMutationsUxTests(UxTestHost host)
{
    private const string User = "ux-wordpress-admin";
    private const string Password = "UxWordPress123!";

    [Fact]
    public async Task Post_and_page_edits_reach_WordPress_and_reload_from_the_remote_result()
    {
        await using var wordpress = new WordPressFixture();
        var siteId = await SeedSiteAsync(wordpress.BaseUri);
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            var errors = new List<string>();
            page.PageError += (_, message) => errors.Add(message);
            await SaveCredentialsAsync(page, siteId, wordpress);

            await ExerciseAsync(page, wordpress, siteId, "post", 9101, "Updated browser post", "updated-browser-post");
            await ExerciseAsync(page, wordpress, siteId, "page", 9201, "Updated browser page", "updated-browser-page");

            errors.Should().BeEmpty("real content mutations must not produce browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("content-editor-mutations");
        }
    }

    private static async Task ExerciseAsync(
        IPage page,
        WordPressFixture wordpress,
        Guid siteId,
        string contentType,
        int wordPressId,
        string updatedTitle,
        string updatedSlug)
    {
        var response = await page.GotoAsync(
            $"{wordpress.ApplicationBaseUrl(page)}/sites/{siteId}/content/{contentType}/{wordPressId}/edit",
            new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);

        var title = page.Locator(".editor-title-input");
        await title.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
        await EnsureEditorInteractiveAsync(page);
        await title.FillAsync(updatedTitle);
        await page.Locator(".editor-slug-row input").FillAsync(updatedSlug);
        await page.Locator(".content-editor-pro").FillAsync($"<p>{updatedTitle} content persisted through WordPress.</p>");
        await page.Locator(".excerpt-editor-pro").FillAsync($"{updatedTitle} excerpt persisted through WordPress.");

        var updateBaseline = wordpress.UpdateCount;
        var syncBaseline = wordpress.FullSyncRequests;
        var saveButton = page.Locator(".publish-card button.btn.primary[type='submit']");
        await saveButton.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
        (await saveButton.IsEnabledAsync()).Should().BeTrue("Content.Edit must expose the real WordPress save action");
        await saveButton.ClickAsync();

        await WaitUntilAsync(() => wordpress.UpdateCount == updateBaseline + 1, $"{contentType} save did not reach WordPress.");
        await WaitUntilAsync(() => wordpress.FullSyncRequests >= syncBaseline + 5, $"{contentType} save did not complete WordPress reconciliation.");

        var update = wordpress.Requests.Last(r =>
            r.Method == "POST" && r.Target == $"/wp-json/wp/v2/{(contentType == "page" ? "pages" : "posts")}/{wordPressId}");
        update.Authorization.Should().StartWith("Basic ");
        using (var document = JsonDocument.Parse(update.Body))
        {
            var root = document.RootElement;
            root.GetProperty("title").GetString().Should().Be(updatedTitle);
            root.GetProperty("slug").GetString().Should().Be(updatedSlug);
            root.GetProperty("content").GetString().Should().Contain("persisted through WordPress");
            root.GetProperty("excerpt").GetString().Should().Contain("excerpt persisted through WordPress");
        }

        await page.ReloadAsync(new PageReloadOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
        await title.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
        (await title.InputValueAsync()).Should().Be(updatedTitle,
            "a fresh editor load must read the value returned by WordPress rather than preserve local-only success state");
    }

    private static async Task EnsureEditorInteractiveAsync(IPage page)
    {
        var preview = page.Locator(".editor-view-toggle button").Nth(1);
        var edit = page.Locator(".editor-view-toggle button").Nth(0);
        var previewSurface = page.Locator(".content-preview");
        var editorSurface = page.Locator(".content-editor-pro");
        var deadline = DateTime.UtcNow.AddSeconds(8);
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await preview.ClickAsync(new() { Timeout = 1500 });
                await previewSurface.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 750 });
                await edit.ClickAsync(new() { Timeout = 1500 });
                await editorSurface.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 750 });
                return;
            }
            catch (TimeoutException)
            {
                await page.WaitForTimeoutAsync(100);
            }
        }

        throw new TimeoutException("Content Editor did not become interactive.");
    }

    private async Task SaveCredentialsAsync(IPage page, Guid siteId, WordPressFixture wordpress)
    {
        var response = await page.GotoAsync(
            host.BaseUrl + $"/sites/{siteId}",
            new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);

        var form = page.Locator(".site-details-form-grid");
        await form.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
        await EnsureSiteDetailsInteractiveAsync(page);
        await form.Locator("input").Nth(0).FillAsync(User);
        await form.Locator("input").Nth(1).FillAsync(Password);
        await page.Locator(".site-details-form-actions button[type='submit']").First.ClickAsync();
        await WaitUntilAsync(() => wordpress.HasAuthenticatedConnectionTest, "Credential save/test did not reach WordPress.");
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
                await probe.ClickAsync(new() { Timeout = 1500 });
                await alert.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 750 });
                return;
            }
            catch (TimeoutException)
            {
                await page.WaitForTimeoutAsync(100);
            }
        }

        throw new TimeoutException("Site Details did not become interactive.");
    }

    private async Task<Guid> SeedSiteAsync(Uri siteUri)
    {
        await using var db = CreateDbContext();
        var admin = await db.AuthUsers.SingleAsync(u => u.NormalizedUserName == "ADMIN");
        var site = new Site("Content editor mutation UX site", siteUri, DateTime.UtcNow, admin.Id);
        db.Sites.Add(site);
        await db.SaveChangesAsync();
        return site.Id;
    }

    private AppDbContext CreateDbContext()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX database context factory.");
        return (AppDbContext)(method.Invoke(host, null)
            ?? throw new InvalidOperationException("UX database context factory returned null."));
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
        private ContentState _post = ContentState.Create("post", 9101, "Existing browser post", "existing-browser-post");
        private ContentState _page = ContentState.Create("page", 9201, "Existing browser page", "existing-browser-page");
        private int _updates;

        public WordPressFixture()
        {
            _listener.Start();
            BaseUri = new Uri($"http://127.0.0.1:{((IPEndPoint)_listener.LocalEndpoint).Port}");
            _loop = AcceptAsync(_stop.Token);
        }

        public Uri BaseUri { get; }
        public IReadOnlyList<RecordedRequest> Requests { get { lock (_sync) return _requests.ToArray(); } }
        public int UpdateCount { get { lock (_sync) return _updates; } }
        public int FullSyncRequests => Requests.Count(r => r.Method == "GET" &&
            (r.Target.StartsWith("/wp-json/wp/v2/posts?context=edit&per_page=100", StringComparison.Ordinal) ||
             r.Target.StartsWith("/wp-json/wp/v2/pages?context=edit&per_page=100", StringComparison.Ordinal) ||
             r.Target.StartsWith("/wp-json/wp/v2/categories?context=edit&per_page=100", StringComparison.Ordinal) ||
             r.Target.StartsWith("/wp-json/wp/v2/tags?context=edit&per_page=100", StringComparison.Ordinal) ||
             r.Target.StartsWith("/wp-json/wp/v2/media?context=edit&per_page=100", StringComparison.Ordinal)));
        public bool HasAuthenticatedConnectionTest => Requests.Any(r =>
            r.Method == "GET" &&
            r.Target.StartsWith("/wp-json/wp/v2/users/me", StringComparison.Ordinal) &&
            r.Authorization.StartsWith("Basic ", StringComparison.Ordinal));

        public string ApplicationBaseUrl(IPage page) => new Uri(page.Url).GetLeftPart(UriPartial.Authority);

        public async ValueTask DisposeAsync()
        {
            _stop.Cancel();
            _listener.Stop();
            try { await _loop.WaitAsync(TimeSpan.FromSeconds(5)); } catch { }
            _stop.Dispose();
        }

        private async Task AcceptAsync(CancellationToken token)
        {
            while (!token.IsCancellationRequested)
            {
                try
                {
                    var client = await _listener.AcceptTcpClientAsync(token);
                    _ = HandleAsync(client, token);
                }
                catch (OperationCanceledException) when (token.IsCancellationRequested) { break; }
                catch (ObjectDisposedException) when (token.IsCancellationRequested) { break; }
            }
        }

        private async Task HandleAsync(TcpClient client, CancellationToken token)
        {
            using (client)
            await using (var stream = client.GetStream())
            {
                try
                {
                    var request = await ReadRequestAsync(stream, token);
                    lock (_sync) _requests.Add(request);
                    var response = Route(request);
                    await WriteResponseAsync(stream, response, token);
                }
                catch (Exception ex) when (ex is IOException or JsonException or OperationCanceledException)
                {
                    if (!token.IsCancellationRequested)
                    {
                        try { await WriteResponseAsync(stream, Json(500, new { error = ex.Message }), CancellationToken.None); }
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
            if (request.Method == "GET" && path == "/wp-json/wp/v2/users/me")
                return Json(200, new { id = 1 });

            if (request.Method == "GET" && path == "/wp-json/wp/v2/posts/9101")
            {
                lock (_sync) return Json(200, ToContent(_post));
            }
            if (request.Method == "GET" && path == "/wp-json/wp/v2/pages/9201")
            {
                lock (_sync) return Json(200, ToContent(_page));
            }
            if (request.Method == "POST" && path is "/wp-json/wp/v2/posts/9101" or "/wp-json/wp/v2/pages/9201")
            {
                using var json = JsonDocument.Parse(request.Body);
                var current = path.Contains("/pages/", StringComparison.Ordinal) ? _page : _post;
                var updated = Apply(current, json.RootElement);
                lock (_sync)
                {
                    if (updated.Type == "page") _page = updated; else _post = updated;
                    _updates++;
                }
                return Json(200, ToContent(updated));
            }

            if (request.Method == "GET" && path == "/wp-json/wp/v2/posts")
            {
                lock (_sync) return Paged(new object[] { ToContent(_post) });
            }
            if (request.Method == "GET" && path == "/wp-json/wp/v2/pages")
            {
                lock (_sync) return Paged(new object[] { ToContent(_page) });
            }
            if (request.Method == "GET" && path is "/wp-json/wp/v2/categories" or "/wp-json/wp/v2/tags" or "/wp-json/wp/v2/media")
                return Paged(Array.Empty<object>());

            return Json(404, new { code = "rest_no_route", message = $"No fixture route for {request.Method} {request.Target}" });
        }

        private static ContentState Apply(ContentState current, JsonElement root) => current with
        {
            Title = root.GetProperty("title").GetString() ?? string.Empty,
            Slug = root.GetProperty("slug").GetString() ?? string.Empty,
            Status = root.GetProperty("status").GetString() ?? "draft",
            Content = root.GetProperty("content").GetString() ?? string.Empty,
            Excerpt = root.GetProperty("excerpt").GetString() ?? string.Empty,
            ModifiedGmt = DateTimeOffset.Parse("2026-08-23T19:30:00Z", CultureInfo.InvariantCulture)
        };

        private object ToContent(ContentState item) => new
        {
            id = item.Id,
            date = "2026-08-23T18:00:00",
            date_gmt = "2026-08-23T18:00:00Z",
            modified = item.ModifiedGmt.ToString("O"),
            modified_gmt = item.ModifiedGmt.ToString("O"),
            slug = item.Slug,
            status = item.Status,
            type = item.Type,
            link = $"{BaseUri.ToString().TrimEnd('/')}/{item.Slug}/",
            title = new { rendered = item.Title, raw = item.Title },
            content = new { rendered = item.Content, raw = item.Content, protectedValue = false },
            excerpt = new { rendered = item.Excerpt, raw = item.Excerpt, protectedValue = false },
            author = 1,
            featured_media = 0,
            comment_status = "open",
            ping_status = "open",
            sticky = false,
            template = string.Empty,
            format = "standard",
            categories = item.Type == "post" ? new[] { 1 } : Array.Empty<int>(),
            tags = Array.Empty<int>(),
            password = string.Empty
        };

        private static Response Paged(Array items) => Json(200, items,
            new Dictionary<string, string>
            {
                ["X-WP-Total"] = items.Length.ToString(CultureInfo.InvariantCulture),
                ["X-WP-TotalPages"] = "1"
            });

        private static Response Json(int status, object value, IReadOnlyDictionary<string, string>? headers = null) =>
            new(status, JsonSerializer.Serialize(value), headers ?? new Dictionary<string, string>());

        private static async Task<RecordedRequest> ReadRequestAsync(NetworkStream stream, CancellationToken token)
        {
            var bytes = new List<byte>();
            var one = new byte[1];
            while (bytes.Count < 32768)
            {
                if (await stream.ReadAsync(one, token) == 0) throw new IOException("HTTP headers ended early.");
                bytes.Add(one[0]);
                var count = bytes.Count;
                if (count >= 4 && bytes[count - 4] == '\r' && bytes[count - 3] == '\n' && bytes[count - 2] == '\r' && bytes[count - 1] == '\n') break;
            }

            var lines = Encoding.ASCII.GetString(bytes.ToArray()).Split("\r\n", StringSplitOptions.None);
            var first = lines[0].Split(' ', 3);
            var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            foreach (var line in lines.Skip(1))
            {
                var colon = line.IndexOf(':');
                if (colon > 0) headers[line[..colon].Trim()] = line[(colon + 1)..].Trim();
            }

            var length = headers.TryGetValue("Content-Length", out var raw) && int.TryParse(raw, out var parsed) ? parsed : 0;
            var body = Encoding.UTF8.GetString(await ReadExactlyAsync(stream, length, token));
            return new RecordedRequest(first[0], first[1], body, headers.TryGetValue("Authorization", out var auth) ? auth : string.Empty);
        }

        private static async Task<byte[]> ReadExactlyAsync(NetworkStream stream, int length, CancellationToken token)
        {
            var bytes = new byte[length];
            var offset = 0;
            while (offset < length)
            {
                var read = await stream.ReadAsync(bytes.AsMemory(offset, length - offset), token);
                if (read == 0) throw new IOException("HTTP body ended early.");
                offset += read;
            }
            return bytes;
        }

        private static async Task WriteResponseAsync(NetworkStream stream, Response response, CancellationToken token)
        {
            var payload = Encoding.UTF8.GetBytes(response.Body);
            var reason = response.Status == 200 ? "OK" : response.Status == 404 ? "Not Found" : "Internal Server Error";
            var text = new StringBuilder()
                .Append($"HTTP/1.1 {response.Status} {reason}\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: {payload.Length}\r\nConnection: close\r\n");
            foreach (var header in response.Headers) text.Append($"{header.Key}: {header.Value}\r\n");
            text.Append("\r\n");
            await stream.WriteAsync(Encoding.ASCII.GetBytes(text.ToString()), token);
            await stream.WriteAsync(payload, token);
            await stream.FlushAsync(token);
        }

        public sealed record RecordedRequest(string Method, string Target, string Body, string Authorization);
        private sealed record ContentState(
            string Type,
            int Id,
            string Title,
            string Slug,
            string Status,
            string Content,
            string Excerpt,
            DateTimeOffset ModifiedGmt)
        {
            public static ContentState Create(string type, int id, string title, string slug) =>
                new(type, id, title, slug, "draft", $"<p>{title} content.</p>", $"{title} excerpt.",
                    DateTimeOffset.Parse("2026-08-23T18:00:00Z", CultureInfo.InvariantCulture));
        }
        private sealed record Response(int Status, string Body, IReadOnlyDictionary<string, string> Headers);
    }
}
