using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class UxRegressionWorkflowShardingTests
{
    [Fact]
    public void Workflow_shards_browser_regressions_without_weakening_coverage()
    {
        var workflow = ReadRepositoryFile(".github/workflows/ux-regression.yml");

        workflow.Should().Contain("strategy:");
        workflow.Should().Contain("fail-fast: false");
        workflow.Should().Contain("timeout-minutes: 20");

        foreach (var shard in new[]
        {
            "smoke-public",
            "authenticated-routes",
            "visual-breakpoints",
            "rtl"
        }) workflow.Should().Contain($"shard: {shard}");

        foreach (var test in new[]
        {
            "Blazor_bootstrap_asset_is_available_before_authentication",
            "Public_routes_render_without_server_or_browser_failure",
            "Keyboard_focus_enters_the_authenticated_application",
            "Authenticated_routes_render_and_pass_accessibility_smoke",
            "High_risk_pages_hold_visual_contract_at_key_breakpoints",
            "Selected_high_risk_pages_preserve_material_contract_in_arabic_rtl"
        }) workflow.Should().Contain(test);
    }

    [Fact]
    public void Workflow_records_per_shard_timing_and_preserves_failure_identity()
    {
        var workflow = ReadRepositoryFile(".github/workflows/ux-regression.yml");

        workflow.Should().Contain("started_at=$(date +%s)");
        workflow.Should().Contain("elapsed_seconds=$((finished_at - started_at))");
        workflow.Should().Contain("Exit code: ${test_exit}");
        workflow.Should().Contain("exit \"$test_exit\"");
        workflow.Should().Contain("ux-regression-${{ matrix.shard }}.trx");
        workflow.Should().Contain("ux-regression-evidence-${{ matrix.shard }}");
    }

    [Fact]
    public void Workflow_and_fixture_fail_fast_with_persistent_hang_evidence()
    {
        var workflow = ReadRepositoryFile(".github/workflows/ux-regression.yml");
        var host = ReadRepositoryFile("tests/AIWordPressManager.UxTests/UxTestHost.cs");

        workflow.Should().Contain("--blame-hang");
        workflow.Should().Contain("--blame-hang-timeout 3m");
        workflow.Should().Contain("--blame-hang-dump-type mini");
        workflow.Should().Contain("console;verbosity=detailed");
        workflow.Should().Contain("tests/AIWordPressManager.UxTests/TestResults/**");

        host.Should().Contain("fixture-checkpoints.log");
        host.Should().Contain("initialize:complete");
        host.Should().Contain("authentication:submit:start");
        host.Should().Contain("dispose:browser:timeout");
        host.Should().Contain("dispose:application:timeout");
        host.Should().Contain("SetDefaultNavigationTimeout");
    }

    private static string ReadRepositoryFile(string relativePath)
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            var solution = Path.Combine(current.FullName, "AIWordPressManager.Web.sln");
            if (File.Exists(solution)) return File.ReadAllText(Path.Combine(current.FullName, relativePath));
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate repository root from test output.");
    }
}
