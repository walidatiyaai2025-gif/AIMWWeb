using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class RtlLtrParityContractTests
{
    [Fact]
    public void Host_exposes_direction_metadata_and_loads_parity_layers_last()
    {
        var host = ReadRepositoryFile("src/AIWordPressManager.Web/Components/App.razor");
        host.Should().Contain("data-app-language=\"en\"");
        host.Should().Contain("data-app-direction=\"ltr\"");
        var feedback = host.IndexOf("css/feedback-states.css", StringComparison.Ordinal);
        var parity = host.IndexOf("css/rtl-ltr-parity.css", StringComparison.Ordinal);
        var language = host.IndexOf("js/app-language.js", StringComparison.Ordinal);
        var bidi = host.IndexOf("js/bidi-runtime.js", StringComparison.Ordinal);
        parity.Should().BeGreaterThan(feedback);
        bidi.Should().BeGreaterThan(language);
    }

    [Fact]
    public void Language_runtime_synchronizes_root_and_body_direction_metadata()
    {
        var script = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/app-language.js");
        script.Should().Contain("document.documentElement.dataset.appLanguage = culture");
        script.Should().Contain("document.documentElement.dataset.appDirection = direction");
        script.Should().Contain("document.body.dataset.appLanguage = culture");
        script.Should().Contain("document.body.dataset.appDirection = direction");
        script.Should().Contain("window.appBidi?.sync");
        script.Should().Contain("aiwp:directionchange");
    }

    [Fact]
    public void Bidi_runtime_observes_direction_and_exposes_logical_helpers()
    {
        var script = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/bidi-runtime.js");
        script.Should().Contain("new MutationObserver");
        script.Should().Contain("attributeFilter: ['dir', 'lang']");
        script.Should().Contain("root.dataset.appLanguage = language");
        script.Should().Contain("root.dataset.appDirection = direction");
        script.Should().Contain("inlineStart: () => isRtl() ? 'right' : 'left'");
        script.Should().Contain("inlineEnd: () => isRtl() ? 'left' : 'right'");
        script.Should().Contain("markTechnical");
        script.Should().Contain("markNumber");
    }

    [Fact]
    public void Bidi_text_primitive_isolates_technical_numeric_and_explicit_direction_modes()
    {
        var component = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppBidiText.razor");
        component.Should().Contain("<bdi");
        component.Should().Contain("data-bidi-mode=\"@NormalizedMode\"");
        component.Should().Contain("\"technical\" or \"code\" or \"path\" or \"url\" or \"email\"");
        component.Should().Contain("\"number\" or \"numeric\" or \"date\" or \"time\" or \"version\"");
        component.Should().Contain("\"technical\" or \"numeric\" or \"ltr\" => \"ltr\"");
        component.Should().Contain("\"rtl\" => \"rtl\"");
        component.Should().Contain("RenderFragment? ChildContent");
    }

    [Fact]
    public void Directional_icon_and_button_contracts_mirror_only_spatial_intents()
    {
        var icon = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppDirectionalIcon.razor");
        var button = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppButton.razor");
        icon.Should().Contain("app-directional-icon--mirror-rtl");
        icon.Should().Contain("NormalizedIntent is \"back\" or \"forward\" or \"enter\"");
        button.Should().Contain("[Parameter] public string IconIntent");
        button.Should().Contain("[Parameter] public bool? MirrorIconInRtl");
        button.Should().Contain("data-directional-icon");
        button.Should().Contain("app-directional-icon--mirror-rtl");
        button.Should().Contain("\"external\" => \"external\"");
    }

    [Fact]
    public void Dialog_and_badge_extensions_are_source_compatible_and_direction_aware()
    {
        var dialog = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppDialog.razor");
        var badge = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppBadge.razor");
        dialog.Should().Contain("[Parameter] public string? Direction");
        dialog.Should().Contain("dir=\"@NormalizedDirection\"");
        dialog.Should().Contain("data-bidi-scope=\"dialog\"");
        dialog.Should().Contain("\"auto\" => \"auto\"");
        badge.Should().Contain("[Parameter] public string? BidiMode");
        badge.Should().Contain("<AppBidiText Mode=\"@BidiMode\" Text=\"@Text\" />");
        badge.Should().Contain("data-bidi-mode=\"@EffectiveBidiMode\"");
    }

    [Fact]
    public void Parity_css_uses_logical_properties_for_shell_forms_dialogs_and_popovers()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/rtl-ltr-parity.css");
        css.Should().Contain("text-align:start");
        css.Should().Contain("margin-inline-start:auto");
        css.Should().Contain("inset-inline-end:0");
        css.Should().Contain("padding-inline-start:1.4rem");
        css.Should().Contain("border-inline-start:3px solid currentColor");
        css.Should().Contain(".app-dialog__close");
        css.Should().Contain("input[type=\"email\"]");
        css.Should().Contain("input[type=\"number\"]");
    }

    [Fact]
    public void Parity_css_protects_data_grid_paging_feedback_and_technical_values()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/rtl-ltr-parity.css");
        css.Should().Contain(".app-data-grid__pagination > span");
        css.Should().Contain("html[dir=\"rtl\"] .app-data-grid__pagination .app-button__icon");
        css.Should().Contain("transform:scaleX(-1)");
        css.Should().Contain("border-inline-start-width:4px");
        css.Should().Contain("font-variant-numeric:tabular-nums");
        css.Should().Contain("unicode-bidi:isolate");
        css.Should().Contain("overscroll-behavior-inline:contain");
    }

    [Fact]
    public void Parity_css_keeps_responsive_motion_and_forced_color_resilience()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/rtl-ltr-parity.css");
        css.Should().Contain("@media (max-width:1024px)");
        css.Should().Contain("@media (max-width:700px)");
        css.Should().Contain("@media (prefers-reduced-motion:reduce)");
        css.Should().Contain("@media (forced-colors:active)");
        css.Should().Contain("safe-area-inset-right");
        css.Should().Contain("safe-area-inset-left");
        css.Should().Contain("border-inline-start-color:CanvasText");
    }

    [Fact]
    public void Build_release_workspace_isolates_mixed_direction_metadata_without_changing_payload()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AboutBuild.razor");
        page.Should().Contain("data-bidi-scope=\"build-info\"");
        page.Should().Contain("<AppBidiText Mode=\"version\">v@(Build.Version)</AppBidiText>");
        page.Should().Contain("<AppBidiText Mode=\"technical\">@Build.Branch</AppBidiText>");
        page.Should().Contain("<AppBidiText Mode=\"technical\">@Build.Commit</AppBidiText>");
        page.Should().Contain("<AppBidiText Mode=\"date\">@Build.BuildTimeUtc.ToLocalTime().ToString(\"yyyy-MM-dd HH:mm:ss\")</AppBidiText>");
        page.Should().Contain("<AppBidiText Mode=\"path\">/api/build</AppBidiText>");
        page.Should().Contain("$\"Branch: {Build.Branch}\"");
        page.Should().Contain("$\"Commit: {Build.Commit}\"");
    }

    [Fact]
    public void UX_008_manifest_records_exactly_one_hundred_completed_code_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_008_100_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] ", StringComparison.Ordinal));
        completed.Should().Be(100);
        manifest.Should().Contain("one component/layout tree");
        manifest.Should().Contain("changes no database schema");
        manifest.Should().Contain("Browser-driven screenshot/visual-diff automation remains UX-010 scope");
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
