namespace AIWordPressManager.Application.Changes;

public sealed record AiProviderTestResult(bool Success, string Message, IReadOnlyList<string> Models);

public interface IAiProvider
{
    string Name { get; }
    Task<IReadOnlyList<AiSuggestionOutput>> ImproveSuggestionsAsync(
        IReadOnlyCollection<AiSuggestionInput> suggestions,
        string model,
        string apiKey,
        CancellationToken cancellationToken = default);
    Task<AiProviderTestResult> TestAsync(string model, string apiKey, CancellationToken cancellationToken = default);
}
