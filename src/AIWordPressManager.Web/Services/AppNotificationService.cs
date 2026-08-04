namespace AIWordPressManager.Web.Services;

public sealed class AppNotificationService
{
    private readonly List<AppNotification> _items = [];
    private readonly object _sync = new();

    public event Action? Changed;

    public IReadOnlyList<AppNotification> Items
    {
        get
        {
            lock (_sync) return _items.ToArray();
        }
    }

    public Guid Success(string message, string? title = null) => Show(NotificationType.Success, message, title);
    public Guid Error(string message, string? title = null, string? details = null) => Show(NotificationType.Error, message, title, details, 12000);
    public Guid Warning(string message, string? title = null) => Show(NotificationType.Warning, message, title, null, 9000);
    public Guid Info(string message, string? title = null) => Show(NotificationType.Info, message, title);

    public Guid Show(NotificationType type, string message, string? title = null, string? details = null, int durationMs = 6000)
    {
        var item = new AppNotification(Guid.NewGuid(), type, title, message, details, DateTime.UtcNow, durationMs);
        lock (_sync)
        {
            _items.Insert(0, item);
            if (_items.Count > 6) _items.RemoveRange(6, _items.Count - 6);
        }
        Changed?.Invoke();
        return item.Id;
    }

    public void Dismiss(Guid id)
    {
        lock (_sync) _items.RemoveAll(x => x.Id == id);
        Changed?.Invoke();
    }

    public void Clear()
    {
        lock (_sync) _items.Clear();
        Changed?.Invoke();
    }
}

public sealed record AppNotification(
    Guid Id,
    NotificationType Type,
    string? Title,
    string Message,
    string? Details,
    DateTime CreatedAtUtc,
    int DurationMs);

public enum NotificationType
{
    Info,
    Success,
    Warning,
    Error
}
