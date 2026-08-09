using AIWordPressManager.Application.AI;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class AISuggestionContractTests
{
    [Fact]
    public void Valid_Suggestion_Preserves_Before_And_Normalizes_Affected_Fields()
    {
        const string before = "Original content\nwith exact spacing.";
        const string raw = """
        {
          "after": "Improved content",
          "explanation": "Clearer wording and stronger structure.",
          "confidence": 0.91,
          "affectedFields": ["content", "Content", "seo.metaDescription"]
        }
        """;

        var success = AISuggestionContract.TryParse(before, raw, out var suggestion, out var error);

        success.Should().BeTrue(error);
        suggestion.Should().NotBeNull();
        suggestion!.Before.Should().Be(before);
        suggestion.After.Should().Be("Improved content");
        suggestion.Explanation.Should().Be("Clearer wording and stronger structure.");
        suggestion.Confidence.Should().Be(0.91);
        suggestion.AffectedFields.Should().Equal("content", "seo.metaDescription");
    }

    [Fact]
    public void Markdown_Fenced_Json_Is_Accepted_Without_Trusting_Ai_Before_Value()
    {
        const string raw = """
        ```json
        {
          "before": "forged source",
          "after": "اقتراح جديد",
          "explanation": "تحسين الوضوح.",
          "confidence": 0.8,
          "affectedFields": ["content"]
        }
        ```
        """;

        var success = AISuggestionContract.TryParse("المحتوى الأصلي", raw, out var suggestion, out var error);

        success.Should().BeTrue(error);
        suggestion!.Before.Should().Be("المحتوى الأصلي");
        suggestion.After.Should().Be("اقتراح جديد");
    }

    [Theory]
    [InlineData("not-json")]
    [InlineData("{\"after\":\"x\",\"explanation\":\"why\",\"confidence\":1.5,\"affectedFields\":[\"content\"]}")]
    [InlineData("{\"after\":\"x\",\"explanation\":\"why\",\"confidence\":0.7,\"affectedFields\":[]}")]
    [InlineData("{\"after\":\"\",\"explanation\":\"why\",\"confidence\":0.7,\"affectedFields\":[\"content\"]}")]
    public void Invalid_Or_Incomplete_Suggestions_Fail_Closed(string raw)
    {
        var success = AISuggestionContract.TryParse("before", raw, out var suggestion, out var error);

        success.Should().BeFalse();
        suggestion.Should().BeNull();
        error.Should().NotBeNullOrWhiteSpace();
    }

    [Fact]
    public void System_Prompt_Requires_Strict_Reviewable_Json_In_Selected_Language()
    {
        var prompt = AISuggestionContract.BuildSystemPrompt("Rewrite for clarity.", "ar-KW");

        prompt.Should().Contain("Rewrite for clarity.");
        prompt.Should().Contain("valid JSON object");
        prompt.Should().Contain("affectedFields");
        prompt.Should().Contain("Arabic");
        prompt.Should().Contain("Do not return Markdown fences");
    }
}
