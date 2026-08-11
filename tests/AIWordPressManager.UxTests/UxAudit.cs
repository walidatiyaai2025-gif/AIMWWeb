using System.Text.Json;
using FluentAssertions;
using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAudit
{
    private const string StabilityCss = """
        *,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition:none!important;caret-color:transparent!important;scroll-behavior:auto!important}
        """;

    public static async Task PrepareAsync(IPage page)
    {
        await page.AddStyleTagAsync(new PageAddStyleTagOptions { Content = StabilityCss });
        await page.EvaluateAsync("() => document.fonts?.ready ?? Promise.resolve()");
        await page.WaitForTimeoutAsync(150);
    }

    public static async Task<IReadOnlyList<string>> AccessibilityIssuesAsync(IPage page, bool requireApplicationShell)
    {
        var issues = await page.EvaluateAsync<string[]>("""
            ({ requireApplicationShell }) => {
              const issues = [];
              const visible = el => {
                const style = getComputedStyle(el);
                const rect = el.getBoundingClientRect();
                return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
              };
              const nameOf = el => (el.getAttribute('aria-label') || el.getAttribute('title') || el.innerText || el.textContent || '').trim();

              if (!document.documentElement.lang) issues.push('html element must declare lang');
              if (!['ltr','rtl'].includes(document.documentElement.dir)) issues.push('html element must declare ltr or rtl direction');

              const ids = [...document.querySelectorAll('[id]')].map(x => x.id).filter(Boolean);
              const duplicates = [...new Set(ids.filter((id, i) => ids.indexOf(id) !== i))];
              duplicates.forEach(id => issues.push(`duplicate id: ${id}`));

              document.querySelectorAll('img').forEach(img => {
                if (!img.hasAttribute('alt')) issues.push(`image missing alt: ${img.getAttribute('src') || '(inline)'}`);
              });

              document.querySelectorAll('input,select,textarea').forEach(control => {
                if (!visible(control) || control.type === 'hidden') return;
                const id = control.id;
                const labelled = control.getAttribute('aria-label') || control.getAttribute('aria-labelledby') || control.getAttribute('title') ||
                  (id && document.querySelector(`label[for="${CSS.escape(id)}"]`)) || control.closest('label');
                if (!labelled) issues.push(`form control missing accessible label: ${control.name || control.type || control.tagName}`);
              });

              document.querySelectorAll('button,a[href],[role="button"]').forEach(control => {
                if (!visible(control)) return;
                if (!nameOf(control) && !control.getAttribute('aria-labelledby'))
                  issues.push(`interactive control missing accessible name: ${control.tagName.toLowerCase()}`);
              });

              document.querySelectorAll('[tabindex]').forEach(el => {
                const value = Number(el.getAttribute('tabindex'));
                if (value > 0) issues.push(`positive tabindex is not allowed: ${value}`);
              });

              document.querySelectorAll('button a,a button,button button,a a').forEach(() => issues.push('nested interactive controls detected'));

              if (requireApplicationShell) {
                const mains = [...document.querySelectorAll('main')].filter(visible);
                if (mains.length !== 1) issues.push(`expected one visible main landmark, found ${mains.length}`);
                const h1s = [...document.querySelectorAll('h1')].filter(visible);
                if (h1s.length !== 1) issues.push(`expected one visible h1, found ${h1s.length}`);
                if (!document.querySelector('#main-content')) issues.push('main content anchor #main-content missing');
                if (!document.querySelector('[data-app-direction]')) issues.push('runtime direction metadata missing');
              }
              return issues;
            }
            """, new { requireApplicationShell });
        return issues;
    }

    public static async Task<VisualMetrics> CaptureVisualMetricsAsync(IPage page)
    {
        var json = await page.EvaluateAsync<JsonElement>("""
            () => {
              const main = document.querySelector('#main-content');
              const rect = main?.getBoundingClientRect();
              const viewportWidth = document.documentElement.clientWidth;
              const scrollWidth = Math.max(document.body.scrollWidth, document.documentElement.scrollWidth);
              const clippedSurfaces = [...document.querySelectorAll('.app-toolbar,.app-card,.app-section,.panel')]
                .filter(el => {
                  const s = getComputedStyle(el); const r = el.getBoundingClientRect();
                  if (s.display === 'none' || s.visibility === 'hidden' || r.width <= 0) return false;
                  return r.left < -2 || r.right > viewportWidth + 2;
                }).length;
              return {
                viewportWidth,
                scrollWidth,
                horizontalOverflow: Math.max(0, scrollWidth - viewportWidth),
                mainLeft: rect?.left ?? 0,
                mainRight: rect?.right ?? 0,
                mainWidth: rect?.width ?? 0,
                h1Count: [...document.querySelectorAll('h1')].filter(x => getComputedStyle(x).display !== 'none').length,
                clippedSurfaces,
                direction: document.documentElement.dir,
                language: document.documentElement.lang,
                title: document.title
              };
            }
            """);

        return JsonSerializer.Deserialize<VisualMetrics>(json.GetRawText(), new JsonSerializerOptions { PropertyNameCaseInsensitive = true })
            ?? throw new InvalidOperationException("Could not deserialize UX visual metrics.");
    }

    public static void AssertMaterialVisualContract(VisualMetrics metrics, string routeKey)
    {
        metrics.HorizontalOverflow.Should().BeLessThanOrEqualTo(1, $"{routeKey} must not create application-level horizontal overflow");
        metrics.MainWidth.Should().BeGreaterThan(0, $"{routeKey} must render the application content landmark");
        metrics.H1Count.Should().Be(1, $"{routeKey} must preserve the single shell h1 hierarchy");
        metrics.ClippedSurfaces.Should().Be(0, $"{routeKey} shared surfaces must remain inside the viewport");
        metrics.Direction.Should().BeOneOf("ltr", "rtl");
        metrics.Language.Should().NotBeNullOrWhiteSpace();
        metrics.Title.Should().NotBeNullOrWhiteSpace();
    }

    public static async Task SaveVisualEvidenceAsync(IPage page, UxTestHost host, UxRouteCase route, UxViewport viewport, VisualMetrics metrics)
    {
        var stem = $"{route.Key}--{viewport.Key}";
        var screenshotPath = host.ArtifactPath("screenshots", stem + ".png");
        await page.ScreenshotAsync(new PageScreenshotOptions { Path = screenshotPath, FullPage = true });
        var metricsPath = host.ArtifactPath("metrics", stem + ".json");
        await File.WriteAllTextAsync(metricsPath, JsonSerializer.Serialize(metrics, new JsonSerializerOptions { WriteIndented = true }));
    }
}

public sealed record VisualMetrics(
    int ViewportWidth,
    int ScrollWidth,
    int HorizontalOverflow,
    double MainLeft,
    double MainRight,
    double MainWidth,
    int H1Count,
    int ClippedSurfaces,
    string Direction,
    string Language,
    string Title);
