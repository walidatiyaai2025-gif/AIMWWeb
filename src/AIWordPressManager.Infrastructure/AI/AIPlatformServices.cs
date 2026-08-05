using System.Collections.Concurrent;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.Abstractions.AI;
using Microsoft.Extensions.Configuration;

namespace AIWordPressManager.Infrastructure.AI;

public sealed class AIPromptRegistry : IAIPromptRegistry
{
    private static readonly IReadOnlyDictionary<string, string> English = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
    {
        ["rewrite"] = "Rewrite the content while preserving meaning, facts and HTML structure.",
        ["expand"] = "Expand the content with useful, non-repetitive detail while preserving HTML structure.",
        ["summarize"] = "Summarize the content accurately and preserve essential facts.",
        ["proofread"] = "Proofread the content and return corrected text without changing meaning or HTML structure.",
        ["translate"] = "Translate the content accurately while preserving HTML tags, links and formatting.",
        ["seo-title-meta"] = "Generate multiple SEO title and meta-description options within recommended length limits.",
        ["taxonomy"] = "Suggest non-duplicate WordPress categories and tags based on the content.",
        ["alt-text"] = "Generate concise, contextual and accessible image alt text.",
        ["content-brief"] = "Create a structured content brief including audience, intent, outline, entities and SEO notes.",
        ["explain-error"] = "Explain the technical error clearly, preserve the original details and suggest safe diagnostic steps."
    };

    private static readonly IReadOnlyDictionary<string, string> Arabic = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
    {
        ["rewrite"] = "أعد صياغة المحتوى مع الحفاظ على المعنى والحقائق وبنية HTML.",
        ["expand"] = "وسّع المحتوى بتفاصيل مفيدة غير مكررة مع الحفاظ على بنية HTML.",
        ["summarize"] = "لخّص المحتوى بدقة مع الحفاظ على الحقائق الأساسية.",
        ["proofread"] = "دقّق المحتوى لغويًا وأعد النص المصحح دون تغيير المعنى أو بنية HTML.",
        ["translate"] = "ترجم المحتوى بدقة مع الحفاظ على وسوم HTML والروابط والتنسيق.",
        ["seo-title-meta"] = "أنشئ عدة اقتراحات لعنوان SEO ووصف Meta ضمن الأطوال الموصى بها.",
        ["taxonomy"] = "اقترح تصنيفات ووسوم WordPress غير مكررة وفق المحتوى.",
        ["alt-text"] = "أنشئ نصًا بديلًا موجزًا وسياقيًا ومتوافقًا مع الوصول.",
        ["content-brief"] = "أنشئ موجز محتوى منظمًا يشمل الجمهور والنية والمخطط والكيانات وملاحظات SEO.",
        ["explain-error"] = "اشرح الخطأ التقني بوضوح مع إبقاء التفاصيل الأصلية واقتراح خطوات تشخيص آمنة."
    };

    public string Get(string key, string culture = "en")
    {
        var source = culture.StartsWith("ar", StringComparison.OrdinalIgnoreCase) ? Arabic : English;
        return source.TryGetValue(key, out var value) ? value : key;
    }

    public IReadOnlyDictionary<string, string> GetAll(string culture = "en") =>
        culture.StartsWith("ar", StringComparison.OrdinalIgnoreCase) ? Arabic : English;
}

public sealed class AIUsageLog : IAIUsageLog
{
    private readonly ConcurrentQueue<AIUsageEntry> _entries = new();

    public void Record(AIUsageEntry entry)
    {
        _entries.Enqueue(entry);
        while (_entries.Count > 2000 && _entries.TryDequeue(out _)) { }
    }

    public IReadOnlyList<AIUsageEntry> GetRecent(int take = 100, Guid? siteId = null, string? userId = null) =>
        _entries.Reverse()
            .Where(x => !siteId.HasValue || x.SiteId == siteId)
            .Where(x => string.IsNullOrWhiteSpace(userId) || string.Equals(x.UserId, userId, StringComparison.OrdinalIgnoreCase))
            .Take(Math.Clamp(take, 1, 500))
            .ToArray();
}

