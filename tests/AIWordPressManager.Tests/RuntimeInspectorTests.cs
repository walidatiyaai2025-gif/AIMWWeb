using AIWordPressManager.Web.Diagnostics;
using FluentAssertions;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Tests;

public sealed class RuntimeInspectorTests
{
    [Fact]
    public void Redactor_Removes_Common_Secrets()
    {
        var input = "Password=top-secret;Api-Key: abc123\nAuthorization: Bearer token-value\n{\"token\":\"json-secret\"}";

        var redacted = RuntimeLogRedactor.Redact(input);

        redacted.Should().NotContain("top-secret");
        redacted.Should().NotContain("abc123");
        redacted.Should().NotContain("token-value");
        redacted.Should().NotContain("json-secret");
        redacted.Should().Contain("[REDACTED]");
    }

    [Theory]
    [InlineData("password")]
    [InlineData("AdminPassword")]
    [InlineData("api_key")]
    [InlineData("Authorization")]
    [InlineData("Cookie")]
    [InlineData("refreshToken")]
    public void Sensitive_Key_Detection_Is_Case_Insensitive(string key)
    {
        RuntimeLogRedactor.IsSensitiveKey(key).Should().BeTrue();
    }

    [Fact]
    public void File_Logger_Writes_Error_Log_And_Redacts_Structured_Secrets()
    {
        var directory = Path.Combine(Path.GetTempPath(), "aimw-runtime-inspector-tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(directory);

        try
        {
            var options = new RuntimeInspectorOptions
            {
                Enabled = true,
                LogDirectory = directory,
                RetainedDays = 1,
                MaxFileSizeBytes = 1024 * 1024
            };

            using var provider = new RuntimeFileLoggerProvider(options);
            using var factory = LoggerFactory.Create(logging =>
            {
                logging.SetMinimumLevel(LogLevel.Trace);
                logging.AddProvider(provider);
            });

            var logger = factory.CreateLogger("RuntimeInspectorTests");
            logger.LogError(
                new InvalidOperationException("Authorization: Bearer exception-secret"),
                "Operation failed for {UserId}; Password={Password}; Token={Token}",
                "user-42",
                "password-secret",
                "token-secret");

            var errorFile = Directory.EnumerateFiles(directory, "errors-*.log").Single();
            var content = File.ReadAllText(errorFile);

            content.Should().Contain("RuntimeInspectorTests");
            content.Should().Contain("user-42");
            content.Should().Contain("[REDACTED]");
            content.Should().NotContain("password-secret");
            content.Should().NotContain("token-secret");
            content.Should().NotContain("exception-secret");
        }
        finally
        {
            Directory.Delete(directory, recursive: true);
        }
    }
}
