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
public sealed class CommentsModerationUxTests(UxTestHost host)
{
    private const string WordPressUser = "ux-wordpress-admin";
    private const string WordPressPassword = "UxWordPress123!";
    private const string CommentText = "Moderation fixture comment";
    private const string ReplyText = "Browser acceptance reply";

    [Fact]
    public async Task Comment_moderation_and_reply_reach_WordPress_REST_and_refresh_the_UI()
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

            var response = await page.GotoAsync(
                host.BaseUrl + $"/sites/{siteId}/comments",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);

            var comment = page.Locator(".comment-content").Filter(new LocatorFilterOptions { HasText = CommentText });
            await comment.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var row = comment.Locator("xpath=ancestor::tr[1]");
            (await row.InnerTextAsync()).Should().Contain("Pending");

            var approve = row.Locator(".comment-actions button").First;
            await ClickUntilAsync(
                () => approve.ClickAsync(new LocatorClickOptions { Timeout = 2000 }),
                () => wordpress.PrimaryStatus == "approved",
                "Approve did not reach the WordPress REST endpoint.");

            await WaitUntilAsync(async () =>
            {
                var current = page.Locator(".comment-content").Filter(new LocatorFilterOptions { HasText = CommentText });
                if (await current.CountAsync() == 0) return false;
                return (await current.Locator("xpath=ancestor::tr[1]").InnerTextAsync())
                    .Contains("Approved", StringComparison.OrdinalIgnoreCase);
            }, "The comments UI did not refresh to Approved.");

            wordpress.Requests.Should().Contain(request =>
                request.Method == "POST" &&
                request.Target.StartsWith("/wp-json/wp/v2/comments/7001", StringComparison.Ordinal) &&
                request.Authorization.StartsWith("Basic ", StringComparison.Ordinal) &&
                request.Body.Contains("\"status\":\"approved\"", StringComparison.OrdinalIgnoreCase));

            var approved = page.Locator(".comment-content").Filter(new LocatorFilterOptions { HasText = CommentText });
            var approvedRow = approved.Locator("xpath=ancestor::tr[1]");
            await approvedRow.Locator(".comment-actions button").Last.ClickAsync();

            var replyBox = approvedRow.Locator(".reply-box");
            await replyBox.Locator("textarea").FillAsync(ReplyText);
            await ClickUntilAsync(
                () => replyBox.Locator(".actions button").First.ClickAsync(new LocatorClickOptions { Timeout = 2000 }),
                () => wordpress.ReplyCount > 0,
                "Reply did not reach the WordPress REST endpoint.");

            wordpress.Requests.Should().Contain(request =>
                request.Method == "POST" &&
                request.Target == "/wp-json/wp/v2/comments" &&
                request.Authorization.StartsWith("Basic ", StringComparison.Ordinal) &&
                request.Body.Contains("\"post\":901", StringComparison.OrdinalIgnoreCase) &&
                request.Body.Contains("\"parent\":7001", StringComparison.OrdinalIgnoreCase) &&
                request.Body.Contains(ReplyText, StringComparison.Ordinal) &&
                request.Body.Contains("\"status\":\"approved\"", StringComparison.OrdinalIgnoreCase));

            await page.Locator(".comment-content")
                .Filter(new LocatorFilterOptions { HasText = ReplyText })
                .WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

