using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class ApplicationSetting : Entity
{
    private ApplicationSetting() { }

    public ApplicationSetting(string key, string value, DateTime utcNow)
    {
        SetValue(key, value, utcNow);
    }

    public string Key { get; private set; } = string.Empty;
    public string Value { get; private set; } = string.Empty;

    public void SetValue(string key, string value, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(key);
        Key = key.Trim();
        Value = value ?? string.Empty;
        MarkUpdated(utcNow);
    }
}
