using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Settings;

namespace AIWordPressManager.Web.Services;

public sealed record AIPromptTemplateView(
    string Key,
    string DisplayName,
    string Text,
    string EnglishText,
    string ArabicText,
    bool IsEnabled,
    int Version,
    bool IsCustomized,
    DateTime? UpdatedAtUtc,
    string UpdatedBy);

public sealed record AIPromptTemplateVersionView(
    string Key,
    int Version,
    string EnglishText,
    string ArabicText,
    bool IsEnabled,
    DateTime? CreatedAtUtc,
    string UpdatedBy,
    bool IsBuiltIn);

public sealed class AIPromptTemplateService(
    IAIPromptRegistry builtInRegistry,
    IApplicationSettingsService settingsService)
{
    public async Task<IReadOnlyList<AIPromptTemplateView>> GetAllAsync(
        string culture = "en",
        bool includeDisabled = false,
        CancellationToken cancellationToken = default)
    {
        var english = builtInRegistry.GetAll("en");
        var arabic = builtInRegistry.GetAll("ar");
        var overrides = (await settingsService.GetAiPromptTemplatesAsync(cancellationToken))
            .ToDictionary(x => x.Key, StringComparer.OrdinalIgnoreCase);
        var keys = english.Keys
            .Union(arabic.Keys, StringComparer.OrdinalIgnoreCase)
            .Union(overrides.Keys, StringComparer.OrdinalIgnoreCase)
            .OrderBy(x => x, StringComparer.OrdinalIgnoreCase);

        var result = new List<AIPromptTemplateView>();
        foreach (var key in keys)
        {
            overrides.TryGetValue(key, out var current);
            var englishText = current?.EnglishText ?? (english.TryGetValue(key, out var en) ? en : key);
            var arabicText = current?.ArabicText ?? (arabic.TryGetValue(key, out var ar) ? ar : englishText);
            var enabled = current?.IsEnabled ?? true;
            if (!includeDisabled && !enabled) continue;
            var localized = culture.StartsWith("ar", StringComparison.OrdinalIgnoreCase) ? arabicText : englishText;
            result.Add(new(
                key,
                ToDisplayName(key),
                localized,
                englishText,
                arabicText,
                enabled,
                current?.Version ?? 0,
                current is not null,
                current?.UpdatedAtUtc,
                current?.UpdatedBy ?? "built-in"));
        }
        return result;
    }

    public async Task<AIPromptTemplateView?> FindAsync(
        string key,
        string culture = "en",
        bool includeDisabled = false,
        CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(key)) return null;
        var normalized = key.Trim();
        var templates = await GetAllAsync(culture, includeDisabled, cancellationToken);
        return templates.FirstOrDefault(x => x.Key.Equals(normalized, StringComparison.OrdinalIgnoreCase));
    }

    public async Task<AIPromptTemplateView> SaveAsync(
        string key,
        string englishText,
        string arabicText,
        bool isEnabled,
        string updatedBy,
        CancellationToken cancellationToken = default)
    {
        var saved = await settingsService.SaveAiPromptTemplateAsync(
            key,
            englishText,
            arabicText,
            isEnabled,
            updatedBy,
            cancellationToken);
        return MapSaved(saved, "en");
    }

    public async Task<IReadOnlyList<AIPromptTemplateVersionView>> GetHistoryAsync(
        string key,
        CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(key)) return [];
        var normalized = key.Trim().ToLowerInvariant();
        var history = (await settingsService.GetAiPromptTemplateHistoryAsync(normalized, cancellationToken))
            .Select(x => new AIPromptTemplateVersionView(
                x.Key,
                x.Version,
                x.EnglishText,
                x.ArabicText,
                x.IsEnabled,
                x.CreatedAtUtc,
                x.UpdatedBy,
                false))
            .ToList();

        var english = builtInRegistry.GetAll("en");
        if (english.TryGetValue(normalized, out var englishText))
        {
            var arabic = builtInRegistry.GetAll("ar");
            history.Add(new(
                normalized,
                0,
                englishText,
                arabic.TryGetValue(normalized, out var arabicText) ? arabicText : englishText,
                true,
                null,
                "built-in",
                true));
        }

        return history.OrderByDescending(x => x.Version).ToArray();
    }

    public async Task<AIPromptTemplateView> RestoreAsync(
        string key,
        int version,
        string updatedBy,
        CancellationToken cancellationToken = default)
    {
        if (version == 0)
        {
            var normalized = key.Trim().ToLowerInvariant();
            var english = builtInRegistry.GetAll("en");
            if (!english.TryGetValue(normalized, out var englishText))
                throw new InvalidOperationException($"Prompt template '{normalized}' has no built-in version.");
            var arabic = builtInRegistry.GetAll("ar");
            var restoredBuiltIn = await settingsService.SaveAiPromptTemplateAsync(
                normalized,
                englishText,
                arabic.TryGetValue(normalized, out var arabicText) ? arabicText : englishText,
                true,
                updatedBy,
                cancellationToken);
            return MapSaved(restoredBuiltIn, "en");
        }

        var restored = await settingsService.RestoreAiPromptTemplateVersionAsync(
            key,
            version,
            updatedBy,
            cancellationToken);
        return MapSaved(restored, "en");
    }

    private static AIPromptTemplateView MapSaved(AiPromptTemplateSettings current, string culture) =>
        new(
            current.Key,
            ToDisplayName(current.Key),
            culture.StartsWith("ar", StringComparison.OrdinalIgnoreCase) ? current.ArabicText : current.EnglishText,
            current.EnglishText,
            current.ArabicText,
            current.IsEnabled,
            current.Version,
            true,
            current.UpdatedAtUtc,
            current.UpdatedBy);

    private static string ToDisplayName(string key)
    {
        var words = key.Replace('.', ' ').Replace('-', ' ').Replace('_', ' ')
            .Split(' ', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);
        return words.Length == 0
            ? key
            : string.Join(' ', words.Select(x => char.ToUpperInvariant(x[0]) + x[1..]));
    }
}
