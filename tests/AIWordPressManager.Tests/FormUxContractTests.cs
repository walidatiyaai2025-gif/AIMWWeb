using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class FormUxContractTests
{
    [Fact]
    public void Shared_form_field_exposes_label_helper_required_and_error_semantics()
    {
        var field = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppFormField.razor");
        field.Should().Contain("for=\"@InputId\"");
        field.Should().Contain("app-form-field__required");
        field.Should().Contain("class=\"sr-only\"");
        field.Should().Contain("app-form-field__helper");
        field.Should().Contain("role=\"alert\"");
        field.Should().Contain("DescriptionIds");
    }

    [Fact]
    public void Validation_summary_is_assertive_focusable_and_runtime_discoverable()
    {
        var summary = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppValidationSummary.razor");
        summary.Should().Contain("role=\"alert\"");
        summary.Should().Contain("aria-live=\"assertive\"");
        summary.Should().Contain("tabindex=\"-1\"");
        summary.Should().Contain("data-form-validation-summary");
        summary.Should().Contain("data-auto-focus");
    }

    [Fact]
    public void Form_actions_prevent_double_submit_and_expose_unsaved_state()
    {
        var actions = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppFormActions.razor");
        actions.Should().Contain("aria-busy=\"@Busy\"");
        actions.Should().Contain("Disabled=\"@(Busy || Disabled)\"");
        actions.Should().Contain("Busy=\"@Busy\"");
        actions.Should().Contain("DirtyText");
        actions.Should().Contain("app-form-actions__dirty");
    }

    [Fact]
    public void Form_status_distinguishes_error_and_non_error_announcements()
    {
        var status = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppFormStatus.razor");
        status.Should().Contain("Tone == \"error\" ? \"alert\" : \"status\"");
        status.Should().Contain("Tone == \"error\" ? \"assertive\" : \"polite\"");
        status.Should().Contain("RecoveryText");
    }

    [Fact]
    public void Confirmation_dialog_supports_typed_confirmation_impact_and_recovery()
    {
        var confirm = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Shared/AppConfirmDialog.razor");
        confirm.Should().Contain("RequiredConfirmationText");
        confirm.Should().Contain("ConfirmationMatches");
        confirm.Should().Contain("ImpactText");
        confirm.Should().Contain("RecoveryText");
        confirm.Should().Contain("data-destructive-confirmation");
        confirm.Should().Contain("Disabled=\"@(!CanConfirm)\"");
        confirm.Should().Contain("if (Busy) return;");
    }

    [Fact]
    public void Form_runtime_focuses_new_validation_summaries_and_tracks_native_invalid_state()
    {
        var runtime = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/js/form-ux.js");
        runtime.Should().Contain("focusFirstInvalid");
        runtime.Should().Contain("[aria-invalid=\"true\"], :invalid");
        runtime.Should().Contain("data-form-validation-summary");
        runtime.Should().Contain("MutationObserver");
        runtime.Should().Contain("document.addEventListener(\"invalid\"");
        runtime.Should().Contain("document.addEventListener(\"input\"");
    }

    [Fact]
    public void Forms_css_has_mobile_rtl_logical_high_contrast_and_touch_contracts()
    {
        var css = ReadRepositoryFile("src/AIWordPressManager.Web/wwwroot/css/forms-ux.css");
        css.Should().Contain("min-height:44px");
        css.Should().Contain("padding-inline-start");
        css.Should().Contain("border-inline-start");
        css.Should().Contain("@media(max-width:700px)");
        css.Should().Contain("@media(prefers-reduced-motion:reduce)");
        css.Should().Contain("@media(forced-colors:active)");
        css.Should().Contain("[aria-invalid=\"true\"]");
    }

    [Fact]
    public void Account_password_change_uses_shared_validation_and_preflight_rules()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AccountProfile.razor");
        page.Should().Contain("<AppValidationSummary");
        page.Should().Contain("<AppFormField InputId=\"current-password\"");
        page.Should().Contain("<AppFormActions");
        page.Should().Contain("ValidatePasswordForm");
        page.Should().Contain("_newPassword.Any(char.IsUpper)");
        page.Should().Contain("aria-invalid");
    }

    [Fact]
    public void Provider_settings_validate_models_and_require_typed_key_removal_confirmation()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/AIProviderSettings.razor");
        page.Should().Contain("ValidateProviders");
        page.Should().Contain("<AppValidationSummary");
        page.Should().Contain("<AppFormActions");
        page.Should().Contain("RequiredConfirmationText=\"REMOVE\"");
        page.Should().Contain("ConfirmRemovalAndSaveAsync");
        page.Should().Contain("RecoveryText=");
        page.Should().Contain("RemoveStoredKey && x.HasStoredKey");
    }

    [Fact]
    public void Application_user_admin_validates_forms_and_confirms_access_security_changes()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/ApplicationUsers.razor");
        page.Should().Contain("ValidateEditor");
        page.Should().Contain("ValidateReset");
        page.Should().Contain("RequestSetActiveAsync");
        page.Should().Contain("ConfirmDisableAsync");
        page.Should().Contain("RequiredConfirmationText=\"@SelectedUserName\"");
        page.Should().Contain("ConfirmResetPasswordAsync");
        page.Should().Contain("<AppValidationSummary");
        page.Should().Contain("<AppFormField");
    }

    [Fact]
    public void Host_loads_form_ux_after_accessibility_hardening()
    {
        var app = ReadRepositoryFile("src/AIWordPressManager.Web/Components/App.razor");
        app.Should().Contain("css/forms-ux.css");
        app.Should().Contain("js/form-ux.js");
        app.IndexOf("css/forms-ux.css", StringComparison.Ordinal)
            .Should().BeGreaterThan(app.IndexOf("css/accessibility-hardening.css", StringComparison.Ordinal));
        app.IndexOf("js/form-ux.js", StringComparison.Ordinal)
            .Should().BeGreaterThan(app.IndexOf("js/accessibility-runtime.js", StringComparison.Ordinal));
    }

    [Fact]
    public void UX_005_manifest_records_exactly_one_hundred_completed_tasks()
    {
        var manifest = ReadRepositoryFile("docs/UX_005_100_TASKS.md");
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
