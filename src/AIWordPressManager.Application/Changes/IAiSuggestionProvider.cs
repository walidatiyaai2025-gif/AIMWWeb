namespace AIWordPressManager.Application.Changes;

public sealed record AiSuggestionInput(
    string SourceType,
    string ObjectType,
    string ObjectId,
    string ChangeType,
    string CurrentValue,
    string ProposedValue,
    string Reason,
    string RiskLevel);

public sealed record AiSuggestionOutput(
    string ObjectId,
    string ChangeType,
    string ProposedValue,
    string Reason,
    double Confidence,
    string RiskLevel);

public interface IAiSuggestionProvider
{
    Task<bool> IsConfiguredAsync(CancellationToken cancellationToken = default);
    Task<IReadOnlyList<AiSuggestionOutput>> ImproveSuggestionsAsync(
        IReadOnlyCollection<AiSuggestionInput> suggestions,
        CancellationToken cancellationToken = default);
}
