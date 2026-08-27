using System.Reflection;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using FluentAssertions;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

/// <summary>
/// Browser-level acceptance for the AI remediation surface. These tests deliberately
/// enter through the InteractiveServer page: they do not invoke component handlers or
/// the remediation service directly.
/// </summary>
[Collection(UxRegressionCollection.Name)]
public sealed class SeoRemediationUxTests(UxTestHost host)
{
    [Fact]
    public async Task Provider_not_configured_blocks_generation_without_spinner_or_proposals()
    {
        var siteId = await SeedAdminSeoSiteAsync("Provider unavailable SEO article");
        await using var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
        var page = await context.NewPageAsync();
        var pageErrors = new List<string>();
        page.PageError += (_, message) => pageErrors.Add(message);

        await OpenWorkspaceAsync(page, siteId);
        var generate = page.GetByTestId("seo-generate-all");
        await generate.WaitForAsync(new() { State = WaitForSelectorState.Visible, Timeout = 10_000 });
        await Assertions.Expect(page.GetByTestId("seo-provider-readiness")).ToContainTextAsync("AI Provider: Not configured");
        await Assertions.Expect(page.GetByTestId("seo-provider-readiness")).ToContainTextAsync("Configuration required");
        (await generate.IsDisabledAsync()).Should().BeTrue();
        await Assertions.Expect(page.GetByTestId("seo-configure-ai-provider")).ToBeVisibleAsync();
        (await page.GetByText("Generating suggestions...", new() { Exact = true }).CountAsync()).Should().Be(0);
        var rows = page.Locator("[data-testid^='proposal-row-']");
        (await rows.CountAsync()).Should().Be(0, "blocked generation must not create fake or failed proposal rows");
        (await page.GetByTestId("seo-apply-selected").IsEnabledAsync()).Should().BeFalse();
        (await page.GetByTestId("seo-apply-all-safe").IsEnabledAsync()).Should().BeFalse();
        pageErrors.Should().BeEmpty();
    }

    [Fact]
    public async Task Configured_provider_enables_generation_and_only_then_enters_generating_state()
    {
        var siteId = await SeedAdminSeoSiteAsync("Configured provider SEO article");
        var keys = new[] { "AI.OpenAI.Enabled", "AI.OpenAI.Priority", "AI.OpenAI.ProtectedApiKey" };
        var previous = await ReadSettingsAsync(keys);
        try
        {
            await SetSettingAsync(keys[0], "True");
            await SetSettingAsync(keys[1], "1");
            await SetSettingAsync(keys[2], "configured-for-readiness-acceptance");
            await using var context = await host.CreateContextAsync(UxRouteCatalog.Viewports[^1]);
            var page = await context.NewPageAsync();

            await OpenWorkspaceAsync(page, siteId);
            var generate = page.GetByTestId("seo-generate-all");
            await Assertions.Expect(page.GetByTestId("seo-provider-readiness")).ToContainTextAsync("AI Provider: OpenAI");
            await Assertions.Expect(page.GetByTestId("seo-provider-readiness")).ToContainTextAsync("Status: Ready");
            (await generate.IsEnabledAsync()).Should().BeTrue();
            (await page.GetByText("Generating suggestions...", new() { Exact = true }).CountAsync()).Should().Be(0);

            await generate.ClickAsync();
            await Assertions.Expect(generate).ToContainTextAsync("Generating suggestions", new() { Timeout = 5_000 });
        }
        finally
        {
            await RestoreSettingsAsync(previous);
        }
    }

    private async Task OpenWorkspaceAsync(IPage page, Guid siteId)
    {
        var response = await page.GotoAsync(host.BaseUrl + $"/sites/{siteId}/seo", new PageGotoOptions
        {
            WaitUntil = WaitUntilState.DOMContentLoaded,
            Timeout = 12_000
        });
        response.Should().NotBeNull();
        response!.Status.Should().BeLessThan(400);
        await page.GetByTestId("seo-remediation-workspace").WaitForAsync(new()
        {
            State = WaitForSelectorState.Visible,
            Timeout = 10_000
        });
    }

    private async Task<Guid> SeedAdminSeoSiteAsync(string title)
    {
        await using var db = OpenFixtureDb();
        var admin = await db.AuthUsers.SingleAsync(x => x.NormalizedUserName == "ADMIN");
        var now = DateTime.UtcNow;
        var site = new Site("SEO remediation browser acceptance", new Uri("https://seo-remediation.example.test"), now, admin.Id);
        db.Sites.Add(site);
        var content = new WordPressContentRecord(site.Id, 9701, "post", now);
        content.Update(title, "provider-unavailable-seo-article", "publish",
            "https://seo-remediation.example.test/provider-unavailable-seo-article",
            "<p>Persisted WordPress source used by browser remediation acceptance.</p>",
            "Persisted source excerpt", now.AddDays(-1), now);
        db.WordPressContentRecords.Add(content);
        await db.SaveChangesAsync();
        return site.Id;
    }

    private AppDbContext OpenFixtureDb()
    {
        var method = typeof(UxTestHost).GetMethod("CreateDbContext", BindingFlags.Instance | BindingFlags.NonPublic);
        method.Should().NotBeNull();
        return (AppDbContext)method!.Invoke(host, null)!;
    }

    private async Task SetSettingAsync(string key, string value)
    {
        await using var db = OpenFixtureDb();
        var setting = await db.ApplicationSettings.SingleOrDefaultAsync(x => x.Key == key);
        if (setting is null)
            db.ApplicationSettings.Add(new ApplicationSetting(key, value, DateTime.UtcNow));
        else
            setting.SetValue(key, value, DateTime.UtcNow);
        await db.SaveChangesAsync();
    }

    private async Task<Dictionary<string, string?>> ReadSettingsAsync(IEnumerable<string> keys)
    {
        var requested = keys.ToArray();
        await using var db = OpenFixtureDb();
        var stored = await db.ApplicationSettings.AsNoTracking().Where(x => requested.Contains(x.Key))
            .ToDictionaryAsync(x => x.Key, x => x.Value);
        return requested.ToDictionary(x => x, x => stored.GetValueOrDefault(x));
    }

    private async Task RestoreSettingsAsync(IReadOnlyDictionary<string, string?> previous)
    {
        await using var db = OpenFixtureDb();
        foreach (var pair in previous)
        {
            var setting = await db.ApplicationSettings.SingleOrDefaultAsync(x => x.Key == pair.Key);
            if (pair.Value is null)
            {
                if (setting is not null) db.ApplicationSettings.Remove(setting);
            }
            else if (setting is null)
                db.ApplicationSettings.Add(new ApplicationSetting(pair.Key, pair.Value, DateTime.UtcNow));
            else
                setting.SetValue(pair.Key, pair.Value, DateTime.UtcNow);
        }
        await db.SaveChangesAsync();
    }

}
