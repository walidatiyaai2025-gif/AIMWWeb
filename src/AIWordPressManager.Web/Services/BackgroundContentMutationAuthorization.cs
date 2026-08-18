namespace AIWordPressManager.Web.Services;

/// <summary>
/// Marks one already-authorized background content-mutation continuation.
/// This is deliberately separate from <see cref="BackgroundExecutionIdentity"/>:
/// ownership never implies mutation permission by itself.
/// </summary>
internal static class BackgroundContentMutationAuthorization
{
    private static readonly AsyncLocal<int> Depth = new();

    public static bool IsGranted =>
        Depth.Value > 0 && BackgroundExecutionIdentity.TryGetOwnerUserId(out _);

    public static IDisposable Push()
    {
        if (!BackgroundExecutionIdentity.TryGetOwnerUserId(out _))
            throw new UnauthorizedAccessException("A background execution owner is required before authorizing content mutation continuation.");

        Depth.Value++;
        return new AuthorizationLease();
    }

    private sealed class AuthorizationLease : IDisposable
    {
        private int _disposed;

        public void Dispose()
        {
            if (Interlocked.Exchange(ref _disposed, 1) != 0)
                return;

            Depth.Value = Math.Max(0, Depth.Value - 1);
        }
    }
}