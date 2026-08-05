using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class SeoRuleEngineTests
{
    [Fact]
    public void Analyze_ShouldReturnSameResult_ForSameInput()
    {
        var input = CreateHealthyInput();

        var first = SeoRuleEngine.Analyze(input);
        var second = SeoRuleEngine.Analyze(input);

        second.Should().BeEquivalentTo(first);
    }

    [Fact]
    public void Analyze_ShouldDetectMissingCriticalMetadata()
    {
        var input = new SeoRuleInput(
            10,
            "post",
            string.Empty,
            string.Empty,
            "https://example.test/post/10",
            "draft",
            string.Empty,
            "<p>Short body.</p>");

        var result = SeoRuleEngine.Analyze(input);

        result.Issues.Select(x => x.Code).Should().Contain(new[]
        {
            "MissingTitle",
            "MissingDescription",
            "ThinContent",
            "MissingSlug"
        });
        result.Score.Should().Be(35);
    }

    [Fact]
    public void Analyze_ShouldDetectImagesWithoutAlt_AndCountThem()
    {
        var input = CreateHealthyInput() with
        {
            RenderedContent = LongContent("<h2>Section</h2><img src='a.jpg'><img src='b.jpg' alt='Useful description'><img src='c.jpg' alt=''>")
        };

        var result = SeoRuleEngine.Analyze(input);

        result.ImageCount.Should().Be(3);
        result.ImagesWithoutAlt.Should().Be(2);
        result.Issues.Should().ContainSingle(x => x.Code == "ImagesMissingAlt" && x.Count == 2);
    }

    [Fact]
    public void Analyze_ShouldDistinguishInternalAndExternalLinks()
    {
        var input = CreateHealthyInput() with
        {
            RenderedContent = LongContent("<h2>Section</h2><a href='/about'>Internal</a><a href='https://external.test'>External</a>")
        };

        var result = SeoRuleEngine.Analyze(input);

        result.InternalLinks.Should().Be(1);
        result.Issues.Should().NotContain(x => x.Code == "MissingInternalLinks");
    }

    [Fact]
    public void Analyze_ShouldIgnoreScriptAndStyleText_WhenCountingWords()
    {
        var input = CreateHealthyInput() with
        {
            RenderedContent = "<script>" + string.Join(' ', Enumerable.Repeat("fake", 500)) + "</script>" +
                              "<style>" + string.Join(' ', Enumerable.Repeat("fake", 500)) + "</style>" +
                              "<p>Real words only.</p>"
        };

        var result = SeoRuleEngine.Analyze(input);

        result.WordCount.Should().Be(3);
        result.Issues.Should().Contain(x => x.Code == "ThinContent");
    }

    [Fact]
    public void Analyze_ShouldReturnHighScore_ForHealthyContent()
    {
        var result = SeoRuleEngine.Analyze(CreateHealthyInput());

        result.Score.Should().Be(100);
        result.Issues.Should().BeEmpty();
        result.HeadingCount.Should().BeGreaterThan(0);
        result.InternalLinks.Should().BeGreaterThan(0);
    }

    [Theory]
    [InlineData("Tiny title", "ShortTitle")]
    [InlineData("This is an intentionally very long SEO title that exceeds the recommended sixty character maximum", "LongTitle")]
    public void Analyze_ShouldValidateTitleLength(string title, string expectedIssue)
    {
        var result = SeoRuleEngine.Analyze(CreateHealthyInput() with { Title = title });

        result.Issues.Should().Contain(x => x.Code == expectedIssue);
    }

    [Fact]
    public void PlainText_ShouldDecodeEntitiesAndRemoveMarkup()
    {
        var result = SeoRuleEngine.PlainText("<p>Tom &amp; Jerry</p><script>alert('x')</script>");

        result.Should().Contain("Tom & Jerry");
        result.Should().NotContain("alert");
    }

    private static SeoRuleInput CreateHealthyInput() => new(
        1,
        "post",
        "A Complete Practical Guide to WordPress Content Optimization",
        "wordpress-content-optimization-guide",
        "https://example.test/wordpress-content-optimization-guide",
        "publish",
        "A practical guide to improving WordPress content quality, structure, accessibility and search visibility.",
        LongContent("<h1>WordPress Optimization</h1><h2>Practical Steps</h2><a href='/resources'>Resources</a><img src='guide.jpg' alt='WordPress optimization dashboard'>"));

    private static string LongContent(string prefix) =>
        prefix + "<p>" + string.Join(' ', Enumerable.Range(1, 340).Select(i => $"word{i}")) + "</p>";
}
