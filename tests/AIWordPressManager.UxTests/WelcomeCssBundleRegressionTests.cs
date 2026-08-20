using FluentAssertions;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

[Collection(UxRegressionCollection.Name)]
public sealed class WelcomeCssBundleRegressionTests(UxTestHost host)
{
    [Fact]
    public async Task Welcome_page_loads_scoped_css_bundle_and_material_layout()
    {
        var viewport = new UxViewport("desktop", 1440, 900);
        var context = await host.CreateContextAsync(viewport, authenticated: false);

        try
        {
            var page = await context.NewPageAsync();
            var response = await page.GotoAsync(
                host.BaseUrl + "/welcome",
                new PageGotoOptions
                {
                    WaitUntil = WaitUntilState.DOMContentLoaded,
                    Timeout = 10000
                });

            response.Should().NotBeNull();
            response!.Status.Should().BeLessThan(400);
            await page.Locator(".landing-v2").WaitForAsync(new LocatorWaitForOptions
            {
                State = WaitForSelectorState.Visible,
                Timeout = 5000
            });

            var scopedBundleLinks = await page.Locator("link[rel='stylesheet'][href='AIWordPressManager.Web.styles.css']").CountAsync();
            scopedBundleLinks.Should().Be(1, "Razor CSS isolation must be linked from the application document");

            var metrics = await page.EvaluateAsync<WelcomeStyleMetrics>("""
                () => {
                  const hero = document.querySelector('.hero-v2');
                  const header = document.querySelector('.public-header');
                  const heading = document.querySelector('.hero-copy-v2 h1');
                  if (!hero || !header || !heading) throw new Error('Welcome landing structure is incomplete.');
                  const heroStyle = getComputedStyle(hero);
                  const headerStyle = getComputedStyle(header);
                  const headingStyle = getComputedStyle(heading);
                  return {
                    heroDisplay: heroStyle.display,
                    heroColumnCount: heroStyle.gridTemplateColumns.split(' ').filter(Boolean).length,
                    headerDisplay: headerStyle.display,
                    headingFontSize: parseFloat(headingStyle.fontSize),
                    headerWidth: header.getBoundingClientRect().width
                  };
                }
                """);

            metrics.HeroDisplay.Should().Be("grid");
            metrics.HeroColumnCount.Should().BeGreaterThanOrEqualTo(2);
            metrics.HeaderDisplay.Should().Be("flex");
            metrics.HeadingFontSize.Should().BeGreaterThan(40);
            metrics.HeaderWidth.Should().BeGreaterThan(900);
        }
        finally
        {
            await context.CloseAsync();
        }
    }

    private sealed class WelcomeStyleMetrics
    {
        public string HeroDisplay { get; set; } = string.Empty;
        public int HeroColumnCount { get; set; }
        public string HeaderDisplay { get; set; } = string.Empty;
        public double HeadingFontSize { get; set; }
        public double HeaderWidth { get; set; }
    }
}
