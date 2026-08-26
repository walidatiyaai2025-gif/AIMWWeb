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
public sealed class SynchronizationWorkspaceUxTestsDualFailure(UxTestHost host)
{
    private const string WordPressUser = "ux-sync-dual-admin";
    private const string WordPressPassword = "UxSyncDualPassword123!";

    [Fact]
    public async Task Primary_synchronization_failure_remains_visible_when_history_refresh_also_fails()
    {
        await using var wordpress = new HeldFailureWordPressFixture();
        var siteId = await SeedOwnedSiteAsync("Synchronization dual-failure UX site", wordpress.BaseUri);
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));
        string? tableName = null;
        string? backupTableName = null;

        try
        {
            var page = await context.NewPageAsync();
            await SaveCredentialsThroughUiAsync(page, siteId, wordpress);
            await NavigateToSynchronizationAsync(page, siteId);

            var start = page.GetByRole(AriaRole.Button, new() { Name = "Start synchronization" });
            await ClickWhenInteractiveAsync(start);
            await wordpress.WaitForHeldFailureRequestAsync();

            await using (var db = CreateDbContext())
            {
                var entity = db.Model.FindEntityType(typeof(SiteSyncRun))
                    ?? throw new InvalidOperationException("SiteSyncRun EF metadata is unavailable.");
                tableName = entity.GetTableName()
                    ?? throw new InvalidOperationException("SiteSyncRun table name is unavailable.");
                backupTableName = tableName + "_ux_dual_failure";
                await db.Database.ExecuteSqlRawAsync(
                    $"ALTER TABLE {QuoteIdentifier(tableName)} RENAME TO {QuoteIdentifier(backupTableName)}");
            }

            wordpress.ReleaseFailureResponse();

            await WaitUntilAsync(async () =>
            {
                var error = page.Locator(".alert.error");
                if (await error.CountAsync() == 0) return false;
                var text = await error.InnerTextAsync();
                return text.Contains("WordPress request to /wp-json/wp/v2/posts failed", StringComparison.Ordinal)
                    && text.Contains("Synchronization history could not be refreshed", StringComparison.Ordinal);
            }, "The browser did not preserve the primary synchronization error and append the secondary history-refresh error.");

            var failureText = await page.Locator(".alert.error").InnerTextAsync();
            var primaryIndex = failureText.IndexOf("WordPress request to /wp-json/wp/v2/posts failed", StringComparison.Ordinal);
            var secondaryIndex = failureText.IndexOf("Synchronization history could not be refreshed", StringComparison.Ordinal);
            primaryIndex.Should().BeGreaterThanOrEqualTo(0);
            secondaryIndex.Should().BeGreaterThan(primaryIndex, "the primary synchronization failure must remain the leading error");
            (await page.Locator(".alert.success").CountAsync()).Should().Be(0, "dual failure must never be represented as success");
        }
        finally
        {
            wordpress.ReleaseFailureResponse();
            if (!string.IsNullOrWhiteSpace(tableName) && !string.IsNullOrWhiteSpace(backupTableName))
            {
                await using var restoreDb = CreateDbContext();
                try
                {
                    await restoreDb.Database.ExecuteSqlRawAsync(
                        $"ALTER TABLE {QuoteIdentifier(backupTableName)} RENAME TO {QuoteIdentifier(tableName)}");
                }
                catch (Exception restoreException)
                {
                    throw new InvalidOperationException("Dual-failure UX test could not restore the SiteSyncRun table after fault injection.", restoreException);
                }
            }

            await context.CloseBoundedAsync("sync-primary-secondary-dual-failure");
        }
    }

    private async Task<Guid> SeedOwnedSiteAsync(string name, Uri siteUri)
    {
        await using var db = CreateDbContext();
        var admin = await db.AuthUsers.SingleAsync(user => user.NormalizedUserName == "ADMIN");
        var site = new Site(name, siteUri, DateTime.UtcNow, admin.Id);
        db.Sites.Add(site);
        await db.SaveChangesAsync();
        return site.Id;
    }

    private async Task SaveCredentialsThroughUiAsync(IPage page, Guid siteId, HeldFailureWordPressFixture wordpress)
    {
        var response = await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}", new PageGotoOptions
        {
            WaitUntil = WaitUntilState.DOMContentLoaded,
            Timeout = 10000
        });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);

        var form = page.Locator(".site-details-form-grid");
        await form.WaitForAsync(new LocatorWaitForOptions { State = WaitForSelectorState.Visible, Timeout = 10000 });
        var probe = page.Locator(".site-details-form-actions button[type='button']").First;
        await ClickWhenInteractiveAsync(probe);

        await form.Locator("input").Nth(0).FillAsync(WordPressUser);
        await form.Locator("input").Nth(1).FillAsync(WordPressPassword);
        await page.Locator(".site-details-form-actions button[type='submit']").First.ClickAsync();

        await WaitUntilAsync(
            () => Task.FromResult(wordpress.HasAuthenticatedConnectionTest),
            "Credential save/test did not reach the real WordPress connection tester.");
        await page.Locator(".site-details-alert.success").WaitForAsync(new LocatorWaitForOptions
        {
            State = WaitForSelectorState.Visible,
            Timeout = 10000
        });
    }

    private async Task NavigateToSynchronizationAsync(IPage page, Guid siteId)
    {
        var response = await page.GotoAsync(host.BaseUrl + $"/module/sync?siteId={siteId}", new PageGotoOptions
        {
            WaitUntil = WaitUntilState.DOMContentLoaded,
            Timeout = 10000
        });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);
        await page.GetByText("Recent synchronization runs", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions
        {
            State = WaitForSelectorState.Visible,
            Timeout = 10000
        });
    }

    private static async Task ClickWhenInteractiveAsync(ILocator locator)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        Exception? lastError = null;
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await locator.ClickAsync(new LocatorClickOptions { Timeout = 1500 });
                return;
            }
            catch (Exception ex) when (ex is TimeoutException or PlaywrightException)
            {
                lastError = ex;
                await Task.Delay(100);
            }
        }
        throw new TimeoutException("InteractiveServer control did not become clickable.", lastError);
    }

    private AppDbContext CreateDbContext()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX fixture database context factory.");
        return (AppDbContext)(method.Invoke(host, null)
            ?? throw new InvalidOperationException("UX fixture database context factory returned null."));
    }

    private static string QuoteIdentifier(string value) => $"\"{value.Replace("\"", "\"\"")}\"";

    private static async Task WaitUntilAsync(Func<Task<bool>> condition, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        Exception? lastError = null;
        while (DateTime.UtcNow < deadline)
        {
            try { if (await condition()) return; }
            catch (Exception ex) when (ex is PlaywrightException or InvalidOperationException) { lastError = ex; }
            await Task.Delay(100);
        }
        throw new TimeoutException(failure, lastError);
    }

    private sealed class HeldFailureWordPressFixture : IAsyncDisposable
    {
        private readonly TcpListener _listener = new(IPAddress.Loopback, 0);
        private readonly CancellationTokenSource _stop = new();
        private readonly object _sync = new();
        private readonly List<RecordedRequest> _requests = [];
        private readonly TaskCompletionSource _postsFailureObserved = new(TaskCreationOptions.RunContinuationsAsynchronously);
        private readonly TaskCompletionSource _releaseFailure = new(TaskCreationOptions.RunContinuationsAsynchronously);
        private readonly Task _loop;

        public HeldFailureWordPressFixture()
        {
            _listener.Start();
            BaseUri = new Uri($"http://127.0.0.1:{((IPEndPoint)_listener.LocalEndpoint).Port}");
            _loop = AcceptAsync(_stop.Token);
        }

        public Uri BaseUri { get; }
        public bool HasAuthenticatedConnectionTest
        {
            get
            {
                lock (_sync)
                    return _requests.Any(request => request.Target.StartsWith("/wp-json/wp/v2/users/me", StringComparison.Ordinal)
                        && request.Authorization.StartsWith("Basic ", StringComparison.Ordinal));
            }
        }

        public Task WaitForHeldFailureRequestAsync() => _postsFailureObserved.Task.WaitAsync(TimeSpan.FromSeconds(10));
        public void ReleaseFailureResponse() => _releaseFailure.TrySetResult();

        public async ValueTask DisposeAsync()
        {
            ReleaseFailureResponse();
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
                var request = await ReadRequestAsync(stream, cancellationToken);
                lock (_sync) _requests.Add(request);
                var path = request.Target.Split('?', 2)[0];

                if (path == "/wp-json/wp/v2/posts")
                {
                    _postsFailureObserved.TrySetResult();
                    await _releaseFailure.Task.WaitAsync(cancellationToken);
                    await WriteJsonAsync(stream, 500, new { code = "ux_sync_dual_failure", message = "Injected primary WordPress synchronization failure" }, null, cancellationToken);
                    return;
                }

                if (path == "/wp-json/")
                {
                    await WriteJsonAsync(stream, 200, new { name = "Dual failure UX fixture", home = BaseUri.ToString().TrimEnd('/'), language = "en_US", site_icon_url = "" }, new Dictionary<string, string> { ["X-WP-Version"] = "6.6-fixture" }, cancellationToken);
                    return;
                }

                if (path == "/wp-json/wp/v2/users/me")
                {
                    await WriteJsonAsync(stream, 200, new { id = 1 }, null, cancellationToken);
                    return;
                }

                if (path is "/wp-json/wp/v2/pages" or "/wp-json/wp/v2/categories" or "/wp-json/wp/v2/tags" or "/wp-json/wp/v2/media")
                {
                    await WriteJsonAsync(stream, 200, Array.Empty<object>(), new Dictionary<string, string> { ["X-WP-Total"] = "0", ["X-WP-TotalPages"] = "0" }, cancellationToken);
                    return;
                }

                await WriteJsonAsync(stream, 404, new { code = "rest_no_route", message = request.Target }, null, cancellationToken);
            }
        }

        private static async Task<RecordedRequest> ReadRequestAsync(NetworkStream stream, CancellationToken cancellationToken)
        {
            var bytes = new List<byte>();
            var one = new byte[1];
            while (bytes.Count < 32768)
            {
                if (await stream.ReadAsync(one, cancellationToken) == 0) throw new IOException("HTTP headers ended early.");
                bytes.Add(one[0]);
                var n = bytes.Count;
                if (n >= 4 && bytes[n - 4] == '\r' && bytes[n - 3] == '\n' && bytes[n - 2] == '\r' && bytes[n - 1] == '\n') break;
            }

            var lines = Encoding.ASCII.GetString(bytes.ToArray()).Split("\r\n", StringSplitOptions.None);
            var requestLine = lines[0].Split(' ', 3);
            var authorization = lines.FirstOrDefault(line => line.StartsWith("Authorization:", StringComparison.OrdinalIgnoreCase));
            return new RecordedRequest(requestLine[0], requestLine[1], authorization is null ? string.Empty : authorization[(authorization.IndexOf(':') + 1)..].Trim());
        }

        private static async Task WriteJsonAsync(NetworkStream stream, int status, object value, IReadOnlyDictionary<string, string>? headers, CancellationToken cancellationToken)
        {
            var payload = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(value));
            var reason = status switch { 200 => "OK", 404 => "Not Found", 500 => "Internal Server Error", _ => "Error" };
            var response = new StringBuilder()
                .Append($"HTTP/1.1 {status} {reason}\r\n")
                .Append("Content-Type: application/json; charset=utf-8\r\n")
                .Append($"Content-Length: {payload.Length}\r\n")
                .Append("Connection: close\r\n");
            if (headers is not null)
                foreach (var header in headers) response.Append($"{header.Key}: {header.Value}\r\n");
            response.Append("\r\n");
            await stream.WriteAsync(Encoding.ASCII.GetBytes(response.ToString()), cancellationToken);
            await stream.WriteAsync(payload, cancellationToken);
            await stream.FlushAsync(cancellationToken);
        }

        private sealed record RecordedRequest(string Method, string Target, string Authorization);
    }
}
