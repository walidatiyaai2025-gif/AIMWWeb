using System.Text.RegularExpressions;
using Xunit;

namespace AIWordPressManager.Tests;

public sealed class RazorProductionAntiMockGuardTests
{
    [Fact]
    public void Production_Razor_surfaces_reject_known_placeholder_and_simulated_runtime_patterns()
    {
        var root = FindRepositoryRoot();
        var webRoot = Path.Combine(root.FullName, "src", "AIWordPressManager.Web");
        var razorFiles = Directory.EnumerateFiles(webRoot, "*.razor", SearchOption.AllDirectories).ToArray();

        Assert.NotEmpty(razorFiles);

        var literalPatterns = new[]
        {
            "href=\"#\"",
            "href='#'",
            "javascript:",
            "NotImplementedException",
            "Thread.Sleep(",
            "MockData",
            "FakeData",
            "SampleRows",
            "SampleData",
            "DemoRows",
            "DemoData"
        };

        var findings = new List<string>();
        foreach (var file in razorFiles)
        {
            var source = File.ReadAllText(file);
            foreach (var pattern in literalPatterns)
            {
                if (source.Contains(pattern, StringComparison.OrdinalIgnoreCase))
                {
                    findings.Add($"{Relative(root, file)} contains forbidden production Razor pattern '{pattern}'.");
                }
            }

            foreach (Match match in Regex.Matches(source, @"Task\.Delay\s*\(\s*[^,\)]+\s*\)", RegexOptions.IgnoreCase))
            {
                findings.Add($"{Relative(root, file)} contains a non-cancellable delay '{match.Value}' that can simulate user-visible work. Cancellable debounce delays must pass a CancellationToken.");
            }

            foreach (Match match in Regex.Matches(source, @"(?im)^\s*(?:private|protected|public)?\s*(?:static\s+)?(?:readonly\s+)?(?:string\[\]|IReadOnlyList<[^>]+>|List<[^>]+>)\s+(?<name>(?:Mock|Fake|Sample|Demo)\w*)\b"))
            {
                findings.Add($"{Relative(root, file)} declares suspicious runtime dataset '{match.Groups["name"].Value}'.");
            }
        }

        Assert.True(findings.Count == 0, "Production Razor anti-mock guard failed:\n" + string.Join("\n", findings));
    }

    private static string Relative(DirectoryInfo root, string file) => Path.GetRelativePath(root.FullName, file).Replace('\\', '/');

    private static DirectoryInfo FindRepositoryRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
            {
                return current;
            }

            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate AIMWWeb repository root from the test output directory.");
    }
}
