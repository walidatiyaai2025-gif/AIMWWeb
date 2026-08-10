using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class DenseWorkspaceUxContractTests
{
    [Fact]
    public void Data_grid_exposes_dense_hierarchy_filter_and_result_state_contracts()
    {
        var grid = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor");
        grid.Should().Contain("data-density=\"@Density\"");
        grid.Should().Contain("FilterPredicate");
        grid.Should().Contain("EffectiveActiveFilterCount");
        grid.Should().Contain("NoResultsTitle");
        grid.Should().Contain("ClearFiltersRequested");
        grid.Should().Contain("FilterSummary");
        grid.Should().Contain("ViewportAriaLabel");
        grid.Should().Contain("aria-rowcount=\"@FilteredItems.Count\"");
    }

    [Fact]
    public void Data_grid_exposes_selection_scope_across_page_and_filtered_results()
    {
        var grid = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor");
        grid.Should().Contain("AllowSelectAllFiltered");
        grid.Should().Contain("SelectAllFilteredAsync");
        grid.Should().Contain("AllFilteredSelected");
        grid.Should().Contain("HiddenSelectedCount");
        grid.Should().Contain("ClearSelectionCoreAsync");
        grid.Should().Contain("aria-selected");
        grid.Should().Contain("PreserveSelectionForMissingItems");
    }

    [Fact]
    public void Data_grid_keeps_paging_sort_and_export_state_explicit()
    {
        var grid = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor");
        grid.Should().Contain("SortAscendingText");
        grid.Should().Contain("SortDescendingText");
        grid.Should().Contain("SortChanged.InvokeAsync");
        grid.Should().Contain("PageSizeOptions.Where(x => x > 0).Distinct().OrderBy(x => x)");
        grid.Should().Contain("CurrentPage = 1");
        grid.Should().Contain("SortedItems.Select(CsvRowSelector)");
    }

    [Fact]
    public void Filter_bar_and_chips_are_shared_keyboard_accessible_controls()
    {
        var bar = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppFilterBar.razor");
        var chip = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppFilterChip.razor");
        bar.Should().Contain("role=\"search\"");
        bar.Should().Contain("aria-busy=\"@Busy\"");
        bar.Should().Contain("ActiveFilterCount");
        bar.Should().Contain("AppliedFilters");
        bar.Should().Contain("ClearRequested");
        chip.Should().Contain("type=\"button\"");
        chip.Should().Contain("RemoveAriaLabel");
        chip.Should().Contain("RemoveRequested");
        chip.Should().Contain("disabled=\"@Disabled\"");
    }

    [Fact]
    public void Bulk_bar_exposes_scope_busy_sticky_and_danger_contracts()
    {
        var bar = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppBulkActionBar.razor");
        bar.Should().Contain("aria-label=\"@AriaLabel\"");
        bar.Should().Contain("aria-busy=\"@Busy\"");
        bar.Should().Contain("ScopeText");
        bar.Should().Contain("BusyText");
        bar.Should().Contain("Sticky");
        bar.Should().Contain("Dangerous");
        bar.Should().Contain("SecondaryActions");
        bar.Should().Contain("ClearAriaLabel");
    }

    [Fact]
    public void Dense_workspace_css_covers_compact_mobile_rtl_reduced_motion_and_forced_colors()
    {
        var gridCss = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/app-data-grid.css");
        var bulkCss = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/app-bulk-action-bar.css");
        gridCss.Should().Contain("app-data-grid--compact");
        gridCss.Should().Contain("app-data-grid__selection-scope");
        gridCss.Should().Contain("app-filter-bar");
        gridCss.Should().Contain("app-filter-chip");
        gridCss.Should().Contain("[dir=\"rtl\"]");
        gridCss.Should().Contain("@media(max-width:700px)");
        gridCss.Should().Contain("@media(prefers-reduced-motion:reduce)");
        gridCss.Should().Contain("@media(forced-colors:active)");
        gridCss.Should().Contain("min-height:44px");
        bulkCss.Should().Contain("env(safe-area-inset-bottom)");
        bulkCss.Should().Contain("is-dangerous");
        bulkCss.Should().Contain("@media(forced-colors:active)");
    }

    [Fact]
    public void Ai_usage_adopts_shared_filter_and_dense_grid_contracts()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AIUsage.razor");
        page.Should().Contain("<AppFilterBar");
        page.Should().Contain("<AppFilterChip");
        page.Should().Contain("<AppDataGrid TItem=\"AIUsageEntry\"");
        page.Should().Contain("CsvRowSelector=\"entry => UsageCsvRow(entry)\"");
        page.Should().Contain("Density=\"compact\"");
        page.Should().Contain("Striped=\"true\"");
        page.Should().Contain("FocusableRows=\"true\"");
        page.Should().Contain("RowStateSelector");
        page.Should().Contain("<MobileRowTemplate Context=\"entry\"");
        page.Should().Contain("ClearSiteFilterAsync");
    }

    [Fact]
    public void Ai_usage_mobile_rows_have_dense_scan_fields()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AIUsage.razor.css");
        css.Should().Contain("usage-mobile-row");
        css.Should().Contain("grid-template-columns:repeat(2,minmax(0,1fr))");
        css.Should().Contain("@media(max-width:520px)");
    }

    [Fact]
    public void UX_006_manifest_records_exactly_one_hundred_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_006_100_TASKS.md");
        var completed = manifest.Split('\n').Count(line => line.StartsWith("- [x] ", StringComparison.Ordinal));
        completed.Should().Be(100);
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
