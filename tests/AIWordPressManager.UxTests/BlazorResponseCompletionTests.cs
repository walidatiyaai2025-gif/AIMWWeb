using System.Diagnostics;
using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class BlazorResponseCompletionTests(UxTestHost host)
{
    private static readonly TimeSpan ResponseBodyTimeout = TimeSpan.FromSeconds(5);
    private static readonly TimeSpan BrowserDiagnosticWindow = TimeSpan.FromSeconds(3);

    [Fact]
    public async Task Blazor_bootstrap_asset_is_available_before_authentication_then_public_response_body_completes()
    {
        using var client = new HttpClient { Timeout = Timeout.InfiniteTimeSpan };
        using var timeout = new CancellationTokenSource(ResponseBodyTimeout);
        var stopwatch = Stopwatch.StartNew();

        try
        {
            using var response = await client.GetAsync(
                host.BaseUrl + "/welcome",
                HttpCompletionOption.ResponseContentRead,
                timeout.Token);

            stopwatch.Stop();
            var body = await response.Content.ReadAsByteArrayAsync(timeout.Token);
            Console.WriteLine(
                $"[UX-HTTP] /welcome complete status={(int)response.StatusCode} bytes={body.Length} elapsedMs={stopwatch.ElapsedMilliseconds}");

            response.IsSuccessStatusCode.Should().BeTrue("the public Razor Components response must complete successfully");
            body.Should().NotBeEmpty("the completed public response must contain rendered HTML");
        }
        catch (OperationCanceledException ex) when (timeout.IsCancellationRequested)
        {
            stopwatch.Stop();
            Console.WriteLine($"[UX-HTTP] /welcome body-timeout elapsedMs={stopwatch.ElapsedMilliseconds}");
            throw new TimeoutException(
                $"The /welcome Razor Components response returned headers/first bytes but did not complete its response body within {ResponseBodyTimeout.TotalSeconds:0}s. " +
                "This isolates the browser DOMContentLoaded failure to server-side response completion/streaming rather than a browser-only script wait.",
                ex);
        }
    }

    [Fact]
    public async Task Blazor_bootstrap_asset_is_available_before_authentication_then_browser_network_settles()
    {
        var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1], authenticated: false);
        try
        {
            var page = await context.NewPageAsync();
            var sync = new object();
            var pending = new Dictionary<string, string>(StringComparer.Ordinal);
            var failed = new List<string>();
            var console = new List<string>();
            var pageErrors = new List<string>();

            page.Request += (_, request) =>
            {
                lock (sync) pending[request.Url] = request.ResourceType;
                Console.WriteLine($"[UX-NET] request {request.ResourceType} {request.Url}");
            };
            page.RequestFinished += (_, request) =>
            {
                lock (sync) pending.Remove(request.Url);
                Console.WriteLine($"[UX-NET] finished {request.ResourceType} {request.Url}");
            };
            page.RequestFailed += (_, request) =>
            {
                lock (sync)
                {
                    pending.Remove(request.Url);
                    failed.Add($"{request.ResourceType} {request.Url} :: {request.Failure}");
                }
                Console.WriteLine($"[UX-NET] failed {request.ResourceType} {request.Url} :: {request.Failure}");
            };
            page.Console += (_, message) =>
            {
                lock (sync) console.Add($"{message.Type}: {message.Text}");
                Console.WriteLine($"[UX-CONSOLE] {message.Type}: {message.Text}");
            };
            page.PageError += (_, message) =>
            {
                lock (sync) pageErrors.Add(message);
                Console.WriteLine($"[UX-PAGE-ERROR] {message}");
            };

            var response = await page.GotoAsync(
                host.BaseUrl + "/welcome",
                new PageGotoOptions { WaitUntil = WaitUntilState.Commit, Timeout = 10000 })
                .WaitAsync(TimeSpan.FromSeconds(12));
            response.Should().NotBeNull();
            response!.Status.Should().Be(200);

            await Task.Delay(BrowserDiagnosticWindow);

            string readyState;
            try
            {
                readyState = await page.EvaluateAsync<string>("() => document.readyState")
                    .WaitAsync(TimeSpan.FromSeconds(2));
            }
            catch (Exception ex)
            {
                readyState = $"<{ex.GetType().Name}: {ex.Message}>";
            }

            string[] pendingSnapshot;
            string[] failedSnapshot;
            string[] consoleSnapshot;
            string[] pageErrorSnapshot;
            lock (sync)
            {
                pendingSnapshot = pending.Select(item => $"{item.Value} {item.Key}").OrderBy(x => x).ToArray();
                failedSnapshot = failed.ToArray();
                consoleSnapshot = console.ToArray();
                pageErrorSnapshot = pageErrors.ToArray();
            }

            Console.WriteLine($"[UX-NET] readyState={readyState}");
            Console.WriteLine($"[UX-NET] pending={string.Join(" | ", pendingSnapshot.DefaultIfEmpty("<none>"))}");
            Console.WriteLine($"[UX-NET] failed={string.Join(" | ", failedSnapshot.DefaultIfEmpty("<none>"))}");
            Console.WriteLine($"[UX-NET] console={string.Join(" | ", consoleSnapshot.DefaultIfEmpty("<none>"))}");
            Console.WriteLine($"[UX-NET] pageErrors={string.Join(" | ", pageErrorSnapshot.DefaultIfEmpty("<none>"))}");

            readyState.Should().BeOneOf(
                "interactive",
                "complete",
                $"after a complete 200 HTML response, parser-blocking resources must settle; pending: {string.Join(" | ", pendingSnapshot.DefaultIfEmpty("<none>"))}; failed: {string.Join(" | ", failedSnapshot.DefaultIfEmpty("<none>"))}; console: {string.Join(" | ", consoleSnapshot.DefaultIfEmpty("<none>"))}; pageErrors: {string.Join(" | ", pageErrorSnapshot.DefaultIfEmpty("<none>"))}");
        }
        finally
        {
            await context.CloseBoundedAsync("network-diagnostic");
        }
    }
}
