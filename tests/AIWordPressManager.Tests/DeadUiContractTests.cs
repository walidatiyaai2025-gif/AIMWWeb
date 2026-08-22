using System.Text.RegularExpressions;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed partial class DeadUiContractTests
{
    [Fact]
    public void Production_Razor_must_not_expose_dead_buttons_or_placeholder_links()
    {
        var root = FindRepositoryRoot();
        var razorRoot = Path.Combine(root.FullName, "src", "AIWordPressManager.Web");
        var violations = new List<string>();

        foreach (var path in Directory.EnumerateFiles(razorRoot, "*.razor", SearchOption.AllDirectories).OrderBy(x => x))
        {
            var source = File.ReadAllText(path);
            var relative = Path.GetRelativePath(root.FullName, path).Replace('\\', '/');

            foreach (Match match in ButtonRegex().Matches(source))
            {
                var buttonMarkup = match.Groups["button"].Value;
                if (HasMeaningfulButtonAction(buttonMarkup)) continue;

                violations.Add($"{relative}:{LineOf(source, match.Index)} button has no click/keyboard action, explicit submit behavior, or form action: {Compact(buttonMarkup)[..Math.Min(Compact(buttonMarkup).Length, 220)]}");
            }

            foreach (Match match in AnchorRegex().Matches(source))
            {
                var attrs = match.Groups["attrs"].Value;
                var href = HrefRegex().Match(attrs);
                if (!href.Success) continue;

                var value = href.Groups["value"].Value.Trim();
                if (value.Length == 0 || value == "#" || value.StartsWith("javascript:", StringComparison.OrdinalIgnoreCase))
                {
                    violations.Add($"{relative}:{LineOf(source, match.Index)} placeholder anchor href '{value}': {Compact(match.Value)}");
                }
            }
        }

        violations.Should().BeEmpty(
            "user-visible controls must either execute a real action or navigate to a real destination; unsupported capabilities must render as non-interactive unavailable states instead of dead controls.\n" +
            string.Join(Environment.NewLine, violations));
    }

    [Fact]
    public void Production_Razor_must_not_reintroduce_known_runtime_demo_datasets()
    {
        var root = FindRepositoryRoot();
        var razorRoot = Path.Combine(root.FullName, "src", "AIWordPressManager.Web");
        var forbidden = new[]
        {
            "SampleRows",
            "seo-ring\">78",
            "Missing meta description on 12 pages",
            "7 images without alt text",
            "Weak internal links in 9 posts",
            "2 titles exceed recommended length",
            "backup-2026-08-0",
            "image-{i}.jpg"
        };

        var violations = new List<string>();
        foreach (var path in Directory.EnumerateFiles(razorRoot, "*.razor", SearchOption.AllDirectories).OrderBy(x => x))
        {
            var source = File.ReadAllText(path);
            var relative = Path.GetRelativePath(root.FullName, path).Replace('\\', '/');
            foreach (var marker in forbidden)
            {
                if (source.Contains(marker, StringComparison.Ordinal))
                {
                    violations.Add($"{relative} contains forbidden runtime demo marker '{marker}'.");
                }
            }
        }

        violations.Should().BeEmpty("production Razor must not present known fabricated runtime datasets.\n" + string.Join(Environment.NewLine, violations));
    }

    private static bool HasMeaningfulButtonAction(string buttonMarkup)
    {
        if (EventActionRegex().IsMatch(buttonMarkup)) return true;
        if (SubmitTypeRegex().IsMatch(buttonMarkup)) return true;
        if (FormActionRegex().IsMatch(buttonMarkup)) return true;
        return false;
    }

    private static int LineOf(string source, int index)
    {
        var line = 1;
        for (var i = 0; i < index; i++)
        {
            if (source[i] == '\n') line++;
        }
        return line;
    }

    private static string Compact(string value) => Regex.Replace(value, @"\s+", " ").Trim();

    private static DirectoryInfo FindRepositoryRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln"))) return current;
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate AIMWWeb repository root from the test output directory.");
    }

    // Match the whole button element instead of stopping at the first `>` character.
    // Razor expressions commonly contain `>` and `=>` inside attributes, which must not
    // truncate the opening tag before a later @onclick attribute is seen.
    [GeneratedRegex(@"(?<button><button\b.*?</button>)", RegexOptions.IgnoreCase | RegexOptions.Singleline)]
    private static partial Regex ButtonRegex();

    [GeneratedRegex(@"<a\b(?<attrs>[^>]*)>", RegexOptions.IgnoreCase | RegexOptions.Singleline)]
    private static partial Regex AnchorRegex();

    [GeneratedRegex("href\\s*=\\s*(?:\\\"(?<value>[^\\\"]*)\\\"|'(?<value>[^']*)')", RegexOptions.IgnoreCase | RegexOptions.Singleline)]
    private static partial Regex HrefRegex();

    // Blazor event attributes and deliberate native DOM handlers both count as real actions.
    // The latter is used for language toggles that must run before a full page reload.
    [GeneratedRegex(@"(?:@on(?:click|mousedown|mouseup|pointerdown|pointerup|keydown|keyup)|on(?:click|mousedown|mouseup|pointerdown|pointerup|keydown|keyup))\s*=", RegexOptions.IgnoreCase)]
    private static partial Regex EventActionRegex();

    [GeneratedRegex("type\\s*=\\s*(?:\\\"submit\\\"|'submit')", RegexOptions.IgnoreCase)]
    private static partial Regex SubmitTypeRegex();

    [GeneratedRegex(@"formaction\s*=", RegexOptions.IgnoreCase)]
    private static partial Regex FormActionRegex();
}
