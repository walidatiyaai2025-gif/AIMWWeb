using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class FeedbackStateUxContractTests
{
    [Fact]
    public void State_panel_exposes_taxonomy_semantics_recovery_and_retry_contracts()
    {
        var panel = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppStatePanel.razor");
        panel.Should().Contain("data-state=\"@NormalizedKind\"");
        panel.Should().Contain("data-blocking=\"@Blocking.ToString().ToLowerInvariant()\"");
        panel.Should().Contain("\"offline\" => \"offline\"");
        panel.Should().Contain("\"cached\" or \"stale\" => \"cached\"");
        panel.Should().Contain("\"partial\" => \"partial\"");
        panel.Should().Contain("EffectiveRole => Assertive || NormalizedKind == \"error\" ? \"alert\" : \"status\"");
        panel.Should().Contain("RecoveryText");
        panel.Should().Contain("RetryRequested");
        panel.Should().Contain("Busy ? Task.CompletedTask");
        panel.Should().Contain("<details class=\"app-state-panel__details\"");
    }

    [Fact]
    public void State_panel_exposes_freshness_and_accessible_busy_metadata()
    {
        var panel = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppStatePanel.razor");
        panel.Should().Contain("aria-live=\"@EffectiveLive\"");
        panel.Should().Contain("aria-atomic=\"true\"");
        panel.Should().Contain("aria-busy=\"@Busy.ToString().ToLowerInvariant()\"");
        panel.Should().Contain("UpdatedAtUtc");
        panel.Should().Contain("FreshnessText");
        panel.Should().Contain("UpdatedTimestamp");
    }

    [Fact]
    public void Retained_content_banner_supports_cached_partial_offline_and_retry_states()
    {
        var banner = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppStateBanner.razor");
        banner.Should().Contain("data-retains-content=\"true\"");
        banner.Should().Contain("\"offline\" => \"offline\"");
        banner.Should().Contain("\"cached\" or \"stale\" => \"cached\"");
        banner.Should().Contain("\"partial\" => \"partial\"");
        banner.Should().Contain("aria-busy=\"@Busy.ToString().ToLowerInvariant()\"");
        banner.Should().Contain("RetryRequested");
        banner.Should().Contain("FreshnessText");
    }

    [Fact]
    public void Skeleton_and_legacy_loading_wrapper_keep_accessible_loading_contracts()
    {
        var skeleton = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppSkeleton.razor");
        var loading = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppLoading.razor");
        skeleton.Should().Contain("aria-hidden=\"true\"");
        skeleton.Should().Contain("Math.Clamp(Count, 1, 12)");
        loading.Should().Contain("<AppStatePanel Kind=\"loading\"");
        loading.Should().Contain("ShowSkeleton");
        loading.Should().Contain("SkeletonLines");
        loading.Should().Contain("data-feedback-wrapper=\"loading\"");
    }

    [Fact]
    public void Legacy_empty_wrapper_uses_shared_empty_state_without_breaking_actions()
    {
        var empty = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppEmptyState.razor");
        empty.Should().Contain("<AppStatePanel Kind=\"empty\"");
        empty.Should().Contain("RecoveryText");
        empty.Should().Contain("RecoveryLabel");
        empty.Should().Contain("Blocking");
        empty.Should().Contain("@if (Actions is null)");
        empty.Should().Contain("<Actions>@Actions</Actions>");
    }

    [Fact]
    public void Ai_usage_adopts_blocking_retained_cached_and_partial_feedback_states()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AIUsage.razor");
        page.Should().Contain("_loading && _snapshot is null");
        page.Should().Contain("<AppLoading");
        page.Should().Contain("ShowSkeleton=\"true\"");
        page.Should().Contain("<AppStatePanel Kind=\"error\"");
        page.Should().Contain("RetryRequested=\"LoadAsync\"");
        page.Should().Contain("<AppStateBanner Kind=\"cached\"");
        page.Should().Contain("<AppStateBanner Kind=\"partial\"");
        page.Should().Contain("FailedCallCount");
        page.Should().Contain("_loadedAtUtc = DateTime.UtcNow");
    }

    [Fact]
    public void Ai_usage_keeps_successful_snapshot_visible_during_refresh_failures()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AIUsage.razor");
        page.Should().Contain("@if (_loading)");
        page.Should().Contain("The last successful snapshot remains visible while refresh runs.");
        page.Should().Contain("Refresh failed — showing last successful data");
        page.Should().Contain("Last successful refresh:");
        page.Should().Contain("review the marked rows for failure details");
        page.Should().NotContain("<section class=\"alert error\" role=\"alert\"");
    }

    [Fact]
    public void Feedback_css_covers_non_color_mobile_rtl_motion_and_forced_color_resilience()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/feedback-states.css");
        css.Should().Contain("border-inline-start:4px solid var(--state-accent)");
        css.Should().Contain("app-state-panel--offline");
        css.Should().Contain("app-state-panel--cached");
        css.Should().Contain("app-state-panel--partial");
        css.Should().Contain("@media(max-width:700px)");
        css.Should().Contain("@media(prefers-reduced-motion:reduce)");
        css.Should().Contain("@media(forced-colors:active)");
        css.Should().Contain("app-skeleton__line");
        css.Should().Contain("overflow-wrap:anywhere");
    }

    [Fact]
    public void Host_loads_feedback_state_css_after_form_and_accessibility_layers()
    {
        var host = ReadRepositoryFile("src/AIWordPressManager.Web/Components/App.razor");
        var accessibility = host.IndexOf("css/accessibility-hardening.css", StringComparison.Ordinal);
        var forms = host.IndexOf("css/forms-ux.css", StringComparison.Ordinal);
        var feedback = host.IndexOf("css/feedback-states.css", StringComparison.Ordinal);
        accessibility.Should().BeGreaterThanOrEqualTo(0);
        forms.Should().BeGreaterThan(accessibility);
        feedback.Should().BeGreaterThan(forms);
    }

    [Fact]
    public void UX_007_manifest_records_exactly_one_hundred_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_007_100_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] ", StringComparison.Ordinal));
        completed.Should().Be(100);
        manifest.Should().Contain("no database schema");
        manifest.Should().Contain("avoid falsely labeling generic service failures as offline");
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
