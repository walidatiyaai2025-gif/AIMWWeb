using System.Text.RegularExpressions;
using Xunit;

namespace AIWordPressManager.Tests;

public sealed class GlobalSynchronizationWorkspaceHonestyContractTests
{
    [Fact]
    public void Synchronization_failure_does_not_swallow_history_refresh_failure()
    {
        var root = FindRepositoryRoot();
        var path = Path.Combine(root.FullName, "src", "AIWordPressManager.Web", "Components", "Pages", "GlobalSynchronizationWorkspace.razor");
        var source = File.ReadAllText(path);

        Assert.Contains("catch (Exception historyEx)", source, StringComparison.Ordinal);
        Assert.Contains("Synchronization history could not be refreshed", source, StringComparison.Ordinal);
        Assert.Contains("_history = [];", source, StringComparison.Ordinal);
        Assert.DoesNotMatch(new Regex(@"catch\s*\{\s*\}", RegexOptions.Singleline), source);
    }

    private static DirectoryInfo FindRepositoryRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln")))
                return current;

            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate AIMWWeb repository root from the test output directory.");
    }
}