            pageErrors.Should().BeEmpty("the real credential, moderation, and reply UI chain must not cause browser errors");
        }
        finally
        {
            await context.CloseBoundedAsync("comments-moderation");
        }
    }

    private async Task SaveCredentialsThroughUiAsync(IPage page, Guid siteId, WordPressFixture wordpress)
    {
        var response = await page.GotoAsync(
            host.BaseUrl + $"/sites/{siteId}",
            new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);

        var form = page.Locator(".site-details-form-grid");
        await form.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
        await form.Locator("input").Nth(0).FillAsync(WordPressUser);
        await form.Locator("input").Nth(1).FillAsync(WordPressPassword);

        var submit = page.Locator(".site-details-form-actions button[type='submit']").First;
        await ClickUntilAsync(
            () => submit.ClickAsync(new LocatorClickOptions { Timeout = 2000 }),
            () => wordpress.HasAuthenticatedConnectionTest,
            "Save/Test did not reach the WordPress connection tester.");

        await WaitUntilAsync(
            async () => await page.Locator(".site-details-alert.success").CountAsync() > 0,
            "The credential UI did not surface a successful connection result.");

        wordpress.Requests.Should().Contain(request => request.Method == "GET" && request.Target == "/wp-json/");
        wordpress.Requests.Should().Contain(request =>
            request.Method == "GET" &&
            request.Target.StartsWith("/wp-json/wp/v2/users/me", StringComparison.Ordinal) &&
            request.Authorization.StartsWith("Basic ", StringComparison.Ordinal));
    }

    private async Task<Guid> SeedOwnedSiteAsync(Uri siteUri)
    {
        await using var db = CreateDbContext();
        var admin = await db.AuthUsers.SingleAsync(user => user.NormalizedUserName == "ADMIN");
        var site = new Site("Comments REST UX site", siteUri, DateTime.UtcNow, admin.Id);
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

    private static async Task ClickUntilAsync(Func<Task> click, Func<bool> complete, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        Exception? lastError = null;
        while (DateTime.UtcNow < deadline)
        {
            if (complete()) return;
            try { await click(); }
            catch (Exception ex) when (ex is TimeoutException or PlaywrightException) { lastError = ex; }
            if (complete()) return;
            await Task.Delay(150);
        }
        throw new TimeoutException(failure, lastError);
    }

    private static async Task WaitUntilAsync(Func<Task<bool>> condition, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        Exception? lastError = null;
        while (DateTime.UtcNow < deadline)
        {
            try { if (await condition()) return; }
            catch (Exception ex) when (ex is PlaywrightException or InvalidOperationException) { lastError = ex; }
            await Task.Delay(150);
        }
        throw new TimeoutException(failure, lastError);
    }

    private sealed class WordPressFixture : IAsyncDisposable
    {
        private readonly TcpListener _listener = new(IPAddress.Loopback, 0);
        private readonly CancellationTokenSource _stop = new();
        private readonly object _sync = new();
        private readonly List<RecordedRequest> _requests = [];
        private readonly List<Comment> _replies = [];
        private readonly Task _loop;
        private string _primaryStatus = "hold";

        public WordPressFixture()
        {
            _listener.Start();
            BaseUri = new Uri($"http://127.0.0.1:{((IPEndPoint)_listener.LocalEndpoint).Port}");
            _loop = AcceptAsync(_stop.Token);
        }

        public Uri BaseUri { get; }
        public string PrimaryStatus { get { lock (_sync) return _primaryStatus; } }
        public int ReplyCount { get { lock (_sync) return _replies.Count; } }
        public IReadOnlyList<RecordedRequest> Requests { get { lock (_sync) return _requests.ToArray(); } }
        public bool HasAuthenticatedConnectionTest => Requests.Any(request =>
            request.Method == "GET" &&
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

            if (request.Method == "GET" && path == "/wp-json/wp/v2/users/me")
                return Json(200, new { id = 1 });

            if (request.Method == "GET" && path == "/wp-json/wp/v2/comments")
            {
                Comment[] comments;
                lock (_sync)
                    comments = [Primary(), .. _replies];
                return Json(200, comments.Select(ToWordPress).ToArray(), new Dictionary<string, string>
                {
                    ["X-WP-Total"] = comments.Length.ToString(CultureInfo.InvariantCulture),
                    ["X-WP-TotalPages"] = "1"
                });
            }

            if (request.Method == "POST" && path == "/wp-json/wp/v2/comments/7001")
            {
                using var body = JsonDocument.Parse(request.Body);
                lock (_sync) _primaryStatus = body.RootElement.GetProperty("status").GetString() ?? "hold";
                return Json(200, ToWordPress(Primary()));
            }

            if (request.Method == "POST" && path == "/wp-json/wp/v2/comments")
            {
                using var body = JsonDocument.Parse(request.Body);
                var reply = new Comment(
                    8001,
                    body.RootElement.GetProperty("post").GetInt32(),
                    body.RootElement.GetProperty("parent").GetInt32(),
                    "UX Admin",
                    "ux@example.test",
                    body.RootElement.GetProperty("content").GetString() ?? string.Empty,
                    "approved");
                lock (_sync) _replies.Add(reply);
                return Json(201, ToWordPress(reply));
            }

            return Json(404, new { code = "rest_no_route", message = $"No fixture route for {request.Method} {request.Target}" });
        }

        private Comment Primary()
        {
            lock (_sync) return new Comment(7001, 901, 0, "Fixture Author", "fixture@example.test", CommentText, _primaryStatus);
        }

        private object ToWordPress(Comment comment) => new
        {
            id = comment.Id,
            post = comment.Post,
            parent = comment.Parent,
            author_name = comment.Author,
            author_email = comment.Email,
            author_url = "https://example.test/author",
            content = new { rendered = $"<p>{WebUtility.HtmlEncode(comment.Content)}</p>" },
            status = comment.Status,
            link = $"{BaseUri.ToString().TrimEnd('/')}/?p={comment.Post}#comment-{comment.Id}",
            date_gmt = "2026-08-22T10:00:00",
            type = "comment"
        };

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

            return new RecordedRequest(
                requestLine[0], requestLine[1], body,
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
                if (size == 0) { await ReadLineAsync(stream, cancellationToken); break; }
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
        private sealed record Response(int Status, string Body, IReadOnlyDictionary<string, string> Headers);
        private sealed record Comment(int Id, int Post, int Parent, string Author, string Email, string Content, string Status);
    }
}