public sealed class AIContentProtector : IAIContentProtector
{
    private readonly ConcurrentDictionary<string, DailyQuota> _quotas = new(StringComparer.OrdinalIgnoreCase);
    private const int DailyLimit = 100;

    private static readonly Regex SecretRegex = new(
        "(?i)(api[_ -]?key|authorization|password|secret|token)\\s*[:=]\\s*[^\\s,;]+",
        RegexOptions.Compiled | RegexOptions.CultureInvariant);

    public string Redact(string input) => SecretRegex.Replace(input ?? string.Empty, "$1=[REDACTED]");

    public bool TryConsume(string identity, int amount, out int remaining)
    {
        identity = string.IsNullOrWhiteSpace(identity) ? "anonymous" : identity.Trim();
        amount = Math.Max(1, amount);
        var today = DateOnly.FromDateTime(DateTime.UtcNow);

        while (true)
        {
            var current = _quotas.GetOrAdd(identity, _ => new DailyQuota(today, 0));
            if (current.Day != today)
            {
                if (_quotas.TryUpdate(identity, new DailyQuota(today, amount), current))
                {
                    remaining = Math.Max(0, DailyLimit - amount);
                    return amount <= DailyLimit;
                }
                continue;
            }

            if (current.Used + amount > DailyLimit)
            {
                remaining = Math.Max(0, DailyLimit - current.Used);
                return false;
            }

            var next = current with { Used = current.Used + amount };
            if (_quotas.TryUpdate(identity, next, current))
            {
                remaining = DailyLimit - next.Used;
                return true;
            }
        }
    }

    private sealed record DailyQuota(DateOnly Day, int Used);
}

public sealed class AIOrchestrator(
    IEnumerable<IAIProvider> providers,
    IAIUsageLog usageLog,
    IAIContentProtector protector) : IAIOrchestrator
{
    public async Task<AIResponse> ExecuteAsync(AIRequest request, CancellationToken cancellationToken = default)
    {
        var identity = request.UserId ?? request.SiteId?.ToString() ?? "anonymous";
        if (!protector.TryConsume(identity, 1, out _))
            return new(false, string.Empty, "quota", request.Model, 0, 0, 0, "Daily AI quota exceeded.");

        var protectedRequest = request with
        {
            Prompt = protector.Redact(request.Prompt),
            SystemPrompt = protector.Redact(request.SystemPrompt ?? string.Empty)
        };

        AIResponse? last = null;
        foreach (var provider in providers)
        {
            for (var attempt = 1; attempt <= 2; attempt++)
            {
                try
                {
                    last = await provider.GenerateAsync(protectedRequest, cancellationToken);
                    Record(request, last);
                    if (last.IsSuccess) return last;
                }
                catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
                {
                    throw;
                }
                catch (Exception ex)
                {
                    last = new(false, string.Empty, provider.Name, request.Model, 0, 0, 0, ex.Message);
                    Record(request, last);
                }

                if (attempt == 1) await Task.Delay(300, cancellationToken);
            }
        }

        return last ?? new(false, string.Empty, "none", request.Model, 0, 0, 0, "No AI provider is configured.");
    }

    private void Record(AIRequest request, AIResponse response) => usageLog.Record(new(
        DateTime.UtcNow,
        response.Provider,
        response.Model,
        request.Operation,
        request.SiteId,
        request.UserId,
        response.InputTokens,
        response.OutputTokens,
        response.EstimatedCost,
        response.IsSuccess,
        response.Error));
}

public abstract class JsonHttpAIProvider(HttpClient httpClient, IConfiguration configuration) : IAIProvider
{
    protected HttpClient HttpClient { get; } = httpClient;
    protected IConfiguration Configuration { get; } = configuration;
    public abstract string Name { get; }
    public abstract Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default);

    protected static int EstimateTokens(string? value) => string.IsNullOrWhiteSpace(value) ? 0 : Math.Max(1, value.Length / 4);

    protected static StringContent JsonContent(object payload) =>
        new(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");
}

