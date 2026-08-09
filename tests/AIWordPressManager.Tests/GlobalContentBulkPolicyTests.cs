using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class GlobalContentBulkPolicyTests
{
    [Theory]
    [InlineData(" publish ", "publish")]
    [InlineData("DRAFT", "draft")]
    [InlineData("pending", "pending")]
    [InlineData("Private", "private")]
    public void NormalizeStatus_AcceptsSupportedStatuses(string input, string expected)
    {
        GlobalContentBulkPolicy.NormalizeStatus(input).Should().Be(expected);
    }

    [Fact]
    public void NormalizeStatus_RejectsUnsupportedStatus()
    {
        var action = () => GlobalContentBulkPolicy.NormalizeStatus("trash");
        action.Should().Throw<InvalidOperationException>();
    }

    [Fact]
    public void NormalizeTargets_DeduplicatesAndNormalizesContentType()
    {
        var siteId = Guid.NewGuid();
        var targets = new[]
        {
            new GlobalBulkContentTarget(siteId, "POST", 42, " First "),
            new GlobalBulkContentTarget(siteId, "post", 42, "Duplicate")
        };

        var result = GlobalContentBulkPolicy.NormalizeTargets(targets);

        result.Should().ContainSingle();
        result[0].ContentType.Should().Be("post");
        result[0].Title.Should().Be("First");
    }

    [Theory]
    [InlineData("post", 0)]
    [InlineData("media", 4)]
    public void NormalizeTargets_RejectsInvalidTargets(string contentType, int wordPressId)
    {
        var targets = new[] { new GlobalBulkContentTarget(Guid.NewGuid(), contentType, wordPressId, "Item") };
        var action = () => GlobalContentBulkPolicy.NormalizeTargets(targets);
        action.Should().Throw<InvalidOperationException>();
    }

    [Fact]
    public void NormalizeTargets_RejectsEmptySiteId()
    {
        var targets = new[] { new GlobalBulkContentTarget(Guid.Empty, "post", 1, "Item") };
        var action = () => GlobalContentBulkPolicy.NormalizeTargets(targets);
        action.Should().Throw<InvalidOperationException>();
    }

    [Fact]
    public void NormalizeTargets_EnforcesSafetyLimit()
    {
        var siteId = Guid.NewGuid();
        var targets = Enumerable.Range(1, GlobalContentBulkPolicy.MaxTargets + 1)
            .Select(id => new GlobalBulkContentTarget(siteId, "post", id, $"Post {id}"));

        var action = () => GlobalContentBulkPolicy.NormalizeTargets(targets);
        action.Should().Throw<InvalidOperationException>()
            .WithMessage($"*{GlobalContentBulkPolicy.MaxTargets}*");
    }

    [Fact]
    public void GroupBySite_SeparatesSiteOperations()
    {
        var first = Guid.NewGuid();
        var second = Guid.NewGuid();
        var targets = new[]
        {
            new GlobalBulkContentTarget(first, "post", 1, "A"),
            new GlobalBulkContentTarget(second, "post", 2, "B"),
            new GlobalBulkContentTarget(first, "post", 3, "C")
        };

        var groups = GlobalContentBulkPolicy.GroupBySite(targets);

        groups.Should().HaveCount(2);
        groups.Single(x => x.SiteId == first).Targets.Should().HaveCount(2);
        groups.Single(x => x.SiteId == second).Targets.Should().ContainSingle();
    }
}
