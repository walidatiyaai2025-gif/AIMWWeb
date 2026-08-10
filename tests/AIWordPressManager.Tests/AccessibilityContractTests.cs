using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class AccessibilityContractTests
{
    [Fact]
    public void Accessibility_runtime_traps_modal_focus_and_restores_the_opener()
    {
        var runtime = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/accessibility-runtime.js");

        runtime.Should().Contain("focusableElements");
        runtime.Should().Contain("event.key !== \"Tab\"");
        runtime.Should().Contain("event.shiftKey");
        runtime.Should().Contain("managedDialogs.set");
        runtime.Should().Contain("state.opener.focus");
        runtime.Should().Contain("has-a11y-modal-dialog");
        runtime.Should().Contain("focusin");
    }

    [Fact]
    public void Accessibility_runtime_focuses_and_announces_new_page_context()
    {
        var runtime = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/accessibility-runtime.js");

        runtime.Should().Contain("page-title");
        runtime.Should().Contain("main-content");
        runtime.Should().Contain("focusMain");
        runtime.Should().Contain("announce(announcement)");
        runtime.Should().Contain("MutationObserver");
    }

    [Fact]
    public void Application_shell_uses_semantic_page_landmarks_and_accessible_command_dialog()
    {
        var layout = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Layout/MainLayout.razor");

        layout.Should().Contain("<h1 id=\"page-title\"");
        layout.Should().Contain("<main class=\"content\" id=\"main-content\"");
        layout.Should().Contain("aria-labelledby=\"page-title\"");
        layout.Should().Contain("<nav class=\"app-breadcrumb\"");
        layout.Should().Contain("aria-current=\"page\"");
        layout.Should().Contain("aria-keyshortcuts=\"Control+K\"");
        layout.Should().Contain("aria-keyshortcuts=\"Control+Shift+P\"");
        layout.Should().Contain("id=\"command-palette-dialog\"");
        layout.Should().Contain("data-a11y-close");
        layout.Should().Contain("data-a11y-autofocus");
    }

    [Fact]
    public void Shared_dialog_has_programmatic_name_description_and_unique_ids()
    {
        var dialog = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppDialog.razor");

        dialog.Should().Contain("Guid.NewGuid().ToString(\"N\")");
        dialog.Should().Contain("aria-labelledby=\"@LabelledById\"");
        dialog.Should().Contain("aria-describedby=\"@DescribedById\"");
        dialog.Should().Contain("tabindex=\"-1\"");
        dialog.Should().Contain("data-a11y-close");
        dialog.Should().Contain("CloseAriaLabel");
    }

    [Fact]
    public void Shared_button_and_search_components_expose_accessible_state()
    {
        var button = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppButton.razor");
        var search = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppSearchBox.razor");

        button.Should().Contain("EffectiveAriaLabel");
        button.Should().Contain("aria-busy=\"@Busy\"");
        button.Should().Contain("aria-pressed=\"@AriaPressed\"");
        button.Should().Contain("EffectiveHref => Disabled ? null : Href");
        button.Should().Contain("DisabledTabIndex => Disabled ? \"-1\" : null");

        search.Should().Contain("aria-busy=\"@Busy\"");
        search.Should().Contain("role=\"status\"");
        search.Should().Contain("ClearAriaLabel");
        search.Should().Contain("autocomplete=\"@AutoComplete\"");
    }

    [Fact]
    public void Shared_data_grid_exposes_selection_pagination_and_live_status_semantics()
    {
        var grid = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor");

        grid.Should().Contain("aria-busy=\"@Loading\"");
        grid.Should().Contain("aria-label=\"@TableLabel\"");
        grid.Should().Contain("aria-rowcount=\"@FilteredItems.Count\"");
        grid.Should().Contain("SelectVisibleRowsText");
        grid.Should().Contain("RowAriaLabelSelector");
        grid.Should().Contain("RowsPerPageAriaLabel");
        grid.Should().Contain("<nav class=\"app-data-grid__pagination\"");
        grid.Should().Contain("PreviousPageText");
        grid.Should().Contain("NextPageText");
        grid.Should().Contain("aria-live=\"polite\"");
    }

    [Fact]
    public void Accessibility_css_covers_focus_motion_contrast_forced_colors_and_touch_targets()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/accessibility-hardening.css");

        css.Should().Contain(":focus-visible");
        css.Should().Contain(".topbar h1");
        css.Should().Contain("prefers-reduced-motion: reduce");
        css.Should().Contain("data-reduced-motion=\"true\"");
        css.Should().Contain("prefers-contrast: more");
        css.Should().Contain("forced-colors: active");
        css.Should().Contain("min-width: 44px");
        css.Should().Contain("min-height: 44px");
        css.Should().Contain("tr.is-selected");
    }

    [Fact]
    public void Accessibility_settings_panel_exposes_dialog_and_toggle_semantics()
    {
        var center = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/accessibility-center.js");

        center.Should().Contain("aria-haspopup");
        center.Should().Contain("aria-controls");
        center.Should().Contain("aria-keyshortcuts");
        center.Should().Contain("aria-labelledby");
        center.Should().Contain("aria-pressed");
        center.Should().Contain("dataset.focusKey");
        center.Should().Contain("close(true)");
    }

    [Fact]
    public void Host_loads_accessibility_hardening_after_shared_component_styles()
    {
        var app = ReadRepositoryFile("src/AIWordPressManager.Web/Components/App.razor");

        app.Should().Contain("css/accessibility-hardening.css");
        app.Should().Contain("js/accessibility-runtime.js");
        app.IndexOf("css/accessibility-hardening.css", StringComparison.Ordinal)
            .Should().BeGreaterThan(app.IndexOf("css/app-data-grid.css", StringComparison.Ordinal));
    }

    [Fact]
    public void UX_004_manifest_records_exactly_fifty_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_004_50_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] ", StringComparison.Ordinal));
        completed.Should().Be(50);
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var directory = new DirectoryInfo(Directory.GetCurrentDirectory());
        while (directory is not null)
        {
            var candidate = Path.Combine(directory.FullName, relativePath.Replace('/', Path.DirectorySeparatorChar));
            if (File.Exists(candidate))
                return File.ReadAllText(candidate);

            directory = directory.Parent;
        }

        throw new FileNotFoundException($"Could not locate repository file '{relativePath}' from '{Directory.GetCurrentDirectory()}'.");
    }
}
