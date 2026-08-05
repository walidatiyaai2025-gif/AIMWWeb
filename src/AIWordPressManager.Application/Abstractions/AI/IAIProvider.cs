namespace AIWordPressManager.Application.Abstractions.AI;

public interface IAIProvider
{
    string Name { get; }
    Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default);
}

public sealed record AIRequest(
    string Prompt,
    string? SystemPrompt = null,
    string? Model = null,
    double Temperature = 0.2,
    int MaxOutputTokens = 1500,
    Guid? SiteId = null,
    string? UserId = null,
    string? Operation = null);

public sealed record AIResponse(
    bool IsSuccess,
    string Content,
    string Provider,
    string? Model,
    int InputTokens,
    int OutputTokens,
    decimal EstimatedCost,
    string? Error = null);

public interface IAIPromptRegistry
{
    string Get(string key, string culture = "en");
    IReadOnlyDictionary<string, string> GetAll(string culture = "en");
}

public interface IAIOrchestrator
{
    Task<AIResponse> ExecuteAsync(AIRequest request, CancellationToken cancellationToken = default);
}

public interface IAIUsageLog
{
    void Record(AIUsageEntry entry);
    IReadOnlyList<AIUsageEntry> GetRecent(int take = 100, Guid? siteId = null, string? userId = null);
}

public sealed record AIUsageEntry(
    DateTime CreatedAtUtc,
    string Provider,
    string? Model,
    string? Operation,
    Guid? SiteId,
    string? UserId,
    int InputTokens,
    int OutputTokens,
    decimal EstimatedCost,
    bool IsSuccess,
    string? Error);

public interface IAIContentProtector
{
    string Redact(string input);
    bool TryConsume(string identity, int amount, out int remaining);
}
