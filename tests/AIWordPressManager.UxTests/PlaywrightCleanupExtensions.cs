using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

internal static class PlaywrightCleanupExtensions
{
    private static readonly TimeSpan CloseTimeout = TimeSpan.FromSeconds(5);

    public static async Task CloseBoundedAsync(this IBrowserContext context, string scope)
    {
        Console.WriteLine($"[UX-CLEANUP] {DateTime.UtcNow:O} {scope}:context-close:start");
        try
        {
            await context.CloseAsync().WaitAsync(CloseTimeout);
            Console.WriteLine($"[UX-CLEANUP] {DateTime.UtcNow:O} {scope}:context-close:ok");
        }
        catch (TimeoutException)
        {
            Console.WriteLine($"[UX-CLEANUP] {DateTime.UtcNow:O} {scope}:context-close:timeout:{CloseTimeout.TotalSeconds:0}s");
        }
        catch (PlaywrightException ex)
        {
            Console.WriteLine($"[UX-CLEANUP] {DateTime.UtcNow:O} {scope}:context-close:error:{ex.GetType().Name}:{ex.Message}");
        }
    }
}
