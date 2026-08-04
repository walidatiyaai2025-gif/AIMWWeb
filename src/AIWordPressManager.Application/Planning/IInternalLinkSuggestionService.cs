namespace AIWordPressManager.Application.Planning;

public interface IInternalLinkSuggestionService
{
    Task<IReadOnlyList<InternalLinkSuggestionItem>> GenerateAsync(Guid siteId, CancellationToken cancellationToken = default);
}

public sealed record InternalLinkSuggestionItem(int SourceWordPressId, string SourceTitle, int TargetWordPressId, string TargetTitle, string SuggestedAnchor, string Reason, double Confidence);
