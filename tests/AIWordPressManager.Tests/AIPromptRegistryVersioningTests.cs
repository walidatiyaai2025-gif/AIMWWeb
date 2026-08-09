using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Infrastructure.AI;
using FluentAssertions;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class AIPromptRegistryVersioningTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "aiwm-prompt-tests", Guid.NewGuid().ToString("N"));

    [Fact]
    public void BuiltIn_Catalog_Is_Bilingual_And_Persisted()
    {
        var registry = CreateRegistry();

        registry.Get("rewrite", "en").Should().Contain("Rewrite");
        registry.Get("rewrite", "ar").Should().Contain("أعد");
        registry.GetDefinitions().Should().Contain(x => x.Key == "alt-text" && x.IsBuiltIn && x.Revision == 1);
        File.Exists(Path.Combine(_root, "Data", "ai-prompt-registry.json")).Should().BeTrue();
    }

    [Fact]
    public void Save_Is_Idempotent_For_Unchanged_Content_And_Persists_Across_Restart()
    {
        var registry = CreateRegistry();
        var input = new AIPromptTemplateInput(
            "custom.editorial",
            "Editorial review",
            "مراجعة تحريرية",
            "Review this draft for editorial quality.",
            "راجع هذه المسودة من ناحية الجودة التحريرية.",
            true);

        var created = registry.Save(input, "admin");
        var unchanged = registry.Save(input, "another-admin");

        created.Revision.Should().Be(1);
        unchanged.Revision.Should().Be(1);
        registry.GetHistory(input.Key).Should().HaveCount(1);

        var afterRestart = CreateRegistry();
        afterRestart.Get(input.Key, "en").Should().Be(input.PromptEn);
        afterRestart.GetDefinition(input.Key)!.Revision.Should().Be(1);
    }

    [Fact]
    public void Update_And_Restore_Append_New_Immutable_Revisions()
    {
        var registry = CreateRegistry();
        var original = new AIPromptTemplateInput(
            "custom.versioned",
            "Versioned",
            "ذو إصدارات",
            "Original prompt.",
            "الأمر الأصلي.",
            true);
        registry.Save(original, "admin");

        var updated = registry.Save(original with { PromptEn = "Updated prompt.", PromptAr = "الأمر المحدث." }, "editor");
        var restored = registry.Restore(original.Key, 1, "admin");

        updated.Revision.Should().Be(2);
        restored.Revision.Should().Be(3);
        restored.PromptEn.Should().Be("Original prompt.");
        var history = registry.GetHistory(original.Key);
        history.Select(x => x.Revision).Should().Equal(3, 2, 1);
        history.Single(x => x.Revision == 3).ChangeType.Should().Contain("r1");
        history.Single(x => x.Revision == 2).PromptEn.Should().Be("Updated prompt.");
    }

    [Fact]
    public void Disabled_Template_Is_Not_Exposed_To_Runtime_But_History_Is_Preserved()
    {
        var registry = CreateRegistry();

        var disabled = registry.SetEnabled("rewrite", false, "admin");

        disabled.Enabled.Should().BeFalse();
        disabled.Revision.Should().Be(2);
        registry.Get("rewrite", "en").Should().BeEmpty();
        registry.GetAll("en").Should().NotContainKey("rewrite");
        registry.GetHistory("rewrite").Should().HaveCount(2);
    }

    [Fact]
    public void Invalid_Custom_Key_Is_Rejected()
    {
        var registry = CreateRegistry();
        var input = new AIPromptTemplateInput("Bad Key!", "Title", "عنوان", "Prompt", "أمر", true);

        var action = () => registry.Save(input, "admin");

        action.Should().Throw<ArgumentException>().WithMessage("*Prompt key*");
    }

    private VersionedAIPromptRegistry CreateRegistry() =>
        new(new TestPathService(_root), NullLogger<VersionedAIPromptRegistry>.Instance);

    public void Dispose()
    {
        try
        {
            if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true);
        }
        catch (IOException) { }
        catch (UnauthorizedAccessException) { }
    }

    private sealed class TestPathService(string root) : IApplicationPathService
    {
        public string GetApplicationDataDirectory() => Ensure("Data");
        public string GetDatabasePath() => Path.Combine(GetApplicationDataDirectory(), "test.db");
        public string GetLogsDirectory() => Ensure("Logs");
        public string GetScreenshotsDirectory() => Ensure("Screenshots");
        public string GetBackupsDirectory() => Ensure("Backups");
        public string GetExportsDirectory() => Ensure("Exports");
        public string GetTemporaryDirectory() => Ensure("Temp");

        private string Ensure(string name)
        {
            var path = Path.Combine(root, name);
            Directory.CreateDirectory(path);
            return path;
        }
    }
}
