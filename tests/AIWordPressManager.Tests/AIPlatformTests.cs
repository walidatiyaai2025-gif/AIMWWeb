using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Infrastructure.AI;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class AIPlatformTests
{
    [Fact]
    public void Redact_Removes_Common_Secrets()
    {
        var protector = new AIContentProtector();

        var result = protector.Redact("password=secret123 api_key:abc token=qwerty");

        result.Should().NotContain("secret123");
        result.Should().NotContain("abc");
        result.Should().NotContain("qwerty");
        result.Should().Contain("[REDACTED]");
    }

    [Fact]
    public void Quota_Stops_After_Daily_Limit()
    {
        var protector = new AIContentProtector();

        protector.TryConsume("user-1", 100, out var remaining).Should().BeTrue();
        remaining.Should().Be(0);
        protector.TryConsume("user-1", 1, out remaining).Should().BeFalse();
        remaining.Should().Be(0);
    }

    [Fact]
    public void PromptRegistry_Returns_English_And_Arabic_Prompts()
    {
        var registry = new AIPromptRegistry();

        registry.Get("rewrite", "en").Should().Contain("Rewrite");
        registry.Get("rewrite", "ar").Should().Contain("أعد");
        registry.GetAll("en").Should().ContainKey("alt-text");
    }

    [Fact]
    public async Task Orchestrator_Falls_Back_To_Next_Provider_And_Records_Usage()
    {
        var usage = new AIUsageLog();
        var orchestrator = new AIOrchestrator(
            new IAIProvider[] { new FakeProvider("first", false), new FakeProvider("second", true) },
            usage,
            new AIContentProtector());

        var result = await orchestrator.ExecuteAsync(new AIRequest("content", "instruction", null, 0.2, 100, null, "tester", "rewrite"));

        result.IsSuccess.Should().BeTrue();
        result.Provider.Should().Be("second");
        usage.GetRecent().Should().Contain(x => x.Provider == "first" && !x.IsSuccess);
        usage.GetRecent().Should().Contain(x => x.Provider == "second" && x.IsSuccess);
    }

    private sealed class FakeProvider(string name, bool succeed) : IAIProvider
    {
        public string Name => name;

        public Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default) =>
            Task.FromResult(succeed
                ? new AIResponse(true, "done", Name, "fake", 1, 1, 0)
                : new AIResponse(false, string.Empty, Name, "fake", 1, 0, 0, "failed"));
    }
}
