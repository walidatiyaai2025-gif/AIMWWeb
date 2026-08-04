namespace AIWordPressManager.Application.Abstractions.WordPress;

public interface IWordPressConnectionTester
{
    Task<WordPressConnectionResult> TestAsync(WordPressConnectionRequest request, CancellationToken cancellationToken = default);
}
