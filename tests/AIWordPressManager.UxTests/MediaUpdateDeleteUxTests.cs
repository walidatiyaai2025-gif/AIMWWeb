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
    private const string User = "ux-wordpress-admin";
    private const string Password = "UxWordPress123!";
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
        var siteId = await SeedSiteAsync(wordpress.BaseUri);
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        try
        {
            var page = await context.NewPageAsync();
            var errors = new List<string>();
            page.PageError += (_, message) => errors.Add(message);
            await SaveCredentialsAsync(page, siteId, wordpress);

            var response = await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}/media",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);
            await RefreshInteractivelyAsync(page, wordpress);
            await ExactText(page, OriginalTitle).Last.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });

            var originalRow = ExactText(page, OriginalTitle).Last.Locator("xpath=ancestor::tr[1]");
            await originalRow.GetByRole(AriaRole.Button, new() { Name = "Optimize", Exact = true }).ClickAsync();

            var save = page.GetByRole(AriaRole.Button, new() { Name = "Save optimization", Exact = true });
            await save.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var editor = save.Locator("xpath=ancestor::*[.//div[contains(concat(' ', normalize-space(@class), ' '), ' form-grid ')]][1]");
            var form = editor.Locator(".form-grid");
            var inputs = form.Locator("input");
            inputs.Should().NotBeNull();
            await inputs.Nth(0).FillAsync(UpdatedTitle);
            await inputs.Nth(1).FillAsync(UpdatedSlug);
            await inputs.Nth(2).FillAsync(UpdatedAlt);
            var textareas = form.Locator("textarea");
            await textareas.Nth(0).FillAsync(UpdatedCaption);
            await textareas.Nth(1).FillAsync(UpdatedDescription);
            await save.ClickAsync();

            await WaitUntilAsync(() => wordpress.UpdateCount == 1, "Metadata save did not reach WordPress.");
            await WaitUntilAsync(() => wordpress.FullSyncRequests >= 10, "Metadata save did not complete WordPress reconciliation.");
            await ExactText(page, UpdatedTitle).Last.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 15000 });
            var update = wordpress.Requests.Single(r => r.Method == "POST" && r.Target == "/wp-json/wp/v2/media/8101");
            update.Authorization.Should().StartWith("Basic ");
            using (var document = JsonDocument.Parse(update.Body))
            {
                var root = document.RootElement;
                root.GetProperty("title").GetString().Should().Be(UpdatedTitle);
                root.GetProperty("slug").GetString().Should().Be(UpdatedSlug);
                root.GetProperty("alt_text").GetString().Should().Be(UpdatedAlt);
                root.GetProperty("caption").GetString().Should().Be(UpdatedCaption);
                root.GetProperty("description").GetString().Should().Be(UpdatedDescription);
            }

            var updatedRow = ExactText(page, UpdatedTitle).Last.Locator("xpath=ancestor::tr[1]");
            await updatedRow.GetByRole(AriaRole.Button, new() { Name = "Delete", Exact = true }).ClickAsync();
            var confirm = page.GetByRole(AriaRole.Button, new() { Name = "Delete permanently", Exact = true });
            await confirm.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 5000 });
            await confirm.ClickAsync();

            await WaitUntilAsync(() => wordpress.DeleteCount == 1, "Permanent delete did not reach WordPress.");
            await WaitUntilAsync(() => wordpress.FullSyncRequests >= 15, "Permanent delete did not complete WordPress reconciliation.");
            await ExactText(page, UpdatedTitle).Last.WaitForAsync(new() { State = WaitForSelectorState.Hidden, Timeout = 15000 });
            wordpress.Requests.Should().Contain(r => r.Method == "DELETE" &&
                r.Target == "/wp-json/wp/v2/media/8101?force=true" && r.Authorization.StartsWith("Basic ", StringComparison.Ordinal));
            wordpress.FullSyncRequests.Should().BeGreaterThanOrEqualTo(15);
            errors.Should().BeEmpty("real media mutations must not produce browser runtime errors");
        }
        finally { await context.CloseBoundedAsync("media-update-delete"); }
    }

    private static ILocator ExactText(IPage page, string text) => page.GetByText(text, new PageGetByTextOptions { Exact = true });

    private async Task SaveCredentialsAsync(IPage page, Guid siteId, WordPressFixture wordpress)
    {
        var response = await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}", new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
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
            catch (TimeoutException) { await page.WaitForTimeoutAsync(100); }
        }
        throw new TimeoutException("Site Details did not become interactive.");
    }

    private static async Task RefreshInteractivelyAsync(IPage page, WordPressFixture wordpress)
    {
        var refresh = page.Locator(".media-manager-workspace button.app-button[aria-label='Refresh']").First;
        var baseline = wordpress.FullSyncRequests;
        var deadline = DateTime.UtcNow.AddSeconds(8);
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await refresh.ClickAsync(new() { Timeout = 1500 });
                var sideEffectDeadline = DateTime.UtcNow.AddMilliseconds(1500);
                while (DateTime.UtcNow < sideEffectDeadline)
                {
                    if (wordpress.FullSyncRequests >= baseline + 5) return;
                    await page.WaitForTimeoutAsync(100);
                }
            }
            catch (TimeoutException) { await page.WaitForTimeoutAsync(100); }
        }
        throw new TimeoutException("Media Manager did not become interactive.");
    }

    private async Task<Guid> SeedSiteAsync(Uri siteUri)
    {
        await using var db = CreateDbContext();
        var admin = await db.AuthUsers.SingleAsync(u => u.NormalizedUserName == "ADMIN");
        var site = new Site("Media mutation UX site", siteUri, DateTime.UtcNow, admin.Id);
        db.Sites.Add(site);
        await db.SaveChangesAsync();
        return site.Id;
    }

    private AppDbContext CreateDbContext()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX database context factory.");
        return (AppDbContext)(method.Invoke(host, null) ?? throw new InvalidOperationException("UX database context factory returned null."));
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
        private MediaState? _media = new(8101, OriginalTitle, "existing-browser-media", "Original alt", "Original caption", "Original description", "2026-08-23T12:00:00");
        private int _updates;
        private int _deletes;

        public WordPressFixture()
        {
            _listener.Start();
            BaseUri = new Uri($"http://127.0.0.1:{((IPEndPoint)_listener.LocalEndpoint).Port}");
            _loop = AcceptAsync(_stop.Token);
        }

        public Uri BaseUri { get; }
        public IReadOnlyList<RecordedRequest> Requests { get { lock (_sync) return _requests.ToArray(); } }
        public int UpdateCount { get { lock (_sync) return _updates; } }
        public int DeleteCount { get { lock (_sync) return _deletes; } }
        public int FullSyncRequests => Requests.Count(r => r.Method == "GET" &&
            (r.Target.StartsWith("/wp-json/wp/v2/posts?context=edit&per_page=100", StringComparison.Ordinal) ||
             r.Target.StartsWith("/wp-json/wp/v2/pages?context=edit&per_page=100", StringComparison.Ordinal) ||
             r.Target.StartsWith("/wp-json/wp/v2/categories?context=edit&per_page=100", StringComparison.Ordinal) ||
             r.Target.StartsWith("/wp-json/wp/v2/tags?context=edit&per_page=100", StringComparison.Ordinal) ||
             r.Target.StartsWith("/wp-json/wp/v2/media?context=edit&per_page=100", StringComparison.Ordinal)));
        public bool HasAuthenticatedConnectionTest => Requests.Any(r => r.Method == "GET" && r.Target.StartsWith("/wp-json/wp/v2/users/me", StringComparison.Ordinal) && r.Authorization.StartsWith("Basic ", StringComparison.Ordinal));

        public async ValueTask DisposeAsync()
        {
            _stop.Cancel(); _listener.Stop();
            try { await _loop.WaitAsync(TimeSpan.FromSeconds(5)); } catch { }
            _stop.Dispose();
        }

        private async Task AcceptAsync(CancellationToken token)
        {
            while (!token.IsCancellationRequested)
            {
                try { var client = await _listener.AcceptTcpClientAsync(token); _ = HandleAsync(client, token); }
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
                        try { await WriteResponseAsync(stream, Json(500, new { error = ex.Message }), CancellationToken.None); } catch (IOException) { }
                }
            }
        }

        private Response Route(RecordedRequest request)
        {
            var q = request.Target.IndexOf('?');
            var path = q >= 0 ? request.Target[..q] : request.Target;
            if (request.Method == "GET" && path == "/wp-json/")
                return Json(200, new { name = "UX WordPress fixture", home = BaseUri.ToString().TrimEnd('/'), language = "en_US", site_icon_url = "" }, new() { ["X-WP-Version"] = "6.6-fixture" });
            if (request.Method == "GET" && path == "/wp-json/wp/v2/users/me") return Json(200, new { id = 1 });
            if (request.Method == "GET" && path == "/wp-json/wp/v2/media/8101")
            {
                lock (_sync) return _media is null ? Json(404, new { code = "rest_post_invalid_id" }) : Json(200, ToMedia(_media));
            }
            if (request.Method == "POST" && path == "/wp-json/wp/v2/media/8101")
            {
                using var json = JsonDocument.Parse(request.Body);
                var root = json.RootElement;
                lock (_sync)
                {
                    _media = new MediaState(8101, root.GetProperty("title").GetString() ?? "", root.GetProperty("slug").GetString() ?? "",
                        root.GetProperty("alt_text").GetString() ?? "", root.GetProperty("caption").GetString() ?? "", root.GetProperty("description").GetString() ?? "", "2026-08-23T12:05:00");
                    _updates++;
                    return Json(200, ToMedia(_media));
                }
            }
            if (request.Method == "DELETE" && request.Target == "/wp-json/wp/v2/media/8101?force=true")
            {
                lock (_sync) { var previous = _media; _media = null; _deletes++; return Json(200, new { deleted = true, previous = previous is null ? null : ToMedia(previous) }); }
            }
            if (request.Method == "GET" && path == "/wp-json/wp/v2/media")
            {
                lock (_sync) return Paged(_media is null ? Array.Empty<object>() : new[] { ToMedia(_media) });
            }
            if (request.Method == "GET" && path is "/wp-json/wp/v2/posts" or "/wp-json/wp/v2/pages" or "/wp-json/wp/v2/categories" or "/wp-json/wp/v2/tags") return Paged(Array.Empty<object>());
            return Json(404, new { code = "rest_no_route", message = $"No fixture route for {request.Method} {request.Target}" });
        }

        private object ToMedia(MediaState m) => new
        {
            id = m.Id, date = "2026-08-23T12:00:00", date_gmt = "2026-08-23T12:00:00", modified = m.Modified, modified_gmt = m.Modified,
            slug = m.Slug, status = "inherit", type = "attachment", link = $"{BaseUri.ToString().TrimEnd('/')}/uploads/browser-media.png",
            title = new { rendered = m.Title, raw = m.Title }, author = 1,
            description = new { rendered = m.Description, raw = m.Description }, caption = new { rendered = m.Caption, raw = m.Caption },
            alt_text = m.Alt, media_type = "image", mime_type = "image/png", media_details = new { width = 1200, height = 800, filesize = 4096 },
            source_url = $"{BaseUri.ToString().TrimEnd('/')}/uploads/browser-media.png"
        };

        private static Response Paged(Array items) => Json(200, items, new() { ["X-WP-Total"] = items.Length.ToString(CultureInfo.InvariantCulture), ["X-WP-TotalPages"] = "1" });
        private static Response Json(int status, object value, Dictionary<string, string>? headers = null) => new(status, JsonSerializer.Serialize(value), headers ?? new Dictionary<string, string>());

        private static async Task<RecordedRequest> ReadRequestAsync(NetworkStream stream, CancellationToken token)
        {
            var bytes = new List<byte>(); var one = new byte[1];
            while (bytes.Count < 32768)
            {
                if (await stream.ReadAsync(one, token) == 0) throw new IOException("HTTP headers ended early.");
                bytes.Add(one[0]); var n = bytes.Count;
                if (n >= 4 && bytes[n - 4] == '\r' && bytes[n - 3] == '\n' && bytes[n - 2] == '\r' && bytes[n - 1] == '\n') break;
            }
            var lines = Encoding.ASCII.GetString(bytes.ToArray()).Split("\r\n", StringSplitOptions.None);
            var first = lines[0].Split(' ', 3); var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            foreach (var line in lines.Skip(1)) { var colon = line.IndexOf(':'); if (colon > 0) headers[line[..colon].Trim()] = line[(colon + 1)..].Trim(); }
            var length = headers.TryGetValue("Content-Length", out var raw) && int.TryParse(raw, out var parsed) ? parsed : 0;
            var body = Encoding.UTF8.GetString(await ReadExactlyAsync(stream, length, token));
            return new RecordedRequest(first[0], first[1], body, headers.TryGetValue("Authorization", out var auth) ? auth : "");
        }

        private static async Task<byte[]> ReadExactlyAsync(NetworkStream stream, int length, CancellationToken token)
        {
            var bytes = new byte[length]; var offset = 0;
            while (offset < length) { var read = await stream.ReadAsync(bytes.AsMemory(offset, length - offset), token); if (read == 0) throw new IOException("HTTP body ended early."); offset += read; }
            return bytes;
        }

        private static async Task WriteResponseAsync(NetworkStream stream, Response response, CancellationToken token)
        {
            var payload = Encoding.UTF8.GetBytes(response.Body);
            var reason = response.Status == 200 ? "OK" : response.Status == 404 ? "Not Found" : "Internal Server Error";
            var text = new StringBuilder().Append($"HTTP/1.1 {response.Status} {reason}\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: {payload.Length}\r\nConnection: close\r\n");
            foreach (var header in response.Headers) text.Append($"{header.Key}: {header.Value}\r\n");
            text.Append("\r\n");
            await stream.WriteAsync(Encoding.ASCII.GetBytes(text.ToString()), token); await stream.WriteAsync(payload, token); await stream.FlushAsync(token);
        }

        public sealed record RecordedRequest(string Method, string Target, string Body, string Authorization);
        private sealed record MediaState(int Id, string Title, string Slug, string Alt, string Caption, string Description, string Modified);
        private sealed record Response(int Status, string Body, IReadOnlyDictionary<string, string> Headers);
    }
}
