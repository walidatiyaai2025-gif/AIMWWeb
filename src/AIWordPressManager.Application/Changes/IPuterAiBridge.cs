namespace AIWordPressManager.Application.Changes;

/// <summary>
/// Bridges the desktop WebView2-hosted Puter.js session to the provider-neutral AI layer.
/// Puter uses a user-pays browser session and does not require an application API key.
/// </summary>
public interface IPuterAiBridge
{
    Task<string> ChatAsync(string prompt, string model, CancellationToken cancellationToken = default);
    Task<AiProviderTestResult> ConnectAndTestAsync(string model, CancellationToken cancellationToken = default);
}
