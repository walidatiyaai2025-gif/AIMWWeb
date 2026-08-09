using System.Text.Json;

namespace AIWordPressManager.Application.AI;

public sealed record AISuggestion(
    string Before,
    string After,
    string Explanation,
    double Confidence,
    IReadOnlyList<string> AffectedFields);

public static class AISuggestionContract
{
    private const int MaxAfterLength = 50_000;
    private const int MaxExplanationLength = 8_000;
    private const int MaxAffectedFields = 20;
    private const int MaxAffectedFieldLength = 80;

    public static string BuildSystemPrompt(string? baseInstruction, string culture = "en")
    {
        var language = culture.StartsWith("ar", StringComparison.OrdinalIgnoreCase) ? "Arabic" : "English";
        var baseText = string.IsNullOrWhiteSpace(baseInstruction)
            ? "Improve the supplied content while preserving its intent and factual meaning."
            : baseInstruction.Trim();

        return $"""
{baseText}

Return a reviewable change proposal as ONE valid JSON object and no other text.
The JSON schema is exactly:
{{
  "after": "the complete proposed replacement content",
  "explanation": "why this change is recommended",
  "confidence": 0.0,
  "affectedFields": ["content"]
}}
Rules:
- `after` must be complete replacement content, not commentary.
- `explanation` must be concise and written in {language}.
- `confidence` must be a JSON number from 0.0 through 1.0.
- `affectedFields` must contain 1 to {MaxAffectedFields} stable field identifiers such as `title`, `content`, `excerpt`, or `seo.metaDescription`.
- Do not return Markdown fences, prose outside the JSON object, or a `before` field. The application preserves the authoritative original value itself.
""";
    }

    public static bool TryParse(string before, string rawOutput, out AISuggestion? suggestion, out string error)
    {
        suggestion = null;
        error = string.Empty;

        if (string.IsNullOrWhiteSpace(before))
        {
            error = "Original content is required.";
            return false;
        }

        if (string.IsNullOrWhiteSpace(rawOutput))
        {
            error = "AI suggestion output is empty.";
            return false;
        }

        try
        {
            using var document = JsonDocument.Parse(StripOptionalCodeFence(rawOutput));
            var root = document.RootElement;
            if (root.ValueKind != JsonValueKind.Object)
            {
                error = "AI suggestion must be a JSON object.";
                return false;
            }

            if (!TryGetString(root, "after", out var after) || string.IsNullOrWhiteSpace(after))
            {
                error = "AI suggestion is missing a non-empty `after` value.";
                return false;
            }

            if (after.Length > MaxAfterLength)
            {
                error = $"AI suggestion `after` exceeds {MaxAfterLength} characters.";
                return false;
            }

            if (!TryGetString(root, "explanation", out var explanation) || string.IsNullOrWhiteSpace(explanation))
            {
                error = "AI suggestion is missing a non-empty `explanation` value.";
                return false;
            }

            if (explanation.Length > MaxExplanationLength)
            {
                error = $"AI suggestion `explanation` exceeds {MaxExplanationLength} characters.";
                return false;
            }

            if (!TryGetProperty(root, "confidence", out var confidenceElement) ||
                confidenceElement.ValueKind != JsonValueKind.Number ||
                !confidenceElement.TryGetDouble(out var confidence) ||
                double.IsNaN(confidence) ||
                double.IsInfinity(confidence) ||
                confidence is < 0 or > 1)
            {
                error = "AI suggestion `confidence` must be a number from 0.0 through 1.0.";
                return false;
            }

            if (!TryGetProperty(root, "affectedFields", out var fieldsElement) || fieldsElement.ValueKind != JsonValueKind.Array)
            {
                error = "AI suggestion `affectedFields` must be an array.";
                return false;
            }

            var fields = new List<string>();
            foreach (var element in fieldsElement.EnumerateArray())
            {
                if (element.ValueKind != JsonValueKind.String) continue;
                var field = element.GetString()?.Trim();
                if (string.IsNullOrWhiteSpace(field)) continue;
                if (field.Length > MaxAffectedFieldLength)
                {
                    error = $"Affected field identifiers cannot exceed {MaxAffectedFieldLength} characters.";
                    return false;
                }
                if (!fields.Contains(field, StringComparer.OrdinalIgnoreCase)) fields.Add(field);
                if (fields.Count > MaxAffectedFields)
                {
                    error = $"AI suggestion cannot contain more than {MaxAffectedFields} affected fields.";
                    return false;
                }
            }

            if (fields.Count == 0)
            {
                error = "AI suggestion must identify at least one affected field.";
                return false;
            }

            suggestion = new AISuggestion(before, after.Trim(), explanation.Trim(), confidence, fields.ToArray());
            return true;
        }
        catch (JsonException ex)
        {
            error = $"AI suggestion is not valid JSON: {ex.Message}";
            return false;
        }
    }

    private static bool TryGetString(JsonElement root, string name, out string value)
    {
        value = string.Empty;
        if (!TryGetProperty(root, name, out var element) || element.ValueKind != JsonValueKind.String) return false;
        value = element.GetString() ?? string.Empty;
        return true;
    }

    private static bool TryGetProperty(JsonElement root, string name, out JsonElement value)
    {
        foreach (var property in root.EnumerateObject())
        {
            if (property.Name.Equals(name, StringComparison.OrdinalIgnoreCase))
            {
                value = property.Value;
                return true;
            }
        }

        value = default;
        return false;
    }

    private static string StripOptionalCodeFence(string rawOutput)
    {
        var text = rawOutput.Trim();
        if (!text.StartsWith("```", StringComparison.Ordinal)) return text;

        var firstNewLine = text.IndexOf('\n');
        var closingFence = text.LastIndexOf("```", StringComparison.Ordinal);
        if (firstNewLine < 0 || closingFence <= firstNewLine) return text;
        return text[(firstNewLine + 1)..closingFence].Trim();
    }
}