public sealed class OpenAIProvider(HttpClient httpClient, IConfiguration configuration)
    : JsonHttpAIProvider(httpClient, configuration)
{
    public override string Name => "OpenAI";

    public override async Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default)
    {
        var apiKey = Configuration["AI:OpenAI:ApiKey"];
        if (string.IsNullOrWhiteSpace(apiKey)) return new(false, "", Name, request.Model, 0, 0, 0, "OpenAI API key is not configured.");
        var model = request.Model ?? Configuration["AI:OpenAI:Model"] ?? "gpt-4.1-mini";
        using var message = new HttpRequestMessage(HttpMethod.Post, "https://api.openai.com/v1/responses");
        message.Headers.Authorization = new AuthenticationHeaderValue("Bearer", apiKey);
        message.Content = JsonContent(new { model, instructions = request.SystemPrompt, input = request.Prompt, max_output_tokens = request.MaxOutputTokens, temperature = request.Temperature });
        using var response = await HttpClient.SendAsync(message, cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode) return new(false, "", Name, model, EstimateTokens(request.Prompt), 0, 0, $"HTTP {(int)response.StatusCode}: {body}");
        using var json = JsonDocument.Parse(body);
        var content = json.RootElement.TryGetProperty("output_text", out var output) ? output.GetString() ?? "" : body;
        return new(true, content, Name, model, EstimateTokens(request.Prompt), EstimateTokens(content), 0);
    }
}

public sealed class GeminiProvider(HttpClient httpClient, IConfiguration configuration)
    : JsonHttpAIProvider(httpClient, configuration)
{
    public override string Name => "Gemini";

    public override async Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default)
    {
        var apiKey = Configuration["AI:Gemini:ApiKey"];
        if (string.IsNullOrWhiteSpace(apiKey)) return new(false, "", Name, request.Model, 0, 0, 0, "Gemini API key is not configured.");
        var model = request.Model ?? Configuration["AI:Gemini:Model"] ?? "gemini-2.5-flash";
        var url = $"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={Uri.EscapeDataString(apiKey)}";
        using var response = await HttpClient.PostAsync(url, JsonContent(new { contents = new[] { new { parts = new[] { new { text = $"{request.SystemPrompt}\n\n{request.Prompt}" } } } }, generationConfig = new { temperature = request.Temperature, maxOutputTokens = request.MaxOutputTokens } }), cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode) return new(false, "", Name, model, EstimateTokens(request.Prompt), 0, 0, $"HTTP {(int)response.StatusCode}: {body}");
        using var json = JsonDocument.Parse(body);
        var content = json.RootElement.GetProperty("candidates")[0].GetProperty("content").GetProperty("parts")[0].GetProperty("text").GetString() ?? "";
        return new(true, content, Name, model, EstimateTokens(request.Prompt), EstimateTokens(content), 0);
    }
}

public sealed class PuterProvider(HttpClient httpClient, IConfiguration configuration)
    : JsonHttpAIProvider(httpClient, configuration)
{
    public override string Name => "Puter";

    public override async Task<AIResponse> GenerateAsync(AIRequest request, CancellationToken cancellationToken = default)
    {
        var endpoint = Configuration["AI:Puter:Endpoint"];
        var token = Configuration["AI:Puter:Token"];
        if (string.IsNullOrWhiteSpace(endpoint)) return new(false, "", Name, request.Model, 0, 0, 0, "Puter endpoint is not configured.");
        using var message = new HttpRequestMessage(HttpMethod.Post, endpoint);
        if (!string.IsNullOrWhiteSpace(token)) message.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        message.Content = JsonContent(new { prompt = request.Prompt, system = request.SystemPrompt, model = request.Model, temperature = request.Temperature, max_tokens = request.MaxOutputTokens });
        using var response = await HttpClient.SendAsync(message, cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode) return new(false, "", Name, request.Model, EstimateTokens(request.Prompt), 0, 0, $"HTTP {(int)response.StatusCode}: {body}");
        return new(true, body, Name, request.Model, EstimateTokens(request.Prompt), EstimateTokens(body), 0);
    }
}
