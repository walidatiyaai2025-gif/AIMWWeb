namespace AIWordPressManager.Web.Diagnostics;

public sealed class RuntimeInspectorOptions
{
    public const string SectionName = "RuntimeInspector";

    public bool Enabled { get; set; } = true;
    public string LogDirectory { get; set; } = @"C:\ProgramData\AIMWWeb\Logs";
    public int RetainedDays { get; set; } = 30;
    public long MaxFileSizeBytes { get; set; } = 20 * 1024 * 1024;
    public bool IncludeRequestQueryString { get; set; }
}
