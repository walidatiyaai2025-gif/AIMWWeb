using System.Security.Claims;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;

namespace AIWordPressManager.Tests;

public sealed class ConfigurationValidationTruthfulnessContractTests
{
    [Fact]
    public void Configuration_validation_routes_require_SettingsManage()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/ConfigurationValidation.razor");

        page.Should().Contain("Authorize(Policy = ApplicationPermissionCatalog.SettingsManage)");
        ApplicationRoutePermissionCatalog.All["ConfigurationValidation"]
            .Should().Be(ApplicationPermissionCatalog.SettingsManage);
        ApplicationPermissionCatalog.RoleHasPermission("User", ApplicationPermissionCatalog.SettingsManage)
            .Should().BeFalse();
        ApplicationPermissionCatalog.RoleHasPermission("Administrator", ApplicationPermissionCatalog.SettingsManage)
            .Should().BeTrue();
    }

    [Fact]
    public void Anonymous_validation_is_denied_before_any_filesystem_probe()
    {
        using var fixture = new ValidationFixture(CreatePrincipal());

        var act = () => fixture.Service.Validate();

        act.Should().Throw<UnauthorizedAccessException>();
        fixture.Paths.AccessCount.Should().Be(0);
    }

    [Fact]
    public void Authenticated_user_without_SettingsManage_is_denied_before_any_filesystem_probe()
    {
        using var fixture = new ValidationFixture(CreatePrincipal("User"));

        var act = () => fixture.Service.Validate();

        act.Should().Throw<UnauthorizedAccessException>();
        fixture.Paths.AccessCount.Should().Be(0);
    }

    [Fact]
    public void Administrator_can_run_real_configuration_validation_after_authorization()
    {
        using var fixture = new ValidationFixture(CreatePrincipal("Administrator"));

        var report = fixture.Service.Validate();

        fixture.Paths.AccessCount.Should().BeGreaterThan(0);
        report.Items.Should().Contain(x => x.Key == "local-data" && x.Status == ValidationStatus.Valid);
        report.Items.Should().Contain(x => x.Key == "logs" && x.Status == ValidationStatus.Valid);
        report.Items.Should().Contain(x => x.Key == "backups" && x.Status == ValidationStatus.Valid);
    }

    [Fact]
    public void Service_guard_precedes_path_access_and_probe_writes()
    {
        var service = ReadRepositoryFile("src/AIWordPressManager.Web/Services/ConfigurationValidationService.cs");

        var guard = service.IndexOf("_currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);", StringComparison.Ordinal);
        var firstPathAccess = service.IndexOf("_paths.GetApplicationDataDirectory()", StringComparison.Ordinal);
        var probeWrite = service.IndexOf("File.WriteAllText(probe, \"ok\")", StringComparison.Ordinal);

        guard.Should().BeGreaterThanOrEqualTo(0);
        firstPathAccess.Should().BeGreaterThan(guard);
        probeWrite.Should().BeGreaterThan(firstPathAccess);
    }

    [Fact]
    public void User_visible_validation_output_redacts_paths_and_exception_details()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/ConfigurationValidation.razor");

        page.Should().Contain("@DisplayValue(item)");
        page.Should().Contain("@DisplayMessage(item)");
        page.Should().Contain("Managed application-data storage");
        page.Should().Contain("Managed log storage");
        page.Should().Contain("Managed backup storage");
        page.Should().Contain("Server paths and exception details were withheld.");
        page.Should().Contain("Internal server details were withheld; you can retry.");
        page.Should().NotContain("> @item.Value <");
        page.Should().NotContain(">@item.Value<");
        page.Should().NotContain("L.TranslateMessage(ex.Message)");
        page.Should().NotContain("Description=\"@ex.Message\"");
        page.Should().Contain("BuildSanitizedCopyReport()");
        page.Should().Contain("DisplayValue(item)");
        page.Should().Contain("DisplayMessage(item)");
    }

    [Fact]
    public void Readiness_copy_and_controls_are_truthful_and_retryable()
    {
        var page = ReadRepositoryFile("src/AIWordPressManager.Web/Components/Pages/ConfigurationValidation.razor");

        page.Should().NotContain("Configuration is ready");
        page.Should().NotContain(">READY<");
        page.Should().Contain("No blocking errors detected in these configuration checks");
        page.Should().Contain("No blocking errors; warnings require review");
        page.Should().Contain("This result applies only to the checks listed below.");

        var clipboardCall = page.IndexOf("await JS.InvokeVoidAsync(\"navigator.clipboard.writeText\", BuildSanitizedCopyReport());", StringComparison.Ordinal);
        var successAssignment = page.IndexOf("_copySucceeded = true;", clipboardCall, StringComparison.Ordinal);
        clipboardCall.Should().BeGreaterThanOrEqualTo(0);
        successAssignment.Should().BeGreaterThan(clipboardCall);

        page.Should().Contain("_copySucceeded = false;");
        page.Should().Contain("_copyError = null;");
        page.Should().Contain("No copy success was reported; you can retry.");
        page.Should().Contain("RetryRequested=\"CopyReportAsync\"");
        page.Should().Contain("Retry copy");
        page.Should().Contain("Retry validation");
        page.Should().Contain("disabled=\"@(_report is null || _copying)\"");
        page.Should().NotContain("href=\"#\"");
        page.Contains("javascript:", StringComparison.OrdinalIgnoreCase).Should().BeFalse();
    }

    private static ClaimsPrincipal CreatePrincipal(string? role = null)
    {
        if (role is null)
            return new ClaimsPrincipal(new ClaimsIdentity());

        var userId = Guid.NewGuid();
        return new ClaimsPrincipal(new ClaimsIdentity(
        [
            new Claim(ClaimTypes.NameIdentifier, userId.ToString()),
            new Claim(ClaimTypes.Name, $"{role.ToLowerInvariant()}-test"),
            new Claim(ClaimTypes.Role, role)
        ], "Test"));
    }

    private sealed class ValidationFixture : IDisposable
    {
        private readonly string _root;

        public ValidationFixture(ClaimsPrincipal principal)
        {
            _root = Path.Combine(Path.GetTempPath(), $"aiwm-config-validation-{Guid.NewGuid():N}");
            Directory.CreateDirectory(_root);

            var context = new DefaultHttpContext { User = principal };
            var accessor = new HttpContextAccessor { HttpContext = context };
            var currentUser = new CurrentUserContext(accessor);

            Paths = new TrackingPaths(_root);
            var configuration = new ConfigurationBuilder()
                .AddInMemoryCollection(new Dictionary<string, string?>
                {
                    ["Database:Provider"] = "SqlServer",
                    ["Database:SetupComplete"] = "true",
                    ["Database:ConnectionString"] = "Server=test;Database=test;Trusted_Connection=true",
                    ["AllowedHosts"] = "localhost",
                    ["DetailedErrors"] = "false"
                })
                .Build();

            Service = new ConfigurationValidationService(
                configuration,
                new TestWebHostEnvironment(_root),
                Paths,
                currentUser);
        }

        public TrackingPaths Paths { get; }
        public ConfigurationValidationService Service { get; }

        public void Dispose()
        {
            try
            {
                if (Directory.Exists(_root))
                    Directory.Delete(_root, recursive: true);
            }
            catch (IOException)
            {
            }
            catch (UnauthorizedAccessException)
            {
            }
        }
    }

    private sealed class TrackingPaths(string root) : IApplicationPathService
    {
        public int AccessCount { get; private set; }

        private string Resolve(string name)
        {
            AccessCount++;
            return Path.Combine(root, name);
        }

        public string GetApplicationDataDirectory() => Resolve("data");
        public string GetDatabasePath() => Resolve("database.db");
        public string GetLogsDirectory() => Resolve("logs");
        public string GetScreenshotsDirectory() => Resolve("screenshots");
        public string GetBackupsDirectory() => Resolve("backups");
        public string GetExportsDirectory() => Resolve("exports");
        public string GetTemporaryDirectory() => Resolve("temp");
    }

    private sealed class TestWebHostEnvironment(string root) : IWebHostEnvironment
    {
        public string ApplicationName { get; set; } = "AIWordPressManager.Tests";
        public IFileProvider WebRootFileProvider { get; set; } = new NullFileProvider();
        public string WebRootPath { get; set; } = root;
        public string EnvironmentName { get; set; } = "Production";
        public string ContentRootPath { get; set; } = root;
        public IFileProvider ContentRootFileProvider { get; set; } = new NullFileProvider();
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