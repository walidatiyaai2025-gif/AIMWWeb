namespace AIWordPressManager.Web.Services;

/// <summary>
/// Carries the owning application user through one background execution flow.
/// The lease is ambient only for the current async execution context and must be disposed.
/// It never carries roles or administrator privileges.
/// </summary>
public static class BackgroundExecutionIdentity
{
    private static readonly AsyncLocal<IdentityState?> Current = new();

    public static bool TryGetOwnerUserId(out Guid ownerUserId)
    {
        var state = Current.Value;
        if (state is not null && state.OwnerUserId != Guid.Empty)
        {
            ownerUserId = state.OwnerUserId;
            return true;
        }

        ownerUserId = Guid.Empty;
        return false;
    }

    public static IDisposable Push(Guid ownerUserId)
    {
        if (ownerUserId == Guid.Empty)
            throw new ArgumentException("Background execution owner user ID is required.", nameof(ownerUserId));

        var previous = Current.Value;
        Current.Value = new IdentityState(ownerUserId);
        return new IdentityLease(previous);
    }

    private sealed record IdentityState(Guid OwnerUserId);

    private sealed class IdentityLease(IdentityState? previous) : IDisposable
    {
        private IdentityState? _previous = previous;
        private int _disposed;

        public void Dispose()
        {
            if (Interlocked.Exchange(ref _disposed, 1) != 0)
                return;

            Current.Value = _previous;
            _previous = null;
        }
    }
}
