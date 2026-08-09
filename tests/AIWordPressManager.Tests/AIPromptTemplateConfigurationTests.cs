using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Infrastructure.AI;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Settings;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;

namespace AIWordPressManager.Tests;

[Collection("Workflow persistence")]
public sealed class AIPromptTemplateConfigurationTests
{
    [Fact]
    public async Task Saving_Prompt_Creates_Immutable_Versions_And_Restores_As_New_Version()
    {
        await using var fixture = await SettingsFixture.CreateAsync();

        var v1 = await fixture.Service.SaveAiPromptTemplateAsync(
            "rewrite",
            "English v1",
            "عربي 1",
            true,
            "admin");
        var v2 = await fixture.Service.SaveAiPromptTemplateAsync(
            "rewrite",
            "English v2",
            "عربي 2",
            false,
            "admin");
        var restored = await fixture.Service.RestoreAiPromptTemplateVersionAsync("rewrite", 1, "reviewer");

        v1.Version.Should().Be(1);
        v2.Version.Should().Be(2);
        restored.Version.Should().Be(3);
        restored.EnglishText.Should().Be("English v1");
        restored.ArabicText.Should().Be("عربي 1");
        restored.IsEnabled.Should().BeTrue();
        restored.UpdatedBy.Should().Be("reviewer");

        var history = await fixture.Service.GetAiPromptTemplateHistoryAsync("rewrite");
        history.Select(x => x.Version).Should().Equal(3, 2, 1);
        history.Single(x => x.Version == 2).EnglishText.Should().Be("English v2");
    }

    [Fact]
    public async Task Effective_Registry_Uses_Saved_Localization_And_Hides_Disabled_Templates()
    {
        await using var fixture = await SettingsFixture.CreateAsync();
        var prompts = new AIPromptTemplateService(new AIPromptRegistry(), fixture.Service);

        var baseline = await prompts.FindAsync("rewrite", "en");
        baseline.Should().NotBeNull();
        baseline!.IsCustomized.Should().BeFalse();
        baseline.Version.Should().Be(0);

        await prompts.SaveAsync("rewrite", "Managed English", "عربي مخصص", true, "admin");
        (await prompts.FindAsync("rewrite", "en"))!.Text.Should().Be("Managed English");
        (await prompts.FindAsync("rewrite", "ar"))!.Text.Should().Be("عربي مخصص");

        await prompts.SaveAsync("rewrite", "Managed English", "عربي مخصص", false, "admin");
        (await prompts.FindAsync("rewrite", "en")).Should().BeNull();
        var includingDisabled = await prompts.FindAsync("rewrite", "en", true);
        includingDisabled.Should().NotBeNull();
        includingDisabled!.IsEnabled.Should().BeFalse();
    }

    [Fact]
    public async Task BuiltIn_Restore_Creates_New_Managed_Version_Without_Deleting_History()
    {
        await using var fixture = await SettingsFixture.CreateAsync();
        var registry = new AIPromptRegistry();
        var prompts = new AIPromptTemplateService(registry, fixture.Service);

        var customized = await prompts.SaveAsync("rewrite", "Changed English", "عربي معدل", true, "admin");
        var restored = await prompts.RestoreAsync("rewrite", 0, "admin");

        restored.Version.Should().Be(customized.Version + 1);
        restored.EnglishText.Should().Be(registry.Get("rewrite", "en"));
        restored.ArabicText.Should().Be(registry.Get("rewrite", "ar"));
        var history = await prompts.GetHistoryAsync("rewrite");
        history.Should().Contain(x => x.Version == 0 && x.IsBuiltIn);
        history.Should().Contain(x => x.Version == customized.Version && x.EnglishText == "Changed English");
        history.Should().Contain(x => x.Version == restored.Version);
    }

    [Fact]
    public async Task Prompt_Key_And_Bilingual_Text_Are_Validated_Before_Persistence()
    {
        await using var fixture = await SettingsFixture.CreateAsync();

        var invalidKey = async () => await fixture.Service.SaveAiPromptTemplateAsync(
            "bad key!",
            "English",
            "عربي",
            true,
            "admin");
        var missingArabic = async () => await fixture.Service.SaveAiPromptTemplateAsync(
            "valid-key",
            "English",
            "   ",
            true,
            "admin");

        await invalidKey.Should().ThrowAsync<ArgumentException>();
        await missingArabic.Should().ThrowAsync<ArgumentException>();
        (await fixture.Context.ApplicationSettings.CountAsync()).Should().Be(0);
    }

    private sealed class TestSecretProtectionService : ISecretProtectionService
    {
        public Task<string> ProtectAsync(string plainText, CancellationToken cancellationToken = default) =>
            Task.FromResult("protected::" + plainText);

        public Task<string> UnprotectAsync(string protectedValue, CancellationToken cancellationToken = default) =>
            Task.FromResult(protectedValue.StartsWith("protected::", StringComparison.Ordinal) ? protectedValue[11..] : protectedValue);
    }

    private sealed class SettingsFixture : IAsyncDisposable
    {
        private SettingsFixture(SqliteConnection connection, AppDbContext context, ApplicationSettingsService service)
        {
            Connection = connection;
            Context = context;
            Service = service;
        }

        public SqliteConnection Connection { get; }
        public AppDbContext Context { get; }
        public ApplicationSettingsService Service { get; }

        public static async Task<SettingsFixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            var configuration = new ConfigurationBuilder().AddInMemoryCollection().Build();
            var service = new ApplicationSettingsService(context, configuration, new TestSecretProtectionService());
            return new SettingsFixture(connection, context, service);
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
