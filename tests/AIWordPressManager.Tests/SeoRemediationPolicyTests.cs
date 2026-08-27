using AIWordPressManager.Web.Services;

namespace AIWordPressManager.Tests;

public sealed class SeoRemediationPolicyTests
{
    [Fact]
    public void ValidStructuredProposal_IsAccepted()
    {
        var valid = SeoRemediationWebService.TryValidateSuggestion(
            """{"suggestedValue":"A focused SEO title","reason":"Clearer search intent","confidence":0.91}""",
            SeoRemediationField.Title, "Old title", out var value, out var reason, out var confidence, out var error);

        Assert.True(valid);
        Assert.Equal("A focused SEO title", value);
        Assert.Equal("Clearer search intent", reason);
        Assert.Equal(0.91m, confidence);
        Assert.Empty(error);
    }

    [Theory]
    [InlineData("")]
    [InlineData("not json")]
    [InlineData("{\"suggestedValue\":\"\",\"reason\":\"reason\",\"confidence\":0.8}")]
    [InlineData("{\"suggestedValue\":\"Same\",\"reason\":\"reason\",\"confidence\":0.8}")]
    [InlineData("{\"suggestedValue\":\"Different\",\"reason\":\"reason\",\"confidence\":1.1}")]
    public void MalformedOrUnusableProposal_IsRejected(string response)
    {
        Assert.False(SeoRemediationWebService.TryValidateSuggestion(response, SeoRemediationField.Title, "Same",
            out var value, out _, out _, out var error));
        Assert.Empty(value);
        Assert.NotEmpty(error);
    }

    [Fact]
    public void ApplyAllSafePolicy_AllowsOnlyCurrentlySupportedNonDestructiveFields()
    {
        Assert.Equal(SeoRemediationSafetyClass.SafeAutomatic, SeoRemediationWebService.SafetyFor(SeoRemediationField.Title));
        Assert.Equal(SeoRemediationSafetyClass.SafeAutomatic, SeoRemediationWebService.SafetyFor(SeoRemediationField.MetaDescription));
        Assert.Equal(SeoRemediationSafetyClass.Unsupported, SeoRemediationWebService.SafetyFor(SeoRemediationField.ImageAltText));
        Assert.Equal(SeoRemediationSafetyClass.ReviewRequired, SeoRemediationWebService.SafetyFor(SeoRemediationField.InternalLink));
    }

    [Fact]
    public void OverlongSuggestion_IsRejected()
    {
        var response = $$"""{"suggestedValue":"{{new string('x', 101)}}","reason":"reason","confidence":0.8}""";
        Assert.False(SeoRemediationWebService.TryValidateSuggestion(response, SeoRemediationField.Title, "Old",
            out _, out _, out _, out _));
    }
}
