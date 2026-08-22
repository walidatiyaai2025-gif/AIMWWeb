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
    private const string FixtureCommentText = "Moderation fixture comment";
    private const string FixtureReply = "Browser acceptance reply";

    [Fact]
    public async Task Comment_moderation_and_reply_reach_WordPress_REST_and_refresh_the_UI()
    {
        await using var wordpress = new WordPressCommentsFixtureServer();
        var siteId = await SeedOwnedSiteAsync(wordpress.BaseUri);
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));

        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            await SaveCredentialsThroughUiAsync(page, siteId, wordpress);

            var commentsResponse = await page.GotoAsync(
                host.BaseUrl + $"/sites/{siteId}/comments",
                new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });

            commentsResponse.Should().NotBeNull();
            commentsResponse!.Status.Should().BeLessThan(400);
            await page.Locator("#main-content").WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Attached,
                Timeout = 5000
            });

            var content = page.Locator(".comment-content").Filter(new LocatorFilterOptions { HasText = FixtureCommentText });
            await content.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
            var row = content.Locator("xpath=ancestor::tr[1]");
            (await row.InnerTextAsync()).Should().Contain("Pending");

            await ClickUntilAsync(
                async () => await row.GetByRole(AriaRole.Button, new() { Name = "Approve", Exact = true }).ClickAsync(new LocatorClickOptions { Timeout = 2000 }),
                () => wordpress.PrimaryCommentStatus == "approved",
                "Approve did not reach the WordPress comments REST endpoint.");

            await WaitUntilAsync(async () =>
            {
                var updated = page.Locator(".comment-content").Filter(new LocatorFilterOptions { HasText = FixtureCommentText });
                if (await updated.CountAsync() == 0) return false;
                var updatedRow = updated.Locator("xpath=ancestor::tr[1]");
                return (await updatedRow.InnerTextAsync()).Contains("Approved", StringComparison.OrdinalIgnoreCase);
            }, "The comments UI did not refresh to the approved status.");

            wordpress.Requests.Should().Contain(request =>
                request.Method == "POST" &&
                request.Target.StartsWith("/wp-json/wp/v2/comments/7001", StringComparison.Ordinal) &&
                request.Body.Contains("\"status\":\"approved\"", StringComparison.OrdinalIgnoreCase));

            var approvedContent = page.Locator(".comment-content").Filter(new LocatorFilterOptions { HasText = FixtureCommentText });
            var approvedRow = approvedContent.Locator("xpath=ancestor::tr[1]");
            await approvedRow.GetByRole(AriaRole.Button, new() { Name = "Reply", Exact = true }).ClickAsync();

            var replyEditor = approvedRow.Locator("textarea");
            await replyEditor.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 5000 });
            await replyEditor.FillAsync(FixtureReply);

            await ClickUntilAsync(
                async () => await approvedRow.GetByRole(AriaRole.Button, new() { Name = "Send Reply", Exact = true }).ClickAsync(new LocatorClickOptions { Timeout = 2000 }),
                () => wordpress.ReplyCount > 0,
                "Reply did not reach the WordPress comments REST endpoint.");

            wordpress.Requests.Should().Contain(request =>
                request.Method == "POST" &&
                request.Target == "/wp-json/wp/v2/comments" &&
                request.Body.Contains("\"post\":901", StringComparison.OrdinalIgnoreCase) &&
                request.Body.Contains("\"parent\":7001", StringComparison.OrdinalIgnoreCase) &&
                request.Body.Contains(FixtureReply, StringComparison.Ordinal) &&
                request.Body.Contains("\"status\":\"approved\"", StringComparison.OrdinalIgnoreCase));

            await page.GetByText(FixtureReply, new PageGetByTextOptions { Exact = true }).WaitForAsync(
                new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });

            pageErrors.Should().BeEmpty("real UI moderation and reply must not produce browser runtime errors");
        }
        finally
        {
            await context.CloseBoundedAsync("comments-moderation");
        }
    }

    private async Task SaveCredentialsThroughUiAsync(IPage page, Guid siteId, WordPressCommentsFixtureServer wordpress)
    {
        var response = await page.GotoAsync(
            host.BaseUrl + $"/sites/{siteId}",
            new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });

        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);
        await page.Locator("#main-content").WaitForAsync(new LocatorWaitForOptions
        {
            State = WaitForSelectorState.Attached,
            Timeout = 5000
        });

        var form = page.Locator(".site-details-form-grid");
        var userName = form.Locator("input").Nth(0);
        var password = form.Locator("input").Nth(1);
        await userName.FillAsync(WordPressUser);
        await password.FillAsync(WordPressPassword);

        await ClickUntilAsync(
            async () => await page.GetByRole(AriaRole.Button, new() { Name = "Save & Test", Exact = true }).ClickAsync(new LocatorClickOptions { Timeout = 2000 }),
            () => wordpress.HasAuthenticatedConnectionTest,
            "Save & Test did not reach the WordPress connection tester.");

        await WaitUntilAsync(async () => await page.Locator(".site-details-alert.success").CountAsync() > 0,
            "The credential UI did not surface a successful connection result.");

        wordpress.Requests.Should().Contain(request =>
            request.Method == "GET" && request.Target == "/wp-json/");
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
            ?? throw new InvalidOperationException("Could not resolve the UX fixture database context factory.");
        return (AppDbContext)(method.Invoke(host, null)
            ?? throw new InvalidOperationException("UX fixture database context factory returned null."));
    }

    private static async Task ClickUntilAsync(Func<Task> click, Func<bool> completed, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        Exception? lastError = null;
        while (DateTime.UtcNow < deadline)
        {
            if (completed()) return;
            try { await click(); }
            catch (Exception ex) when (ex is TimeoutException or PlaywrightException) { lastError = ex; }

            if (completed()) return;
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

    private sealed class WordPressCommentsFixtureServer : IAsyncDisposable
    {
        private readonly TcpListener _listener = new(IPAddress.Loopback, 0);
        private readonly CancellationTokenSource _shutdown = new();
        private readonly object _sync = new();
        private readonly List<FixtureRequest> _requests = [];
        private readonly List<FixtureComment> _replies = [];
        private readonly Task _acceptLoop;
        private string _primaryStatus = "hold";

        public WordPressCommentsFixtureServer()
        {
            _listener.Start();
            var port = ((IPEndPoint)_listener.LocalEndpoint).Port;
            BaseUri = new Uri($"http://127.0.0.1:{port}");
            _acceptLoop = AcceptLoopAsync(_shutdown.Token);
        }

        public Uri BaseUri { get; }
        public string PrimaryCommentStatus { get { lock (_sync) return _primaryStatus; } }
        public int ReplyCount { get { lock (_sync) return _replies.Count; } }
        public bool HasAuthenticatedConnectionTest => Requests.Any(request =>
            request.Method == "GET" &&
            request.Target.StartsWith("/wp-json/wp/v2/users/me", StringComparison.Ordinal) &&
            request.Authorization.StartsWith("Basic ", StringComparison.Ordinal));
        public IReadOnlyList<FixtureRequest> Requests { get { lock (_sync) return _requests.ToArray(); } }

        public async ValueTask DisposeAsync()
        {
            _shutdown.Cancel();
            _listener.Stop();
            try { await _acceptLoop.WaitAsync(TimeSpan.FromSeconds(5)); }
            catch (Exception) { }
            _shutdown.Dispose();
        }

        private async Task AcceptLoopAsync(CancellationToken ct)
        {
            while (!ct.IsCancellationRequested)
            {
                try
                {
                    var client = await _listener.AcceptTcpClientAsync(ct);
                    _ = HandleClientAsync(client, ct);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested) { break; }
                catch (ObjectDisposedException) when (ct.IsCancellationRequested) { break; }
            }
        }

        private async Task HandleClientAsync(TcpClient client, CancellationToken ct)
        {
            using (client)
            {
                await using var stream = client.GetStream();
                try
                {
                    var request = await ReadRequestAsync(stream, ct);
                    lock (_sync) _requests.Add(request);
                    var response = Route(request);
                    await WriteResponseAsync(stream, response.StatusCode, response.Body, response.Headers, ct);
                }
                catch (Exception ex) when (ex is IOException or OperationCanceledException or JsonException)
                {
                    if (!ct.IsCancellationRequested)
                    {
                        try
                        {
                            await WriteResponseAsync(stream, 500, JsonSerializer.Serialize(new { error = ex.Message }), null, CancellationToken.None);
                        }
                        catch (IOException) { }
                    }
                }
            }
        }

        private FixtureResponse Route(FixtureRequest request)
        {
            var queryIndex = request.Target.IndexOf('?');
            var path = queryIndex >= 0 ? request.Target[..queryIndex] : request.Target;
            if (request.Method == "GET" && path == "/wp-json/")
            {
                return Json(200, new
                {
                    name = "UX WordPress fixture",
                    home = BaseUri.ToString().TrimEnd('/'),
                    language = "en_US",
                    site_icon_url = ""
                }, new Dictionary<string, string> { ["X-WP-Version"] = "6.6-fixture" });
            }

            if (request.Method == "GET" && path == "/wp-json/wp/v2/users/me")
                return Json(200, new { id = 1 });

            if (request.Method == "GET" && path == "/wp-json/wp/v2/comments")
            {
                FixtureComment[] comments;
                lock (_sync)
                {
                    comments = [new FixtureComment(7001, 901, 0, "Fixture Author", "fixture@example.test", FixtureCommentText, _primaryStatus), .. _replies];
                }

                var headers = new Dictionary<string, string>
                {
                    ["X-WP-Total"] = comments.Length.ToString(),
                    ["X-WP-TotalPages"] = "1"
                };
                return Json(200, comments.Select(ToWordPressComment).ToArray(), headers);
            }

            if (request.Method == "POST" && path == "/wp-json/wp/v2/comments/7001")
            {
                using var body = JsonDocument.Parse(request.Body);
                var status = body.RootElement.GetProperty("status").GetString() ?? "hold";
                FixtureComment comment;
                lock (_sync)
                {
                    _primaryStatus = status;
                    comment = new FixtureComment(7001, 901, 0, "Fixture Author", "fixture@example.test", FixtureCommentText, _primaryStatus);
                }
                return Json(200, ToWordPressComment(comment));
            }

            if (request.Method == "POST" && path == "/wp-json/wp/v2/comments")
            {
                using var body = JsonDocument.Parse(request.Body);
                var content = body.RootElement.GetProperty("content").GetString() ?? string.Empty;
                var post = body.RootElement.GetProperty("post").GetInt32();
                var parent = body.RootElement.GetProperty("parent").GetInt32();
                FixtureComment reply;
                lock (_sync)
                {
                    reply = new FixtureComment(8000 + _replies.Count + 1, post, parent, "UX Admin", "ux@example.test", content, "approved");
                    _replies.Add(reply);
                }
                return Json(201, ToWordPressComment(reply));
            }

            if (request.Method == "DELETE" && path == "/wp-json/wp/v2/comments/7001")
                return Json(200, new { deleted = true });

            return Json(404, new { code = "rest_no_route", message = $"No fixture route for {request.Method} {request.Target}" });
        }

        private object ToWordPressComment(FixtureComment comment) => new
        {
            id = comment.Id,
            post = comment.PostId,
            parent = comment.ParentId,
            author_name = comment.AuthorName,
            author_email = comment.AuthorEmail,
            author_url = "https://example.test/author",
            content = new { rendered = $"<p>{WebUtility.HtmlEncode(comment.Content)}</p>" },
            status = comment.Status,
            link = $"{BaseUri.ToString().TrimEnd('/')}/?p={comment.PostId}#comment-{comment.Id}",
            date_gmt = "2026-08-22T10:00:00",
            type = "comment"
        };

        private static FixtureResponse Json(int statusCode, object value, IReadOnlyDictionary<string, string>? headers = null) =>
            new(statusCode, JsonSerializer.Serialize(value), headers ?? new Dictionary<string, string>());

        private static async Task<FixtureRequest> ReadRequestAsync(NetworkStream stream, CancellationToken ct)
        {
            var header = new List<byte>(1024);
            var single = new byte[1];
            while (header.Count < 32768)
            {
                var read = await stream.ReadAsync(single.AsMemory(0, 1), ct);
                if (read == 0) throw new IOException("Client closed before request headers completed.");
                header.Add(single[0]);
                var count = header.Count;
                if (count >= 4 && header[count - 4] == '\r' && header[count - 3] == '\n' && header[count - 2] == '\r' && header[count - 1] == '\n')
                    break;
            }

            var headerText = Encoding.ASCII.GetString(header.ToArray());
            var lines = headerText.Split("\r\n", StringSplitOptions.None);
            var requestLine = lines[0].Split(' ', 3);
            if (requestLine.Length < 2) throw new IOException("Invalid HTTP request line.");

            var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            foreach (var line in lines.Skip(1))
            {
                var separator = line.IndexOf(':');
                if (separator <= 0) continue;
                headers[line[..separator].Trim()] = line[(separator + 1)..].Trim();
            }

            string body;
            if (headers.TryGetValue("Transfer-Encoding", out var transferEncoding) &&
                transferEncoding.Contains("chunked", StringComparison.OrdinalIgnoreCase))
            {
                body = await ReadChunkedBodyAsync(stream, ct);
            }
            else
            {
                var contentLength = headers.TryGetValue("Content-Length", out var rawLength) && int.TryParse(rawLength, out var length)
                    ? length
                    : 0;
                var bodyBytes = await ReadExactlyAsync(stream, contentLength, ct);
                body = Encoding.UTF8.GetString(bodyBytes);
            }

            return new FixtureRequest(
                requestLine[0],
                requestLine[1],
                body,
                headers.TryGetValue("Authorization", out var authorization) ? authorization : string.Empty);
        }

        private static async Task<string> ReadChunkedBodyAsync(NetworkStream stream, CancellationToken ct)
        {
            using var body = new MemoryStream();
            while (true)
            {
                var sizeLine = await ReadAsciiLineAsync(stream, ct);
                var extension = sizeLine.IndexOf(';');
                if (extension >= 0) sizeLine = sizeLine[..extension];
                if (!int.TryParse(sizeLine.Trim(), System.Globalization.NumberStyles.HexNumber, null, out var size))
                    throw new IOException("Invalid HTTP chunk size.");
                if (size == 0)
                {
                    await ReadAsciiLineAsync(stream, ct);
                    break;
                }

                var chunk = await ReadExactlyAsync(stream, size, ct);
                await body.WriteAsync(chunk, ct);
                var terminator = await ReadExactlyAsync(stream, 2, ct);
                if (terminator[0] != '\r' || terminator[1] != '\n') throw new IOException("Invalid HTTP chunk terminator.");
            }
            return Encoding.UTF8.GetString(body.ToArray());
        }

        private static async Task<string> ReadAsciiLineAsync(NetworkStream stream, CancellationToken ct)
        {
            var bytes = new List<byte>();
            var single = new byte[1];
            while (bytes.Count < 8192)
            {
                var read = await stream.ReadAsync(single.AsMemory(0, 1), ct);
                if (read == 0) throw new IOException("Client closed while reading HTTP line.");
                if (single[0] == '\n') break;
                if (single[0] != '\r') bytes.Add(single[0]);
            }
            return Encoding.ASCII.GetString(bytes.ToArray());
        }

        private static async Task<byte[]> ReadExactlyAsync(NetworkStream stream, int length, CancellationToken ct)
        {
            if (length <= 0) return [];
            var bytes = new byte[length];
            var offset = 0;
            while (offset < bytes.Length)
            {
                var read = await stream.ReadAsync(bytes.AsMemory(offset), ct);
                if (read == 0) throw new IOException("Client closed before HTTP body completed.");
                offset += read;
            }
            return bytes;
        }

        private static async Task WriteResponseAsync(
            NetworkStream stream,
            int statusCode,
            string body,
            IReadOnlyDictionary<string, string>? headers,
            CancellationToken ct)
        {
            var bodyBytes = Encoding.UTF8.GetBytes(body);
            var reason = statusCode switch { 200 => "OK", 201 => "Created", 404 => "Not Found", _ => "Internal Server Error" };
            var builder = new StringBuilder()
                .Append("HTTP/1.1 ").Append(statusCode).Append(' ').Append(reason).Append("\r\n")
                .Append("Content-Type: application/json; charset=utf-8\r\n")
                .Append("Content-Length: ").Append(bodyBytes.Length).Append("\r\n")
                .Append("Connection: close\r\n");
            if (headers is not null)
                foreach (var pair in headers) builder.Append(pair.Key).Append(": ").Append(pair.Value).Append("\r\n");
            builder.Append("\r\n");

            await stream.WriteAsync(Encoding.ASCII.GetBytes(builder.ToString()), ct);
            await stream.WriteAsync(bodyBytes, ct);
            await stream.FlushAsync(ct);
        }

        public sealed record FixtureRequest(string Method, string Target, string Body, string Authorization);
        private sealed record FixtureResponse(int StatusCode, string Body, IReadOnlyDictionary<string, string> Headers);
        private sealed record FixtureComment(int Id, int PostId, int ParentId, string AuthorName, string AuthorEmail, string Content, string Status);
    }
}