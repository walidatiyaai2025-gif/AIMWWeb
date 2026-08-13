using System.Diagnostics;
using FluentAssertions;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class BlazorResponseCompletionTests(UxTestHost host)
{
    private static readonly TimeSpan ResponseBodyTimeout = TimeSpan.FromSeconds(5);

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
}
