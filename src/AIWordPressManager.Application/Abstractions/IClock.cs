namespace AIWordPressManager.Application.Abstractions;

public interface IClock
{
    DateTime UtcNow { get; }
}
