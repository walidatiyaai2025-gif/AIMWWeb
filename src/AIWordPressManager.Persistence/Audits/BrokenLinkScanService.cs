using System.Collections.Concurrent;
using System.Net;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.BrokenLinks;
using AIWordPressManager.Application.Common.Results;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Persistence.Audits;

public sealed partial class BrokenLinkScanService(
    AppDbContext dbContext,
    IHttpClientFactory httpClientFactory,
    ILogger<BrokenLinkScanService> logger) : IBrokenLinkScanService
{
    public async Task<Result<BrokenLinkScanSummary>> LoadLatestAsync(
        Guid siteId,
        CancellationToken cancellationToken = default)
    {
        var stored = await dbContext.BrokenLinks
            .Where(x => x.SiteId == siteId)
            .AsNoTracking()
            .OrderBy(x => x.Status)
            .ThenBy(x => x.TargetUrl)
            .ToListAsync(cancellationToken);

        var contentIds = stored.Select(x => x.ContentRecordId).Distinct().ToArray();
        var content = await dbContext.WordPressContentRecords
            .Where(x => contentIds.Contains(x.Id))
            .AsNoTracking()
            .ToDictionaryAsync(x => x.Id, cancellationToken);

        var results = stored.Select(x =>
        {
            content.TryGetValue(x.ContentRecordId, out var item);
            return new BrokenLinkDto(
                item?.Title ?? "Unknown content",
                x.SourceUrl,
                x.TargetUrl,
                x.StatusCode,
                x.Status,
                x.ErrorMessage);
        }).ToList();

        var broken = results.Count(x => x.Status is "Broken" or "Error");
        var redirects = results.Count(x => x.Status == "Redirect");
        var healthy = results.Count(x => x.Status == "Healthy");
        var completedAt = stored.Count == 0 ? DateTimeOffset.MinValue : new DateTimeOffset(stored.Max(x => x.CheckedAtUtc), TimeSpan.Zero);
        return Result.Success(new BrokenLinkScanSummary(results.Count, broken, redirects, healthy, results, completedAt));
    }

    private const int MaximumLinksPerScan = 200;
    private const int MaximumConcurrency = 6;

    public async Task<Result<BrokenLinkScanSummary>> RunAsync(
        Guid siteId,
        IProgress<BrokenLinkScanProgress>? progress = null,
        CancellationToken cancellationToken = default)
    {
        var site = await dbContext.Sites
            .AsNoTracking()
            .SingleOrDefaultAsync(x => x.Id == siteId, cancellationToken);

        if (site is null)
        {
            return Result.Failure<BrokenLinkScanSummary>(
                Error.NotFound("The selected site was not found."));
        }

        var content = await dbContext.WordPressContentRecords
            .Where(x => x.SiteId == siteId && x.IsAvailable)
            .AsNoTracking()
            .ToListAsync(cancellationToken);

        if (content.Count == 0)
        {
            return Result.Failure<BrokenLinkScanSummary>(
                Error.NotFound("Synchronize WordPress content before scanning links."));
        }

        var candidates = ExtractCandidates(site.SiteUrl, content);
        if (candidates.Count == 0)
        {
            return Result.Success(
                new BrokenLinkScanSummary(0, 0, 0, 0, [], DateTimeOffset.Now));
        }

        progress?.Report(new BrokenLinkScanProgress(
            3,
            $"Prepared {candidates.Count} unique links."));

        var client = httpClientFactory.CreateClient();
        client.Timeout = TimeSpan.FromSeconds(15);
        client.DefaultRequestHeaders.UserAgent.ParseAdd(
            "AIWordPressManager-LinkScanner/1.0");

        var results = new ConcurrentBag<(Guid ContentId, BrokenLinkDto Dto)>();
        var completed = 0;

        using var gate = new SemaphoreSlim(MaximumConcurrency);
        var tasks = candidates.Select(async candidate =>
        {
            await gate.WaitAsync(cancellationToken);
            try
            {
                var checkedResult = await CheckAsync(
                    client,
                    candidate.Key,
                    cancellationToken);

                var dto = new BrokenLinkDto(
                    candidate.Value.SourceTitle,
                    candidate.Value.SourceUrl,
                    candidate.Key,
                    checkedResult.StatusCode,
                    checkedResult.Status,
                    checkedResult.ErrorMessage);

                results.Add((candidate.Value.ContentId, dto));
            }
            finally
            {
                gate.Release();
                var done = Interlocked.Increment(ref completed);
                var percentage = 3 + (int)(done * 92d / candidates.Count);
                progress?.Report(new BrokenLinkScanProgress(
                    percentage,
                    $"Checked {done} of {candidates.Count} links."));
            }
        });

        await Task.WhenAll(tasks);
        cancellationToken.ThrowIfCancellationRequested();

        await ReplaceStoredResultsAsync(
            siteId,
            results,
            cancellationToken);

        var ordered = results
            .Select(x => x.Dto)
            .OrderBy(x => StatusOrder(x.Status))
            .ThenBy(x => x.TargetUrl)
            .ToList();

        var broken = ordered.Count(x => x.Status is "Broken" or "Error");
        var redirects = ordered.Count(x => x.Status == "Redirect");
        var healthy = ordered.Count(x => x.Status == "Healthy");

        progress?.Report(new BrokenLinkScanProgress(
            100,
            "Broken-link scan completed."));

        logger.LogInformation(
            "Broken-link scan completed for site {SiteId}. Checked {Checked}, broken {Broken}, redirects {Redirects}.",
            siteId,
            ordered.Count,
            broken,
            redirects);

        return Result.Success(new BrokenLinkScanSummary(
            ordered.Count,
            broken,
            redirects,
            healthy,
            ordered,
            DateTimeOffset.Now));
    }

    private static Dictionary<string, (Guid ContentId, string SourceTitle, string SourceUrl)> ExtractCandidates(
        string siteUrl,
        IReadOnlyCollection<WordPressContentRecord> content)
    {
        var candidates = new Dictionary<string, (Guid, string, string)>(
            StringComparer.OrdinalIgnoreCase);

        foreach (var item in content)
        {
            foreach (Match match in HrefRegex().Matches(item.RenderedContent ?? string.Empty))
            {
                var raw = WebUtility.HtmlDecode(match.Groups[1].Value.Trim());
                if (!TryNormalizeUrl(siteUrl, raw, out var absolute))
                {
                    continue;
                }

                candidates.TryAdd(
                    absolute,
                    (item.Id, item.Title, item.Link));

                if (candidates.Count >= MaximumLinksPerScan)
                {
                    break;
                }
            }

            if (candidates.Count >= MaximumLinksPerScan)
            {
                break;
            }
        }

        return candidates;
    }

    private async Task ReplaceStoredResultsAsync(
        Guid siteId,
        IEnumerable<(Guid ContentId, BrokenLinkDto Dto)> results,
        CancellationToken cancellationToken)
    {
        var oldResults = await dbContext.BrokenLinks
            .Where(x => x.SiteId == siteId)
            .ToListAsync(cancellationToken);

        dbContext.BrokenLinks.RemoveRange(oldResults);
        var checkedAtUtc = DateTime.UtcNow;

        foreach (var result in results)
        {
            dbContext.BrokenLinks.Add(new BrokenLinkRecord(
                siteId,
                result.ContentId,
                result.Dto.SourceUrl,
                result.Dto.TargetUrl,
                result.Dto.StatusCode,
                result.Dto.Status,
                result.Dto.ErrorMessage,
                checkedAtUtc));
        }

        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private static async Task<(int? StatusCode, string Status, string? ErrorMessage)> CheckAsync(
        HttpClient client,
        string url,
        CancellationToken cancellationToken)
    {
        try
        {
            using var headRequest = new HttpRequestMessage(HttpMethod.Head, url);
            using var headResponse = await client.SendAsync(
                headRequest,
                HttpCompletionOption.ResponseHeadersRead,
                cancellationToken);

            if (headResponse.StatusCode is HttpStatusCode.MethodNotAllowed or HttpStatusCode.NotImplemented)
            {
                using var getRequest = new HttpRequestMessage(HttpMethod.Get, url);
                using var getResponse = await client.SendAsync(
                    getRequest,
                    HttpCompletionOption.ResponseHeadersRead,
                    cancellationToken);

                return Classify(getResponse);
            }

            return Classify(headResponse);
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            return (null, "Error", "Request timed out.");
        }
        catch (HttpRequestException ex)
        {
            return (null, "Error", Truncate(ex.Message));
        }
        catch (Exception ex)
        {
            return (null, "Error", Truncate(ex.Message));
        }
    }

    private static (int? StatusCode, string Status, string? ErrorMessage) Classify(
        HttpResponseMessage response)
    {
        var code = (int)response.StatusCode;

        if (code is >= 200 and < 300)
        {
            return (code, "Healthy", null);
        }

        if (code is >= 300 and < 400)
        {
            return (
                code,
                "Redirect",
                response.Headers.Location?.ToString());
        }

        return (code, "Broken", response.ReasonPhrase);
    }

    private static bool TryNormalizeUrl(
        string siteUrl,
        string raw,
        out string absolute)
    {
        absolute = string.Empty;

        if (string.IsNullOrWhiteSpace(raw)
            || raw.StartsWith('#')
            || raw.StartsWith("mailto:", StringComparison.OrdinalIgnoreCase)
            || raw.StartsWith("tel:", StringComparison.OrdinalIgnoreCase)
            || raw.StartsWith("javascript:", StringComparison.OrdinalIgnoreCase)
            || raw.StartsWith("data:", StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        if (!Uri.TryCreate(raw, UriKind.Absolute, out var uri))
        {
            if (!Uri.TryCreate(siteUrl.TrimEnd('/') + "/", UriKind.Absolute, out var baseUri)
                || !Uri.TryCreate(baseUri, raw, out uri))
            {
                return false;
            }
        }

        if (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps)
        {
            return false;
        }

        var builder = new UriBuilder(uri)
        {
            Fragment = string.Empty
        };

        absolute = builder.Uri.AbsoluteUri;
        return true;
    }

    private static int StatusOrder(string status) => status switch
    {
        "Broken" => 0,
        "Error" => 1,
        "Redirect" => 2,
        _ => 3
    };

    private static string Truncate(string value) =>
        value.Length <= 500 ? value : value[..500];

    [GeneratedRegex(
        "href\\s*=\\s*[\"']([^\"'#]+)[\"']",
        RegexOptions.IgnoreCase | RegexOptions.Compiled)]
    private static partial Regex HrefRegex();
}
