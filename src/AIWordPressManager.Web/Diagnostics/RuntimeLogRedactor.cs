using System.Text.RegularExpressions;

namespace AIWordPressManager.Web.Diagnostics;

internal static partial class RuntimeLogRedactor
{
    private const string Redacted = "[REDACTED]";

    public static string Redact(string? value)
    {
        if (string.IsNullOrEmpty(value)) return string.Empty;

        var result = ConnectionStringSecretRegex().Replace(value, m => $"{m.Groups[1].Value}{Redacted}");
        result = JsonSecretRegex().Replace(result, m => $"{m.Groups[1].Value}{Redacted}{m.Groups[3].Value}");
        result = NamedSecretRegex().Replace(result, m => $"{m.Groups[1].Value}{Redacted}");
        result = BearerRegex().Replace(result, "Bearer [REDACTED]");
        return result;
    }

    public static bool IsSensitiveKey(string key) =>
        key.Contains("password", StringComparison.OrdinalIgnoreCase) ||
        key.Contains("passwd", StringComparison.OrdinalIgnoreCase) ||
        key.Contains("secret", StringComparison.OrdinalIgnoreCase) ||
        key.Contains("token", StringComparison.OrdinalIgnoreCase) ||
        key.Contains("apikey", StringComparison.OrdinalIgnoreCase) ||
        key.Contains("api_key", StringComparison.OrdinalIgnoreCase) ||
        key.Contains("api-key", StringComparison.OrdinalIgnoreCase) ||
        key.Contains("authorization", StringComparison.OrdinalIgnoreCase) ||
        key.Contains("cookie", StringComparison.OrdinalIgnoreCase) ||
        key.Contains("connectionstring", StringComparison.OrdinalIgnoreCase);

    [GeneratedRegex("(?i)(Password\\s*=\\s*|Pwd\\s*=\\s*|User ID\\s*=\\s*|UID\\s*=\\s*)([^;\\r\\n]+)")]
    private static partial Regex ConnectionStringSecretRegex();

    [GeneratedRegex("(?i)(\\\"(?:password|passwd|secret|token|api[_-]?key|authorization|cookie)\\\"\\s*:\\s*\\\")([^\\\"]*)(\\\")")]
    private static partial Regex JsonSecretRegex();

    [GeneratedRegex("(?i)((?:Authorization|Cookie|Set-Cookie|X-Api-Key|Api-Key|ApiKey|Api_Key)\\s*[:=]\\s*)([^;\\r\\n,]+)")]
    private static partial Regex NamedSecretRegex();

    [GeneratedRegex("(?i)Bearer\\s+[A-Za-z0-9._~+\\-/=]+")]
    private static partial Regex BearerRegex();
}
