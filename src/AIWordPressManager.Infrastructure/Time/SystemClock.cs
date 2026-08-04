using AIWordPressManager.Application.Abstractions;

namespace AIWordPressManager.Infrastructure.Time;

public sealed class SystemClock : IClock
{
    public DateTime UtcNow => DateTime.UtcNow;
}
