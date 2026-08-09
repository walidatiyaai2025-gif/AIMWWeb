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

public sealed record AIPromptTemplateDefinition(
    string Key,
    string TitleEn,
    string TitleAr,
    string PromptEn,
    string PromptAr,
    bool Enabled,
    int Revision,
    DateTime UpdatedAtUtc,
    string UpdatedBy,
    bool IsBuiltIn);

public sealed record AIPromptTemplateVersion(
    string Key,
    int Revision,
    string TitleEn,
    string TitleAr,
    string PromptEn,
    string PromptAr,
    bool Enabled,
    DateTime CreatedAtUtc,
    string CreatedBy,
    string ChangeType);

public sealed record AIPromptTemplateInput(
    string Key,
    string TitleEn,
    string TitleAr,
    string PromptEn,
    string PromptAr,
    bool Enabled);

public interface IAIPromptRegistry
{
    string Get(string key, string culture = "en");
    IReadOnlyDictionary<string, string> GetAll(string culture = "en");
}

public interface IAIPromptTemplateStore
{
    IReadOnlyList<AIPromptTemplateDefinition> GetDefinitions();
    AIPromptTemplateDefinition? GetDefinition(string key);
    IReadOnlyList<AIPromptTemplateVersion> GetHistory(string key);
    AIPromptTemplateDefinition Save(AIPromptTemplateInput input, string actor);
    AIPromptTemplateDefinition SetEnabled(string key, bool enabled, string actor);
    AIPromptTemplateDefinition Restore(string key, int revision, string actor);
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
