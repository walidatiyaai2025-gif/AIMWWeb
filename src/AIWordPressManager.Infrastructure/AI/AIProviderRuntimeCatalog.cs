namespace AIWordPressManager.Infrastructure.AI;

/// <summary>
/// Authoritative catalogue of AI providers that have executable runtime adapters
/// registered in the current server build.
/// </summary>
public static class AIProviderRuntimeCatalog
{
    private static readonly HashSet<string> AvailableProviderNames = new(StringComparer.OrdinalIgnoreCase)
    {
        "OpenAI",
        "Gemini",
        "Puter"
    };

    public static IReadOnlyCollection<string> AvailableProviders => AvailableProviderNames;

    public static bool IsAvailable(string? provider) =>
        !string.IsNullOrWhiteSpace(provider) && AvailableProviderNames.Contains(provider.Trim());
}
