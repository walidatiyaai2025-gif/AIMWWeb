namespace AIWordPressManager.Application.Abstractions.Persistence;

public interface IDatabaseInitializationService
{
    Task InitializeAsync(CancellationToken cancellationToken = default);
}
