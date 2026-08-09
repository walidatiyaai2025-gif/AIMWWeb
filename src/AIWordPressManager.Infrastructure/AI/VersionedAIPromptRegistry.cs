using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Infrastructure.AI;

public sealed class VersionedAIPromptRegistry : IAIPromptRegistry, IAIPromptTemplateStore
{
    private const int SchemaVersion = 1;
    private const int MaxPromptLength = 20_000;
    private static readonly Regex KeyPattern = new("^[a-z0-9][a-z0-9.-]{0,79}$", RegexOptions.Compiled | RegexOptions.CultureInvariant);
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web) { WriteIndented = true };
    private readonly object _gate = new();
    private readonly string _filePath;
    private readonly ILogger<VersionedAIPromptRegistry> _logger;
    private PromptRegistryDocument _document;

    public VersionedAIPromptRegistry(IApplicationPathService paths, ILogger<VersionedAIPromptRegistry> logger)
    {
        _logger = logger;
        _filePath = Path.Combine(paths.GetApplicationDataDirectory(), "ai-prompt-registry.json");
        _document = Load();
        if (EnsureBuiltIns()) Persist();
    }

    public string Get(string key, string culture = "en")
    {
        if (string.IsNullOrWhiteSpace(key)) return string.Empty;
        lock (_gate)
        {
            var template = _document.Templates.FirstOrDefault(x => x.Key.Equals(key.Trim(), StringComparison.OrdinalIgnoreCase));
            if (template is null) return key.Trim();
            if (!template.Enabled) return string.Empty;
            return IsArabic(culture) ? template.PromptAr : template.PromptEn;
        }
    }

    public IReadOnlyDictionary<string, string> GetAll(string culture = "en")
    {
        lock (_gate)
        {
            return _document.Templates
                .Where(x => x.Enabled)
                .OrderBy(x => x.Key, StringComparer.OrdinalIgnoreCase)
                .ToDictionary(
                    x => x.Key,
                    x => IsArabic(culture) ? x.PromptAr : x.PromptEn,
                    StringComparer.OrdinalIgnoreCase);
        }
    }

    public IReadOnlyList<AIPromptTemplateDefinition> GetDefinitions()
    {
        lock (_gate)
        {
            return _document.Templates
                .OrderBy(x => x.Key, StringComparer.OrdinalIgnoreCase)
                .Select(ToDefinition)
                .ToArray();
        }
    }

    public AIPromptTemplateDefinition? GetDefinition(string key)
    {
        if (string.IsNullOrWhiteSpace(key)) return null;
        lock (_gate)
        {
            var template = Find(key);
            return template is null ? null : ToDefinition(template);
        }
    }

    public IReadOnlyList<AIPromptTemplateVersion> GetHistory(string key)
    {
        if (string.IsNullOrWhiteSpace(key)) return Array.Empty<AIPromptTemplateVersion>();
        lock (_gate)
        {
            var template = Find(key);
            return template is null
                ? Array.Empty<AIPromptTemplateVersion>()
                : template.Versions.OrderByDescending(x => x.Revision).Select(x => ToVersion(template.Key, x)).ToArray();
        }
    }

    public AIPromptTemplateDefinition Save(AIPromptTemplateInput input, string actor)
    {
        ArgumentNullException.ThrowIfNull(input);
        var validated = Validate(input);
        actor = NormalizeActor(actor);

        lock (_gate)
        {
            var existing = Find(validated.Key);
            if (existing is null)
            {
                var now = DateTime.UtcNow;
                var created = new PromptEntry
                {
                    Key = validated.Key,
                    TitleEn = validated.TitleEn,
                    TitleAr = validated.TitleAr,
                    PromptEn = validated.PromptEn,
                    PromptAr = validated.PromptAr,
                    Enabled = validated.Enabled,
                    Revision = 1,
                    UpdatedAtUtc = now,
                    UpdatedBy = actor,
                    IsBuiltIn = false
                };
                created.Versions.Add(ToVersionEntry(created, now, actor, "Created"));
                _document.Templates.Add(created);
                Persist();
                return ToDefinition(created);
            }

            if (SamePayload(existing, validated)) return ToDefinition(existing);

            ApplyNewRevision(existing, validated, actor, "Updated");
            Persist();
            return ToDefinition(existing);
        }
    }

    public AIPromptTemplateDefinition SetEnabled(string key, bool enabled, string actor)
    {
        actor = NormalizeActor(actor);
        lock (_gate)
        {
            var existing = Find(key) ?? throw new KeyNotFoundException($"Prompt template '{key}' was not found.");
            if (existing.Enabled == enabled) return ToDefinition(existing);

            var input = new AIPromptTemplateInput(existing.Key, existing.TitleEn, existing.TitleAr, existing.PromptEn, existing.PromptAr, enabled);
            ApplyNewRevision(existing, input, actor, enabled ? "Enabled" : "Disabled");
            Persist();
            return ToDefinition(existing);
        }
    }

    public AIPromptTemplateDefinition Restore(string key, int revision, string actor)
    {
        actor = NormalizeActor(actor);
        lock (_gate)
        {
            var existing = Find(key) ?? throw new KeyNotFoundException($"Prompt template '{key}' was not found.");
            var historical = existing.Versions.SingleOrDefault(x => x.Revision == revision)
                ?? throw new KeyNotFoundException($"Revision {revision} for prompt template '{key}' was not found.");

            var input = new AIPromptTemplateInput(
                existing.Key,
                historical.TitleEn,
                historical.TitleAr,
                historical.PromptEn,
                historical.PromptAr,
                historical.Enabled);
            ApplyNewRevision(existing, input, actor, $"Restored from r{revision}");
            Persist();
            return ToDefinition(existing);
        }
    }

    private PromptRegistryDocument Load()
    {
        if (!File.Exists(_filePath)) return new PromptRegistryDocument { SchemaVersion = SchemaVersion };

        try
        {
            var document = JsonSerializer.Deserialize<PromptRegistryDocument>(File.ReadAllText(_filePath, Encoding.UTF8), JsonOptions);
            if (document is null || document.SchemaVersion <= 0)
                throw new InvalidDataException("Prompt registry document is empty or has no schema version.");
            return document;
        }
        catch (Exception ex) when (ex is JsonException or IOException or UnauthorizedAccessException or InvalidDataException)
        {
            var quarantinePath = _filePath + $".corrupt-{DateTime.UtcNow:yyyyMMddHHmmssfff}";
            try { File.Move(_filePath, quarantinePath, overwrite: false); }
            catch (Exception moveEx) when (moveEx is IOException or UnauthorizedAccessException)
            {
                _logger.LogWarning(moveEx, "Could not quarantine corrupt AI prompt registry at {PromptRegistryPath}.", _filePath);
            }
            _logger.LogError(ex, "AI prompt registry could not be loaded. A clean built-in catalog will be created; corrupt data was preserved when possible.");
            return new PromptRegistryDocument { SchemaVersion = SchemaVersion };
        }
    }

    private bool EnsureBuiltIns()
    {
        var changed = false;
        foreach (var seed in BuiltIns)
        {
            if (Find(seed.Key) is not null) continue;
            var now = DateTime.UtcNow;
            var entry = new PromptEntry
            {
                Key = seed.Key,
                TitleEn = seed.TitleEn,
                TitleAr = seed.TitleAr,
                PromptEn = seed.PromptEn,
                PromptAr = seed.PromptAr,
                Enabled = true,
                Revision = 1,
                UpdatedAtUtc = now,
                UpdatedBy = "system",
                IsBuiltIn = true
            };
            entry.Versions.Add(ToVersionEntry(entry, now, "system", "Seeded"));
            _document.Templates.Add(entry);
            changed = true;
        }
        return changed;
    }

    private void Persist()
    {
        _document.SchemaVersion = SchemaVersion;
        var directory = Path.GetDirectoryName(_filePath) ?? throw new InvalidOperationException("Prompt registry data directory is unavailable.");
        Directory.CreateDirectory(directory);
        var temporaryPath = _filePath + $".tmp-{Guid.NewGuid():N}";
        try
        {
            File.WriteAllText(temporaryPath, JsonSerializer.Serialize(_document, JsonOptions), new UTF8Encoding(false));
            File.Move(temporaryPath, _filePath, overwrite: true);
        }
        finally
        {
            if (File.Exists(temporaryPath))
            {
                try { File.Delete(temporaryPath); }
                catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
                {
                    _logger.LogWarning(ex, "Could not remove temporary AI prompt registry file {PromptRegistryTempPath}.", temporaryPath);
                }
            }
        }
    }

    private PromptEntry? Find(string key) =>
        string.IsNullOrWhiteSpace(key)
            ? null
            : _document.Templates.FirstOrDefault(x => x.Key.Equals(key.Trim(), StringComparison.OrdinalIgnoreCase));

    private static AIPromptTemplateInput Validate(AIPromptTemplateInput input)
    {
        var key = (input.Key ?? string.Empty).Trim().ToLowerInvariant();
        if (!KeyPattern.IsMatch(key))
            throw new ArgumentException("Prompt key must be 1-80 lowercase letters, numbers, dots, or hyphens and must start with a letter or number.", nameof(input));

        var titleEn = Required(input.TitleEn, nameof(input.TitleEn), 120);
        var titleAr = Required(input.TitleAr, nameof(input.TitleAr), 120);
        var promptEn = Required(input.PromptEn, nameof(input.PromptEn), MaxPromptLength);
        var promptAr = Required(input.PromptAr, nameof(input.PromptAr), MaxPromptLength);
        return new AIPromptTemplateInput(key, titleEn, titleAr, promptEn, promptAr, input.Enabled);
    }

    private static string Required(string? value, string name, int maxLength)
    {
        var normalized = value?.Trim() ?? string.Empty;
        if (normalized.Length == 0) throw new ArgumentException($"{name} is required.", name);
        if (normalized.Length > maxLength) throw new ArgumentException($"{name} cannot exceed {maxLength} characters.", name);
        return normalized;
    }

    private static string NormalizeActor(string? actor)
    {
        var value = string.IsNullOrWhiteSpace(actor) ? "unknown" : actor.Trim();
        return value.Length <= 128 ? value : value[..128];
    }

    private static bool SamePayload(PromptEntry existing, AIPromptTemplateInput input) =>
        existing.TitleEn == input.TitleEn && existing.TitleAr == input.TitleAr &&
        existing.PromptEn == input.PromptEn && existing.PromptAr == input.PromptAr &&
        existing.Enabled == input.Enabled;

    private static void ApplyNewRevision(PromptEntry existing, AIPromptTemplateInput input, string actor, string changeType)
    {
        var now = DateTime.UtcNow;
        existing.TitleEn = input.TitleEn;
        existing.TitleAr = input.TitleAr;
        existing.PromptEn = input.PromptEn;
        existing.PromptAr = input.PromptAr;
        existing.Enabled = input.Enabled;
        existing.Revision++;
        existing.UpdatedAtUtc = now;
        existing.UpdatedBy = actor;
        existing.Versions.Add(ToVersionEntry(existing, now, actor, changeType));
    }

    private static AIPromptTemplateDefinition ToDefinition(PromptEntry entry) => new(
        entry.Key, entry.TitleEn, entry.TitleAr, entry.PromptEn, entry.PromptAr,
        entry.Enabled, entry.Revision, entry.UpdatedAtUtc, entry.UpdatedBy, entry.IsBuiltIn);

    private static AIPromptTemplateVersion ToVersion(string key, PromptVersionEntry version) => new(
        key, version.Revision, version.TitleEn, version.TitleAr, version.PromptEn, version.PromptAr,
        version.Enabled, version.CreatedAtUtc, version.CreatedBy, version.ChangeType);

    private static PromptVersionEntry ToVersionEntry(PromptEntry entry, DateTime now, string actor, string changeType) => new()
    {
        Revision = entry.Revision,
        TitleEn = entry.TitleEn,
        TitleAr = entry.TitleAr,
        PromptEn = entry.PromptEn,
        PromptAr = entry.PromptAr,
        Enabled = entry.Enabled,
        CreatedAtUtc = now,
        CreatedBy = actor,
        ChangeType = changeType
    };

    private static bool IsArabic(string? culture) => culture?.StartsWith("ar", StringComparison.OrdinalIgnoreCase) == true;

    private static readonly SeedPrompt[] BuiltIns =
    [
        new("rewrite", "Rewrite", "إعادة الصياغة", "Rewrite the content while preserving meaning, facts and HTML structure.", "أعد صياغة المحتوى مع الحفاظ على المعنى والحقائق وبنية HTML."),
        new("expand", "Expand", "توسيع المحتوى", "Expand the content with useful, non-repetitive detail while preserving HTML structure.", "وسّع المحتوى بتفاصيل مفيدة غير مكررة مع الحفاظ على بنية HTML."),
        new("summarize", "Summarize", "تلخيص", "Summarize the content accurately and preserve essential facts.", "لخّص المحتوى بدقة مع الحفاظ على الحقائق الأساسية."),
        new("proofread", "Proofread", "تدقيق لغوي", "Proofread the content and return corrected text without changing meaning or HTML structure.", "دقّق المحتوى لغويًا وأعد النص المصحح دون تغيير المعنى أو بنية HTML."),
        new("translate", "Translate", "ترجمة", "Translate the content accurately while preserving HTML tags, links and formatting.", "ترجم المحتوى بدقة مع الحفاظ على وسوم HTML والروابط والتنسيق."),
        new("seo-title-meta", "SEO title and meta", "عنوان ووصف SEO", "Generate multiple SEO title and meta-description options within recommended length limits.", "أنشئ عدة اقتراحات لعنوان SEO ووصف Meta ضمن الأطوال الموصى بها."),
        new("taxonomy", "Taxonomy", "التصنيفات والوسوم", "Suggest non-duplicate WordPress categories and tags based on the content.", "اقترح تصنيفات ووسوم WordPress غير مكررة وفق المحتوى."),
        new("alt-text", "Image alt text", "النص البديل للصورة", "Generate concise, contextual and accessible image alt text.", "أنشئ نصًا بديلًا موجزًا وسياقيًا ومتوافقًا مع الوصول."),
        new("content-brief", "Content brief", "موجز المحتوى", "Create a structured content brief including audience, intent, outline, entities and SEO notes.", "أنشئ موجز محتوى منظمًا يشمل الجمهور والنية والمخطط والكيانات وملاحظات SEO."),
        new("explain-error", "Explain error", "شرح الخطأ", "Explain the technical error clearly, preserve the original details and suggest safe diagnostic steps.", "اشرح الخطأ التقني بوضوح مع إبقاء التفاصيل الأصلية واقتراح خطوات تشخيص آمنة.")
    ];

    private sealed record SeedPrompt(string Key, string TitleEn, string TitleAr, string PromptEn, string PromptAr);

    private sealed class PromptRegistryDocument
    {
        public int SchemaVersion { get; set; } = VersionedAIPromptRegistry.SchemaVersion;
        public List<PromptEntry> Templates { get; set; } = new();
    }

    private sealed class PromptEntry
    {
        public string Key { get; set; } = string.Empty;
        public string TitleEn { get; set; } = string.Empty;
        public string TitleAr { get; set; } = string.Empty;
        public string PromptEn { get; set; } = string.Empty;
        public string PromptAr { get; set; } = string.Empty;
        public bool Enabled { get; set; }
        public int Revision { get; set; }
        public DateTime UpdatedAtUtc { get; set; }
        public string UpdatedBy { get; set; } = string.Empty;
        public bool IsBuiltIn { get; set; }
        public List<PromptVersionEntry> Versions { get; set; } = new();
    }

    private sealed class PromptVersionEntry
    {
        public int Revision { get; set; }
        public string TitleEn { get; set; } = string.Empty;
        public string TitleAr { get; set; } = string.Empty;
        public string PromptEn { get; set; } = string.Empty;
        public string PromptAr { get; set; } = string.Empty;
        public bool Enabled { get; set; }
        public DateTime CreatedAtUtc { get; set; }
        public string CreatedBy { get; set; } = string.Empty;
        public string ChangeType { get; set; } = string.Empty;
    }
}
