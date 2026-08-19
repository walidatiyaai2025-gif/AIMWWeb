namespace AIWordPressManager.Web.Diagnostics;

public static class RuntimeInspectorHooks
{
    private static int _attached;

    public static void Attach(ILogger logger)
    {
        if (Interlocked.Exchange(ref _attached, 1) != 0) return;

        AppDomain.CurrentDomain.UnhandledException += (_, args) =>
        {
            try
            {
                if (args.ExceptionObject is Exception ex)
                    logger.LogCritical(ex, "Unhandled process exception. IsTerminating={IsTerminating}", args.IsTerminating);
                else
                    logger.LogCritical("Unhandled process exception object: {ExceptionObject}. IsTerminating={IsTerminating}", args.ExceptionObject, args.IsTerminating);
            }
            catch
            {
                // Never allow diagnostics hooks to interfere with process termination.
            }
        };

        TaskScheduler.UnobservedTaskException += (_, args) =>
        {
            try
            {
                logger.LogError(args.Exception, "Unobserved task exception.");
            }
            catch
            {
                // Diagnostics must not introduce another failure.
            }
        };
    }
}
