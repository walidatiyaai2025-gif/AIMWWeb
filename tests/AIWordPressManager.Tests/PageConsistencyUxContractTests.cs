using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class PageConsistencyUxContractTests
{
    [Fact]
    public void App_page_defines_width_density_identity_and_optional_bidi_scope_without_nesting_main()
    {
        var component = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppPage.razor");
        component.Should().Contain("class=\"app-page app-page--@NormalizedWidth app-page--@NormalizedDensity @Class\"");
        component.Should().Contain("data-page-key=\"@PageKey\"");
        component.Should().Contain("data-page-width=\"@NormalizedWidth\"");
        component.Should().Contain("data-page-density=\"@NormalizedDensity\"");
        component.Should().Contain("data-bidi-scope=\"@BidiScope\"");
        component.Should().Contain("\"narrow\" => \"narrow\"");
        component.Should().Contain("\"standard\" => \"standard\"");
        component.Should().Contain("\"fluid\" => \"fluid\"");
        component.Should().NotContain("<main");
    }

    [Fact]
    public void Toolbar_uses_shell_safe_default_hierarchy_and_exposes_meta_tone_density_and_relationships()
    {
        var component = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppToolbar.razor");
        component.Should().Contain("[Parameter] public int HeadingLevel { get; set; } = 2;");
        component.Should().Contain("<h1 id=\"@TitleId\">@Title</h1>");
        component.Should().Contain("<h2 id=\"@TitleId\">@Title</h2>");
        component.Should().Contain("<h3 id=\"@TitleId\">@Title</h3>");
        component.Should().Contain("<h4 id=\"@TitleId\">@Title</h4>");
        component.Should().Contain("aria-labelledby=\"@LabelledById\"");
        component.Should().Contain("aria-describedby=\"@DescribedById\"");
        component.Should().Contain("RenderFragment? Meta");
        component.Should().Contain("data-toolbar-tone=\"@NormalizedTone\"");
        component.Should().Contain("data-toolbar-density=\"@NormalizedDensity\"");
    }

    [Fact]
    public void Card_and_section_default_to_h3_and_keep_source_compatible_content_regions()
    {
        var card = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppCard.razor");
        var section = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppSection.razor");
        card.Should().Contain("[Parameter] public int HeadingLevel { get; set; } = 3;");
        section.Should().Contain("[Parameter] public int HeadingLevel { get; set; } = 3;");
        card.Should().Contain("RenderFragment? Header");
        card.Should().Contain("RenderFragment? Footer");
        card.Should().Contain("RenderFragment? ChildContent");
        section.Should().Contain("RenderFragment? Header");
        section.Should().Contain("RenderFragment? Actions");
        section.Should().Contain("Header ?? Actions");
        card.Should().Contain("data-card-density=\"@NormalizedDensity\"");
        section.Should().Contain("data-section-density=\"@NormalizedDensity\"");
    }

    [Fact]
    public void Stat_card_supports_accessible_naming_bidi_values_density_and_meta()
    {
        var component = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppStatCard.razor");
        component.Should().Contain("aria-label=\"@EffectiveAriaLabel\"");
        component.Should().Contain("[Parameter] public string? ValueBidiMode");
        component.Should().Contain("<AppBidiText Mode=\"@ValueBidiMode\" Text=\"@Value\" />");
        component.Should().Contain("[Parameter] public string Density");
        component.Should().Contain("RenderFragment? Meta");
        component.Should().Contain("data-stat-tone=\"@NormalizedTone\"");
        component.Should().Contain("data-stat-density=\"@NormalizedDensity\"");
    }

    [Fact]
    public void Page_consistency_css_defines_shared_rhythm_responsive_and_accessibility_resilience()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/page-consistency.css");
        css.Should().Contain("--page-max-narrow:760px");
        css.Should().Contain("--page-max-standard:1120px");
        css.Should().Contain("--page-max-wide:1480px");
        css.Should().Contain(".app-page-grid--2");
        css.Should().Contain(".app-page-grid--3");
        css.Should().Contain(".app-action-cluster");
        css.Should().Contain(".app-meta-row");
        css.Should().Contain("text-wrap:balance");
        css.Should().Contain("min-width:0");
        css.Should().Contain("@media (max-width:1024px)");
        css.Should().Contain("@media (max-width:700px)");
        css.Should().Contain("@media (prefers-reduced-motion:reduce)");
        css.Should().Contain("@media (forced-colors:active)");
    }

    [Fact]
    public void Host_loads_consistency_after_feedback_and_before_rtl_final_layer()
    {
        var host = ReadRepositoryFile("src/AIWordPressManager.Web/Components/App.razor");
        var feedback = host.IndexOf("css/feedback-states.css", StringComparison.Ordinal);
        var consistency = host.IndexOf("css/page-consistency.css", StringComparison.Ordinal);
        var bidi = host.IndexOf("css/rtl-ltr-parity.css", StringComparison.Ordinal);
        consistency.Should().BeGreaterThan(feedback);
        bidi.Should().BeGreaterThan(consistency);
    }

    [Fact]
    public void Dashboard_uses_shared_page_cards_progress_and_preserves_live_refresh_contract()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/Home.razor");
        page.Should().Contain("<AppPage PageKey=\"dashboard\"");
        page.Should().Contain("<AppToolbar");
        page.Should().Contain("<AppProgressBar Value=\"@_data.HealthScore\"");
        page.Should().Contain("Class=\"dashboard-card\"");
        page.Should().NotContain("<article class=\"panel dashboard-card\"");
        page.Should().Contain("new Timer");
        page.Should().Contain("Dashboard.GetAsync()");
        page.Should().Contain("_refreshLock.WaitAsync(0)");
    }

    [Fact]
    public void Build_release_page_uses_shared_page_toolbar_and_preserves_ux008_bidi_contract()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AboutBuild.razor");
        page.Should().Contain("<AppPage PageKey=\"build-release\" BidiScope=\"build-info\"");
        page.Should().Contain("<AppToolbar Class=\"build-info-hero\"");
        page.Should().Contain("<Meta>");
        page.Should().Contain("<AppBidiText Mode=\"version\">v@(Build.Version)</AppBidiText>");
        page.Should().Contain("<AppBidiText Mode=\"technical\">@Build.Branch</AppBidiText>");
        page.Should().Contain("<AppBidiText Mode=\"technical\">@Build.Commit</AppBidiText>");
        page.Should().Contain("$\"Branch: {Build.Branch}\"");
        page.Should().Contain("$\"Commit: {Build.Commit}\"");
    }

    [Fact]
    public void Account_pages_use_shared_page_hierarchy_without_changing_security_or_mail_service_calls()
    {
        var profile = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AccountProfile.razor");
        var email = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AccountEmailSettings.razor");
        profile.Should().Contain("<AppPage PageKey=\"account-profile\"");
        profile.Should().Contain("AccountService.ChangePasswordAsync");
        email.Should().Contain("<AppPage PageKey=\"account-email-settings\"");
        email.Should().Contain("<AppSection Eyebrow=\"SMTP\"");
        email.Should().Contain("<AppStateBanner Kind=\"error\"");
        email.Should().Contain("Service.SaveProfileAsync");
        email.Should().Contain("Service.AddRecipientAsync");
        email.Should().Contain("Service.DeleteRecipientAsync");
    }

    [Fact]
    public void System_health_and_ai_prompt_workspaces_adopt_shared_page_contracts_without_domain_rewrites()
    {
        var health = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/SystemHealth.razor");
        var prompts = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AIPromptTemplates.razor");
        health.Should().Contain("<AppPage PageKey=\"system-health\"");
        health.Should().Contain("HealthService.CheckAsync()");
        health.Should().Contain("<AppDataGrid TItem=\"SystemHealthCheck\"");
        prompts.Should().Contain("<AppPage PageKey=\"ai-prompt-templates\"");
        prompts.Should().Contain("<AppSearchBox Value=\"@_search\"");
        prompts.Should().Contain("PromptStore.Save");
        prompts.Should().Contain("PromptStore.Restore");
    }

    [Fact]
    public void Ai_center_uses_shared_page_summary_and_states_while_preserving_ai_and_approval_calls()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AICenter.razor");
        page.Should().Contain("<AppPage PageKey=\"ai-center\"");
        page.Should().Contain("<AppToolbar Class=\"ai-center-hero\"");
        page.Should().Contain("<section class=\"app-stat-grid\"");
        page.Should().Contain("<AppStatePanel Kind=\"error\"");
        page.Should().Contain("<AppEmptyState Icon=\"✦\"");
        page.Should().NotContain("<h1>");
        page.Should().Contain("Orchestrator.ExecuteAsync");
        page.Should().Contain("ApprovalService.Submit");
        page.Should().Contain("AISuggestionContract.TryParse");
    }

    [Fact]
    public void UX_009_manifest_records_exactly_one_hundred_completed_code_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_009_100_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] ", StringComparison.Ordinal));
        completed.Should().Be(100);
        manifest.Should().Contain("presentation/component composition only");
        manifest.Should().Contain("Browser-driven screenshot/visual-diff and automated axe gates remain UX-010 scope");
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var directory = new DirectoryInfo(Directory.GetCurrentDirectory());
        while (directory is not null)
        {
            var candidate = Path.Combine(directory.FullName, relativePath.Replace('/', Path.DirectorySeparatorChar));
            if (File.Exists(candidate)) return File.ReadAllText(candidate);
            directory = directory.Parent;
        }

        throw new FileNotFoundException($"Could not locate repository file '{relativePath}' from '{Directory.GetCurrentDirectory()}'.");
    }
}
