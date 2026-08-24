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
public sealed class ApprovalExecutionUxTests(UxTestHost host)
{
    private const string User = "ux-wordpress-admin";
    private const string Password = "UxWordPress123!";
    private const string ApprovedTitle = "Approved browser post";
    private const string ApprovedSlug = "approved-browser-post";

    [Fact]
    public async Task Approve_and_execute_reaches_WordPress_worker_and_reconciles_to_Executed_UI()
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

            var editorResponse = await page.GotoAsync(
                host.BaseUrl + $"/sites/{siteId}/content/post/9301/edit",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            editorResponse.Should().NotBeNull();
            editorResponse!.Status.Should().BeLessThan(400);
            await page.Locator(".editor-title-input").WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 15000 });
            await EnsureEditorInteractiveAsync(page);

            await page.Locator(".editor-title-input").FillAsync(ApprovedTitle);
            await page.Locator(".editor-slug-row input").FillAsync(ApprovedSlug);
            await page.Locator(".content-editor-pro").FillAsync("<p>Approved execution persisted through the hosted worker.</p>");
            await page.Locator(".excerpt-editor-pro").FillAsync("Approved execution browser acceptance excerpt.");

            await page.GetByRole(AriaRole.Button, new() { Name = "Send for approval", Exact = true }).ClickAsync();
            await page.GetByText("Change submitted for approval.", new PageGetByTextOptions { Exact = false })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            wordpress.UpdateCount.Should().Be(0, "submitting an approval must not mutate WordPress before approval");

            var queueResponse = await page.GotoAsync(host.BaseUrl + "/approvals",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            queueResponse.Should().NotBeNull();
            queueResponse!.Status.Should().BeLessThan(400);

            var approvalTitle = $"Update post #9301 — {ApprovedTitle}";
            var cardTitle = page.GetByText(approvalTitle, new PageGetByTextOptions { Exact = true });
            await cardTitle.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var card = cardTitle.Locator("xpath=ancestor::article[contains(@class,'approval-card')][1]");
            await EnsureApprovalQueueInteractiveAsync(page, card);

            var execute = card.Locator("input[type='checkbox']");
            (await execute.IsCheckedAsync()).Should().BeTrue("executable WordPress content approvals default to real execution");
            await card.Locator("button.btn.primary").First.ClickAsync();

            await WaitUntilAsync(() => wordpress.UpdateCount == 1, "Approved worker did not reach the WordPress mutation endpoint.");
            var update = wordpress.Requests.Single(r => r.Method == "POST" && r.Target == "/wp-json/wp/v2/posts/9301");
            update.Authorization.Should().StartWith("Basic ");
            using (var document = JsonDocument.Parse(update.Body))
            {
                var root = document.RootElement;
                root.GetProperty("title").GetString().Should().Be(ApprovedTitle);
                root.GetProperty("slug").GetString().Should().Be(ApprovedSlug);
                root.GetProperty("content").GetString().Should().Contain("hosted worker");
            }

            await WaitUntilAsync(() => wordpress.FullSyncRequests >= 5, "Approved worker did not reconcile WordPress after mutation.");
            await page.GetByRole(AriaRole.Button, new() { Name = "Refresh", Exact = true }).ClickAsync();
            await card.GetByText("Executed", new LocatorGetByTextOptions { Exact = true })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
            await card.GetByText("Execution job", new LocatorGetByTextOptions { Exact = false })
                .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 5000 });
            errors.Should().BeEmpty("real approval execution must not produce browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("approval-execution");
        }
    }

    private static async Task EnsureEditorInteractiveAsync(IPage page)
    {
        var preview = page.Locator(".editor-view-toggle button").Nth(1);
        var edit = page.Locator(".editor-view-toggle button").Nth(0);
        var deadline = DateTime.UtcNow.AddSeconds(8);
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await preview.ClickAsync(new() { Timeout = 1500 });
                await page.Locator(".content-preview").WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 750 });
                await edit.ClickAsync(new() { Timeout = 1500 });
                await page.Locator(".content-editor-pro").WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 750 });
                return;
            }
            catch (TimeoutException) { await page.WaitForTimeoutAsync(100); }
        }
        throw new TimeoutException("Content Editor did not become interactive.");
    }

    private static async Task EnsureApprovalQueueInteractiveAsync(IPage page, ILocator card)
    {
        var toggle = card.Locator(".approval-card-title");
        var approve = card.Locator("button.btn.primary").First;
        var deadline = DateTime.UtcNow.AddSeconds(8);
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await toggle.ClickAsync(new() { Timeout = 1500 });
                await approve.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 750 });
                return;
            }
            catch (TimeoutException)
            {
                if (await approve.IsVisibleAsync()) return;
                await page.WaitForTimeoutAsync(100);
            }
        }
        throw new TimeoutException("Approval Queue did not become interactive.");
    }

    private async Task SaveCredentialsAsync(IPage page, Guid siteId, WordPressFixture wordpress)
    {
        var response = await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}",
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
        var credentialMetric = page.Locator(".site-details-metric").Filter(new LocatorFilterOptions { HasText = "Credentials" });
        await credentialMetric.GetByText("Saved", new LocatorGetByTextOptions { Exact = true })
            .WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10000 });
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

    private async Task<Guid> SeedSiteAsync(Uri siteUri)
    {
        await using var db = CreateDbContext();
        var admin = await db.AuthUsers.SingleAsync(u => u.NormalizedUserName == "ADMIN");
        var site = new Site("Approval execution UX site", siteUri, DateTime.UtcNow, admin.Id);
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
        var deadline = DateTime.UtcNow.AddSeconds(20);
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
        private ContentState _post = ContentState.Create();
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
        public bool HasAuthenticatedConnectionTest => Requests.Any(r => r.Method == "GET" &&
            r.Target.StartsWith("/wp-json/wp/v2/users/me", StringComparison.Ordinal) &&
            r.Authorization.StartsWith("Basic ", StringComparison.Ordinal));

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
                    await WriteResponseAsync(stream, Route(request), token);
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
            if (request.Method == "GET" && path == "/wp-json/wp/v2/users/me") return Json(200, new { id = 1 });
            if (request.Method == "GET" && path == "/wp-json/wp/v2/posts/9301")
            {
                lock (_sync) return Json(200, ToContent(_post));
            }
            if (request.Method == "POST" && path == "/wp-json/wp/v2/posts/9301")
            {
                using var json = JsonDocument.Parse(request.Body);
                lock (_sync)
                {
                    _post = Apply(_post, json.RootElement);
                    _updates++;
                    return Json(200, ToContent(_post));
                }
            }
            if (request.Method == "GET" && path == "/wp-json/wp/v2/posts")
            {
                lock (_sync) return Paged(new object[] { ToContent(_post) });
            }
            if (request.Method == "GET" && path is "/wp-json/wp/v2/pages" or "/wp-json/wp/v2/categories" or "/wp-json/wp/v2/tags" or "/wp-json/wp/v2/media")
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
            ModifiedGmt = DateTimeOffset.Parse("2026-08-24T00:30:00Z", CultureInfo.InvariantCulture)
        };

        private object ToContent(ContentState item) => new
        {
            id = 9301,
            date = "2026-08-23T23:00:00",
            date_gmt = "2026-08-23T23:00:00Z",
            modified = item.ModifiedGmt.ToString("O"),
            modified_gmt = item.ModifiedGmt.ToString("O"),
            slug = item.Slug,
            status = item.Status,
            type = "post",
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
            categories = new[] { 1 },
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

            var body = headers.TryGetValue("Transfer-Encoding", out var transfer) && transfer.Contains("chunked", StringComparison.OrdinalIgnoreCase)
                ? await ReadChunkedAsync(stream, token)
                : Encoding.UTF8.GetString(await ReadExactlyAsync(stream,
                    headers.TryGetValue("Content-Length", out var raw) && int.TryParse(raw, out var length) ? length : 0,
                    token));
            return new RecordedRequest(first[0], first[1], body, headers.TryGetValue("Authorization", out var auth) ? auth : string.Empty);
        }

        private static async Task<string> ReadChunkedAsync(NetworkStream stream, CancellationToken token)
        {
            using var output = new MemoryStream();
            while (true)
            {
                var sizeLine = await ReadLineAsync(stream, token);
                var semicolon = sizeLine.IndexOf(';');
                if (semicolon >= 0) sizeLine = sizeLine[..semicolon];
                var size = int.Parse(sizeLine, NumberStyles.HexNumber, CultureInfo.InvariantCulture);
                if (size == 0)
                {
                    await ReadLineAsync(stream, token);
                    break;
                }

                var chunk = await ReadExactlyAsync(stream, size, token);
                await output.WriteAsync(chunk, token);
                await ReadExactlyAsync(stream, 2, token);
            }
            return Encoding.UTF8.GetString(output.ToArray());
        }

        private static async Task<string> ReadLineAsync(NetworkStream stream, CancellationToken token)
        {
            var bytes = new List<byte>();
            var one = new byte[1];
            while (true)
            {
                if (await stream.ReadAsync(one, token) == 0) throw new IOException("HTTP line ended early.");
                if (one[0] == '\n') break;
                if (one[0] != '\r') bytes.Add(one[0]);
            }
            return Encoding.ASCII.GetString(bytes.ToArray());
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
            var text = new StringBuilder().Append($"HTTP/1.1 {response.Status} {reason}\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: {payload.Length}\r\nConnection: close\r\n");
            foreach (var header in response.Headers) text.Append($"{header.Key}: {header.Value}\r\n");
            text.Append("\r\n");
            await stream.WriteAsync(Encoding.ASCII.GetBytes(text.ToString()), token);
            await stream.WriteAsync(payload, token);
            await stream.FlushAsync(token);
        }

        public sealed record RecordedRequest(string Method, string Target, string Body, string Authorization);
        private sealed record Response(int Status, string Body, IReadOnlyDictionary<string, string> Headers);
        private sealed record ContentState(string Title, string Slug, string Status, string Content, string Excerpt, DateTimeOffset ModifiedGmt)
        {
            public static ContentState Create() => new(
                "Existing approval post",
                "existing-approval-post",
                "draft",
                "<p>Existing approval content.</p>",
                "Existing approval excerpt.",
                DateTimeOffset.Parse("2026-08-23T23:30:00Z", CultureInfo.InvariantCulture));
        }
    }
}
