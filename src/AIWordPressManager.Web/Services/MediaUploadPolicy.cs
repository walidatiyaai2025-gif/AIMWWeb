namespace AIWordPressManager.Web.Services;

public static class MediaUploadPolicy
{
    public const long MaxUploadSize = 25 * 1024 * 1024;

    private static readonly IReadOnlyDictionary<string, string[]> AllowedMimeTypes =
        new Dictionary<string, string[]>(StringComparer.OrdinalIgnoreCase)
        {
            [".jpg"] = ["image/jpeg"],
            [".jpeg"] = ["image/jpeg"],
            [".png"] = ["image/png"],
            [".gif"] = ["image/gif"],
            [".webp"] = ["image/webp"],
            [".avif"] = ["image/avif"],
            [".pdf"] = ["application/pdf"],
            [".doc"] = ["application/msword", "application/octet-stream"],
            [".docx"] = ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip", "application/octet-stream"],
            [".xls"] = ["application/vnd.ms-excel", "application/octet-stream"],
            [".xlsx"] = ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip", "application/octet-stream"],
            [".zip"] = ["application/zip", "application/x-zip-compressed", "application/octet-stream"]
        };

    public static MediaUploadValidationResult Validate(string? fileName, long size, string? contentType)
    {
        if (size <= 0)
            return MediaUploadValidationResult.Fail("The selected file is empty.");
        if (size > MaxUploadSize)
            return MediaUploadValidationResult.Fail("The file exceeds the 25 MB upload limit.");

        var safeFileName = SanitizeFileName(fileName);
        if (string.IsNullOrWhiteSpace(safeFileName))
            return MediaUploadValidationResult.Fail("The selected file name is invalid.");

        var extension = Path.GetExtension(safeFileName);
        if (string.IsNullOrWhiteSpace(extension) || !AllowedMimeTypes.TryGetValue(extension, out var allowedTypes))
            return MediaUploadValidationResult.Fail("This file type is not allowed for media upload.");

        var normalizedType = string.IsNullOrWhiteSpace(contentType)
            ? "application/octet-stream"
            : contentType.Trim().ToLowerInvariant();

        if (!allowedTypes.Contains(normalizedType, StringComparer.OrdinalIgnoreCase) &&
            !string.Equals(normalizedType, "application/octet-stream", StringComparison.OrdinalIgnoreCase))
        {
            return MediaUploadValidationResult.Fail("The file extension and content type do not match an allowed media type.");
        }

        var effectiveType = string.Equals(normalizedType, "application/octet-stream", StringComparison.OrdinalIgnoreCase)
            ? allowedTypes[0]
            : normalizedType;

        return MediaUploadValidationResult.Ok(safeFileName, effectiveType);
    }

    public static string SanitizeFileName(string? fileName)
    {
        if (string.IsNullOrWhiteSpace(fileName)) return string.Empty;
        var name = Path.GetFileName(fileName.Trim());
        var sanitized = new string(name.Where(ch => !char.IsControl(ch)).ToArray()).Trim();
        return sanitized.Length <= 180 ? sanitized : sanitized[^180..];
    }
}

public sealed record MediaUploadValidationResult(bool IsValid, string Message, string SafeFileName, string ContentType)
{
    public static MediaUploadValidationResult Ok(string safeFileName, string contentType) =>
        new(true, string.Empty, safeFileName, contentType);

    public static MediaUploadValidationResult Fail(string message) =>
        new(false, message, string.Empty, string.Empty);
}
