using System.Diagnostics.CodeAnalysis;

namespace AIWordPressManager.Application.Common.Results;

[SuppressMessage(
    "Naming",
    "CA1716:Identifiers should not match keywords",
    Justification = "Error is the established domain term used by the Result pattern.")]
public sealed record Error(string Code, string Message)
{
    public static readonly Error None = new(string.Empty, string.Empty);

    public static Error Validation(string message) => Create("Validation", message);

    public static Error NotFound(string message) => Create("NotFound", message);

    public static Error Conflict(string message) => Create("Conflict", message);

    public static Error Unauthorized(string message) => Create("Unauthorized", message);

    public static Error Forbidden(string message) => Create("Forbidden", message);

    public static Error Failure(string message) => Create("Failure", message);

    private static Error Create(string code, string message)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(message);
        return new Error(code, message.Trim());
    }
}
