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
public sealed class SynchronizationWorkspaceUxTests(UxTestHost host)
{
    private const string WordPressUser = "ux-sync-admin";
    private const string WordPressPassword = "UxSyncPassword123!";

    [Fact]
    public async Task Synchronization_success_reconciles_real_WordPress_data_requires_confirmation_and_survives_reload()
    {
        await using var wordpress = new WordPressFixture();
        var siteId = await SeedOwnedSiteAsync("Synchronization success UX site", wordpress.BaseUri);
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));

        try
        {
            var page = await context.NewPageAsync();
            var pageErrors = new List<string>();
            page.PageError += (_, message) => pageErrors.Add(message);

            await SaveCredentialsThroughUiAsync(page, siteId, wordpress);
            await NavigateToSynchronizationAsync(page, siteId);

            await StartSynchronizationAndWaitForBoundaryAsync(page, wordpress);
            await page.Locator(".alert.success").WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });
            (await page.Locator(".alert.success").InnerTextAsync()).Should().Contain("Synchronization completed:");
            (await page.Locator(".alert.success").InnerTextAsync()).Should().Contain("Downloaded records: 5");
            (await page.Locator("body").InnerTextAsync()).Should().Contain("Total cached records: 5");

            AssertFullSynchronizationBoundary(wordpress);
            await AssertPersistedSnapshotAsync(siteId, "Initial synchronization post", expectedRuns: 1, expectedStatuses: ["Completed"]);

            wordpress.SetRemotePostTitle("Remote WordPress title v2");
            var reviewRequestsBefore = wordpress.ContentRequestCount;
            await page.GetByRole(AriaRole.Button, new() { Name = "Review remote changes" }).ClickAsync();
            await WaitUntilAsync(
                () => wordpress.ContentRequestCount >= reviewRequestsBefore + 2,
                "Conflict review did not reach live WordPress posts/pages endpoints.");
            await page.GetByText("Local cache vs WordPress", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });
            (await page.Locator("body").InnerTextAsync()).Should().Contain("Remote WordPress title v2");

            var accept = page.GetByRole(AriaRole.Button, new() { Name = "Accept WordPress & force full sync" });
            await accept.ClickAsync();
            await page.GetByText("Accept the WordPress version?", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 5000
            });

            var requestsAtConfirmation = wordpress.ContentRequestCount;
            await page.GetByRole(AriaRole.Button, new() { Name = "Cancel", Exact = true }).ClickAsync();
            await page.WaitForTimeoutAsync(300);
            wordpress.ContentRequestCount.Should().Be(requestsAtConfirmation, "cancelling the confirmation must not start a synchronization");
            await AssertPersistedSnapshotAsync(siteId, "Initial synchronization post", expectedRuns: 1, expectedStatuses: ["Completed"]);

            await accept.ClickAsync();
            await page.GetByText("Accept the WordPress version?", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 5000
            });
            var requestsBeforeForcedSync = wordpress.ContentRequestCount;
            await page.GetByRole(AriaRole.Button, new() { Name = "Accept & force full sync", Exact = true }).ClickAsync();
            await WaitUntilAsync(
                () => wordpress.ContentRequestCount >= requestsBeforeForcedSync + 5,
                "Confirmed force-full synchronization did not reach every WordPress content endpoint.");
            await WaitUntilAsync(
                async () => (await page.Locator(".alert.success").CountAsync()) > 0 &&
                            (await page.Locator(".alert.success").InnerTextAsync()).Contains("Forced synchronization completed:", StringComparison.Ordinal),
                "The browser did not reconcile the confirmed forced synchronization as a real success.");

            await AssertPersistedSnapshotAsync(siteId, "Remote WordPress title v2", expectedRuns: 2, expectedStatuses: ["Completed", "Completed"]);

            await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}/explorer", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByText("Remote WordPress title v2", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });

            await NavigateToSynchronizationAsync(page, siteId);
            await page.ReloadAsync(new PageReloadOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            await page.GetByText("Recent synchronization runs", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });
            var reloadedBody = await page.Locator("body").InnerTextAsync();
            reloadedBody.Should().Contain("Total cached records: 5");
            reloadedBody.Split("Completed", StringSplitOptions.None).Length.Should().BeGreaterThanOrEqualTo(3, "two completed runs must remain visible after reload");
            pageErrors.Should().BeEmpty("the synchronization browser path must not emit page errors");
        }
        finally
        {
            await context.CloseBoundedAsync("sync-success-confirm-reload");
        }
    }

    [Fact]
    public async Task Synchronization_failure_never_shows_success_persists_failed_history_and_real_retry_recovers()
    {
        await using var wordpress = new WordPressFixture { FailSynchronizationRequests = true };
        var siteId = await SeedOwnedSiteAsync("Synchronization failure UX site", wordpress.BaseUri);
        var context = await host.CreateContextAsync(new UxViewport("desktop", 1440, 900));

        try
        {
            var page = await context.NewPageAsync();
            await SaveCredentialsThroughUiAsync(page, siteId, wordpress);
            await NavigateToSynchronizationAsync(page, siteId);

            await StartSynchronizationAndWaitForBoundaryAsync(page, wordpress);
            await page.Locator(".alert.error").WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });
            var failureText = await page.Locator(".alert.error").InnerTextAsync();
            failureText.Should().Contain("WordPress request to /wp-json/wp/v2/posts failed");
            (await page.Locator(".alert.success").CountAsync()).Should().Be(0, "a failed WordPress boundary must never be represented as success");
            (await page.Locator("body").InnerTextAsync()).Should().Contain("Failed");

            await using (var db = CreateDbContext())
            {
                (await db.WordPressContentRecords.CountAsync(x => x.SiteId == siteId)).Should().Be(0);
                (await db.WordPressMediaRecords.CountAsync(x => x.SiteId == siteId)).Should().Be(0);
                var runs = await db.Set<SiteSyncRun>().Where(x => x.SiteId == siteId).OrderBy(x => x.StartedAtUtc).ToListAsync();
                runs.Should().ContainSingle();
                runs[0].Status.Should().Be("Failed");
                runs[0].DownloadedRecords.Should().Be(0);
            }

            wordpress.FailSynchronizationRequests = false;
            var requestsBeforeRetry = wordpress.ContentRequestCount;
            await page.GetByRole(AriaRole.Button, new() { Name = "Start synchronization" }).ClickAsync();
            await WaitUntilAsync(
                () => wordpress.ContentRequestCount >= requestsBeforeRetry + 5,
                "Retry did not execute a fresh real WordPress synchronization.");
            await WaitUntilAsync(
                async () => (await page.Locator(".alert.success").CountAsync()) > 0 &&
                            (await page.Locator(".alert.success").InnerTextAsync()).Contains("Synchronization completed:", StringComparison.Ordinal),
                "Successful retry did not reconcile into a browser success state.");

            await AssertPersistedSnapshotAsync(siteId, "Initial synchronization post", expectedRuns: 2, expectedStatuses: ["Failed", "Completed"]);
            var bodyAfterRetry = await page.Locator("body").InnerTextAsync();
            bodyAfterRetry.Should().Contain("Failed");
            bodyAfterRetry.Should().Contain("Completed");
            bodyAfterRetry.Should().Contain("Total cached records: 5");

            await page.ReloadAsync(new PageReloadOptions { WaitUntil = WaitUntilState.DOMContentLoaded, Timeout = 10000 });
            await page.GetByText("Recent synchronization runs", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });
            var reloadedBody = await page.Locator("body").InnerTextAsync();
            reloadedBody.Should().Contain("Failed");
            reloadedBody.Should().Contain("Completed");
            reloadedBody.Should().Contain("Total cached records: 5");
        }
        finally
        {
            await context.CloseBoundedAsync("sync-failure-retry");
        }
    }

    [Fact]
    public async Task Synchronization_route_requires_authentication_and_query_site_is_tenant_scoped()
    {
        var anonymous = await host.CreateContextAsync(new UxViewport("desktop", 1280, 800), authenticated: false);
        try
        {
            var page = await anonymous.NewPageAsync();
            await page.GotoAsync(host.BaseUrl + "/module/sync", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            page.Url.Should().Contain("/login", "the synchronization route must not render to an unauthenticated browser");
        }
        finally
        {
            await anonymous.CloseBoundedAsync("sync-anonymous-denial");
        }

        var adminSiteId = await SeedOwnedSiteAsync("Admin-only synchronization site", new Uri("https://admin-only.example.test"));
        var (viewerContext, viewerSiteId) = await host.CreateContentViewerContextAsync(new UxViewport("desktop", 1280, 800));
        try
        {
            var page = await viewerContext.NewPageAsync();
            await page.GotoAsync(host.BaseUrl + $"/module/sync?siteId={adminSiteId}", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = 10000
            });
            await page.GetByText("WordPress Synchronization Workspace", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 10000
            });

            var optionTexts = await page.Locator("select.form-control option").AllTextContentsAsync();
            optionTexts.Should().Contain("View-only UX site");
            optionTexts.Should().NotContain("Admin-only synchronization site", "another tenant owner's site must never be selectable");
            (await page.Locator("select.form-control").InputValueAsync()).Should().Be(Guid.Empty.ToString(), "an unowned siteId query must not become the selected execution target");
            (await page.GetByRole(AriaRole.Button, new() { Name = "Start synchronization" }).IsDisabledAsync()).Should().BeTrue();

            await page.Locator("select.form-control").SelectOptionAsync(viewerSiteId.ToString());
            (await page.Locator("select.form-control").InputValueAsync()).Should().Be(viewerSiteId.ToString());
        }
        finally
        {
            await viewerContext.CloseBoundedAsync("sync-tenant-isolation");
        }
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
        await page.GetByText("WordPress Synchronization Workspace", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions
        {
            State = WaitForSelectorState.Visible,
            Timeout = 10000
        });
        await page.GetByText("Recent synchronization runs", new() { Exact = true }).WaitForAsync(new LocatorWaitForOptions
        {
            State = WaitForSelectorState.Visible,
            Timeout = 10000
        });
    }

    private static async Task StartSynchronizationAndWaitForBoundaryAsync(IPage page, WordPressFixture wordpress)
    {
        var before = wordpress.ContentRequestCount;
        var start = page.GetByRole(AriaRole.Button, new() { Name = "Start synchronization" });
        var deadline = DateTime.UtcNow.AddSeconds(10);
        Exception? lastError = null;

        while (DateTime.UtcNow < deadline)
        {
            try
            {
                await start.ClickAsync(new LocatorClickOptions { Timeout = 1500 });
                if (await WaitBrieflyAsync(() => wordpress.ContentRequestCount > before))
                    return;
            }
            catch (Exception ex) when (ex is TimeoutException or PlaywrightException)
            {
                lastError = ex;
            }
            await Task.Delay(150);
        }

        throw new TimeoutException("Synchronization control never reached the WordPress boundary.", lastError);
    }

    private async Task SaveCredentialsThroughUiAsync(IPage page, Guid siteId, WordPressFixture wordpress)
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
        await EnsureSiteDetailsInteractiveAsync(page);

        await form.Locator("input").Nth(0).FillAsync(WordPressUser);
        await form.Locator("input").Nth(1).FillAsync(WordPressPassword);
        var submit = page.Locator(".site-details-form-actions button[type='submit']").First;
        await submit.ClickAsync(new LocatorClickOptions { Timeout = 5000 });

        await WaitUntilAsync(() => wordpress.HasAuthenticatedConnectionTest,
            "Credential save/test did not reach the real WordPress connection tester.");
        await page.Locator(".site-details-alert.success").WaitForAsync(new LocatorWaitForOptions
        {
            State = WaitForSelectorState.Visible,
            Timeout = 10000
        });
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
            catch (TimeoutException)
            {
                await page.WaitForTimeoutAsync(150);
            }
        }

        throw new TimeoutException("Site Details did not become InteractiveServer-ready within 8 seconds.");
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

    private async Task AssertPersistedSnapshotAsync(Guid siteId, string expectedPostTitle, int expectedRuns, IReadOnlyList<string> expectedStatuses)
    {
        await using var db = CreateDbContext();
        (await db.WordPressContentRecords.CountAsync(x => x.SiteId == siteId && x.IsAvailable)).Should().Be(2);
        (await db.WordPressCategoryRecords.CountAsync(x => x.SiteId == siteId && x.IsAvailable)).Should().Be(1);
        (await db.WordPressTagRecords.CountAsync(x => x.SiteId == siteId && x.IsAvailable)).Should().Be(1);
        (await db.WordPressMediaRecords.CountAsync(x => x.SiteId == siteId && x.IsAvailable)).Should().Be(1);
        (await db.WordPressContentRecords.SingleAsync(x => x.SiteId == siteId && x.ContentType == "post" && x.WordPressId == 101)).Title.Should().Be(expectedPostTitle);

        var runs = await db.Set<SiteSyncRun>()
            .Where(x => x.SiteId == siteId)
            .OrderBy(x => x.StartedAtUtc)
            .ToListAsync();
        runs.Should().HaveCount(expectedRuns);
        runs.Select(x => x.Status).Should().Equal(expectedStatuses);
    }

    private AppDbContext CreateDbContext()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic)
            ?? throw new InvalidOperationException("Could not resolve UX fixture database context factory.");
        return (AppDbContext)(method.Invoke(host, null)
            ?? throw new InvalidOperationException("UX fixture database context factory returned null."));
    }

    private static void AssertFullSynchronizationBoundary(WordPressFixture wordpress)
    {
        var required = new[]
        {
            "/wp-json/wp/v2/posts",
            "/wp-json/wp/v2/pages",
            "/wp-json/wp/v2/categories",
            "/wp-json/wp/v2/tags",
            "/wp-json/wp/v2/media"
        };

        foreach (var endpoint in required)
            wordpress.Requests.Should().Contain(request =>
                request.Method == "GET" &&
                request.Target.StartsWith(endpoint, StringComparison.Ordinal) &&
                request.Authorization.StartsWith("Basic ", StringComparison.Ordinal),
                $"synchronization must reach authenticated WordPress endpoint {endpoint}");
    }

    private static async Task<bool> WaitBrieflyAsync(Func<bool> condition)
    {
        var deadline = DateTime.UtcNow.AddMilliseconds(750);
        while (DateTime.UtcNow < deadline)
        {
            if (condition()) return true;
            await Task.Delay(50);
        }
        return condition();
    }

    private static async Task WaitUntilAsync(Func<bool> condition, string failure)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        while (DateTime.UtcNow < deadline)
        {
            if (condition()) return;
            await Task.Delay(100);
        }
        throw new TimeoutException(failure);
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
        private readonly Task _loop;
        private string _postTitle = "Initial synchronization post";

        public WordPressFixture()
        {
            _listener.Start();
            BaseUri = new Uri($"http://127.0.0.1:{((IPEndPoint)_listener.LocalEndpoint).Port}");
            _loop = AcceptAsync(_stop.Token);
        }

        public Uri BaseUri { get; }
        public bool FailSynchronizationRequests { get; set; }
        public IReadOnlyList<RecordedRequest> Requests { get { lock (_sync) return _requests.ToArray(); } }
        public int ContentRequestCount => Requests.Count(request => IsContentEndpoint(request.Target));
        public bool HasAuthenticatedConnectionTest => Requests.Any(request =>
            request.Method == "GET" &&
            request.Target.StartsWith("/wp-json/wp/v2/users/me", StringComparison.Ordinal) &&
            request.Authorization.StartsWith("Basic ", StringComparison.Ordinal));

        public void SetRemotePostTitle(string value)
        {
            lock (_sync) _postTitle = value;
        }

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
                return Json(200, new { name = "Synchronization UX WordPress fixture", home = BaseUri.ToString().TrimEnd('/'), language = "en_US", site_icon_url = "" },
                    new Dictionary<string, string> { ["X-WP-Version"] = "6.6-fixture" });

            if (request.Method == "GET" && path == "/wp-json/wp/v2/users/me")
                return Json(200, new { id = 1 });

            if (request.Method == "GET" && path == "/wp-json/wp/v2/posts")
            {
                if (FailSynchronizationRequests)
                    return Json(500, new { code = "ux_sync_failure", message = "Injected WordPress synchronization failure" });
                if (request.Target.Contains("modified_after=", StringComparison.Ordinal))
                    return Json(200, Array.Empty<object>(), TotalHeaders(1));
                string title;
                lock (_sync) title = _postTitle;
                return Json(200, new[] { ContentItem(101, title, "sync-post", "post") }, TotalHeaders(1));
            }

            if (request.Method == "GET" && path == "/wp-json/wp/v2/pages")
            {
                if (request.Target.Contains("modified_after=", StringComparison.Ordinal))
                    return Json(200, Array.Empty<object>(), TotalHeaders(0));
                return Json(200, new[] { ContentItem(201, "Synchronization page", "sync-page", "page") }, TotalHeaders(1));
            }

            if (request.Method == "GET" && path == "/wp-json/wp/v2/categories")
                return Json(200, new[] { new { id = 301, name = "Synchronization category", slug = "sync-category", count = 1 } }, TotalHeaders(1));

            if (request.Method == "GET" && path == "/wp-json/wp/v2/tags")
                return Json(200, new[] { new { id = 401, name = "Synchronization tag", slug = "sync-tag", count = 1 } }, TotalHeaders(1));

            if (request.Method == "GET" && path == "/wp-json/wp/v2/media")
            {
                if (request.Target.Contains("modified_after=", StringComparison.Ordinal))
                    return Json(200, Array.Empty<object>(), TotalHeaders(0));
                return Json(200, new[]
                {
                    new
                    {
                        id = 501,
                        title = new { rendered = "Synchronization media" },
                        slug = "sync-media",
                        media_type = "image",
                        mime_type = "image/jpeg",
                        source_url = BaseUri.ToString().TrimEnd('/') + "/media/sync.jpg",
                        modified_gmt = "2026-08-24T08:00:00"
                    }
                }, TotalHeaders(1));
            }

            return Json(404, new { code = "rest_no_route", message = $"No fixture route for {request.Method} {request.Target}" });
        }

        private object ContentItem(int id, string title, string slug, string type) => new
        {
            id,
            title = new { rendered = title },
            slug,
            status = "publish",
            link = BaseUri.ToString().TrimEnd('/') + $"/{slug}/",
            modified_gmt = "2026-08-24T08:00:00",
            content = new { rendered = $"<p>{WebUtility.HtmlEncode(type)} synchronization fixture content</p>" },
            excerpt = new { rendered = $"<p>{WebUtility.HtmlEncode(type)} synchronization fixture excerpt</p>" }
        };

        private static bool IsContentEndpoint(string target) =>
            target.StartsWith("/wp-json/wp/v2/posts", StringComparison.Ordinal) ||
            target.StartsWith("/wp-json/wp/v2/pages", StringComparison.Ordinal) ||
            target.StartsWith("/wp-json/wp/v2/categories", StringComparison.Ordinal) ||
            target.StartsWith("/wp-json/wp/v2/tags", StringComparison.Ordinal) ||
            target.StartsWith("/wp-json/wp/v2/media", StringComparison.Ordinal);

        private static IReadOnlyDictionary<string, string> TotalHeaders(int total) => new Dictionary<string, string>
        {
            ["X-WP-Total"] = total.ToString(CultureInfo.InvariantCulture),
            ["X-WP-TotalPages"] = total == 0 ? "0" : "1"
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
            var reason = status switch { 200 => "OK", 201 => "Created", 404 => "Not Found", 500 => "Internal Server Error", _ => "Error" };
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
    }
}
