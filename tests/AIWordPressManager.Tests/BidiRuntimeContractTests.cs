using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class BidiRuntimeContractTests
{
    [Fact]
    public void Bidi_sync_does_not_rewrite_observed_attributes_when_values_are_unchanged()
    {
        var script = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/bidi-runtime.js");

        script.Should().Contain("observer.observe(root, { attributes: true, attributeFilter: ['dir', 'lang'] });");
        script.Should().Contain("if (root.lang !== language) root.lang = language;");
        script.Should().Contain("if (root.dir !== direction) root.dir = direction;");
        script.Should().Contain("if (document.body.dir !== direction) document.body.dir = direction;");
        script.Should().Contain("document.addEventListener('DOMContentLoaded', sync, { once: true });");
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            var solution = Path.Combine(current.FullName, "AIWordPressManager.Web.sln");
            if (File.Exists(solution)) return File.ReadAllText(Path.Combine(current.FullName, relativePath));
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate repository root from test output.");
    }
}
