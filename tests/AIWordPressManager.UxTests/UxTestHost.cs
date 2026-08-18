using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Text;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

public sealed class UxTestHost : IAsyncLifetime
{
    private const float PlaywrightTimeoutMs = 10000;
    private readonly List<string> _appLog = [];
    private readonly List<string> _httpProbeLog = [];
    private readonly object _checkpointLock = new();
    private IPlaywright? _playwright;
    private IBrowser? _browser;
    private Process? _appProcess;
    private string _repositoryRoot = string.Empty;
    private string _runRoot = string.Empty;
    private string _storageStatePath = string.Empty;
    private string _databasePath = string.Empty;

    public string BaseUrl { get; private set; } = string.Empty;
    public string ArtifactRoot => Path.Combine(_repositoryRoot, "artifacts", "ux-regression");
    public IBrowser Browser => _browser ?? throw new InvalidOperationException("Browser is not initialized.");

    public async Task InitializeAsync()
    {
        _repositoryRoot = FindRepositoryRoot();
        _runRoot = Path.Combine(Path.GetTempPath(), "aiwm-ux-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_runRoot);
        Directory.CreateDirectory(ArtifactRoot);
        _storageStatePath = Path.Combine(_runRoot, "auth-state.json");
        Checkpoint("initialize:start");

        var port = ReserveTcpPort();
        BaseUrl = $"http://127.0.0.1:{port}";
        Checkpoint($"application:start:{BaseUrl}");
        StartApplication(port);
        Checkpoint("health:wait");
        await WaitForHealthAsync();
        Checkpoint("health:ok");

        Checkpoint("probe:welcome:start");
        await ProbeResponseAsync("/welcome", null, expectBodyBytes: true, expectedMediaTypeFragment: "html");
        Checkpoint("probe:welcome:ok");
        Checkpoint("probe:blazor:start");
        await ProbeResponseAsync("/_framework/blazor.web.js", null, expectBodyBytes: true, expectedMediaTypeFragment: "javascript");
        Checkpoint("probe:blazor:ok");

        Checkpoint("playwright:create:start");
        _playwright = await Playwright.CreateAsync();
        Checkpoint("playwright:create:ok");
        Checkpoint("browser:launch:start");
        _browser = await _playwright.Chromium.LaunchAsync(new BrowserTypeLaunchOptions
        {
            Headless = true,
            Timeout = PlaywrightTimeoutMs
        });
        Checkpoint("browser:launch:ok");

        Checkpoint("authentication:start");
        var authCookie = await CreateAuthenticatedStorageStateAsync();
        Checkpoint("authentication:ok");
        Checkpoint("probe:authenticated-root:start");
        await ProbeResponseAsync("/", authCookie, expectBodyBytes: true, expectedMediaTypeFragment: "html");
        Checkpoint("probe:authenticated-root:ok");
        Checkpoint("initialize:complete");
    }

    public async Task<IBrowserContext> CreateContextAsync(UxViewport viewport, bool authenticated = true)
    {
        var context = await Browser.NewContextAsync(new BrowserNewContextOptions
        {
            ViewportSize = new ViewportSize { Width = viewport.Width, Height = viewport.Height },
            StorageStatePath = authenticated ? _storageStatePath : null
        });
        ConfigureContext(context);
        await AddDefaultClientStateAsync(context);
        return context;
    }

    public async Task<(IBrowserContext Context, Guid SiteId)> CreateContentViewerContextAsync(UxViewport viewport)
    {
        const string roleName = "ContentViewerUx";
        const string userName = "content.viewer.ux";
        const string password = "Viewer@123";
        var now = DateTime.UtcNow;
        Guid siteId;

        await using (var dbContext = CreateDbContext())
        {
            var roleStore = new ApplicationRoleStore(dbContext);
            var roles = (await roleStore.GetAsync())
                .Where(x => !string.Equals(x.Name, roleName, StringComparison.OrdinalIgnoreCase))
                .ToList();
            roles.Add(new CustomApplicationRole(
                roleName,
                "Content Viewer UX",
                "عارض المحتوى UX",
                true,
                [ApplicationPermissionCatalog.SitesView, ApplicationPermissionCatalog.ContentView]));
            await roleStore.SaveAsync(roles);

            var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.NormalizedUserName == userName.ToUpperInvariant());
            if (user is null)
            {
                user = new AuthUser(userName, "temporary", now, roleName);
                user.SetPasswordHash(new PasswordHasher<AuthUser>().HashPassword(user, password), now);
                dbContext.AuthUsers.Add(user);
                await dbContext.SaveChangesAsync();
            }

            var site = new Site("View-only UX site", new Uri("https://view-only.example.test"), now, user.Id);
            dbContext.Sites.Add(site);

            var content = new WordPressContentRecord(site.Id, 501, "post", now);
            content.Update(
                "View-only test post",
                "view-only-test-post",
                "draft",
                "https://view-only.example.test/view-only-test-post",
                "<p>Read-only content</p>",
                "Read-only excerpt",
                now,
                now);
            dbContext.WordPressContentRecords.Add(content);
            await dbContext.SaveChangesAsync();
            siteId = site.Id;
        }

        var context = await Browser.NewContextAsync(new BrowserNewContextOptions
        {
            ViewportSize = new ViewportSize { Width = viewport.Width, Height = viewport.Height }
        });
        ConfigureContext(context);
        await AddDefaultClientStateAsync(context);

        try
        {
            var page = await context.NewPageAsync();
            var returnUrl = Uri.EscapeDataString($"/sites/{siteId}/explorer");
            await page.GotoAsync(BaseUrl + $"/login?returnUrl={returnUrl}", new PageGotoOptions
            {
                WaitUntil = WaitUntilState.DOMContentLoaded,
                Timeout = PlaywrightTimeoutMs
            });
            await page.Locator("input[name='userName']").FillAsync(userName);
            await page.Locator("input[name='password']").FillAsync(password);
            await page.Locator("button[type='submit']").ClickAsync(new LocatorClickOptions { Timeout = PlaywrightTimeoutMs });

            var deadline = DateTime.UtcNow.AddSeconds(15);
            while (DateTime.UtcNow < deadline && page.Url.Contains("/login", StringComparison.OrdinalIgnoreCase))
                await Task.Delay(100);

            if (page.Url.Contains("/login", StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("UX regression fixture could not authenticate the Content.View-only user.");

            var cookies = await context.CookiesAsync();
            if (!cookies.Any(cookie => string.Equals(cookie.Name, "AIWM.Auth", StringComparison.Ordinal)))
                throw new InvalidOperationException("Content.View-only UX login completed without receiving the AIWM.Auth cookie.");

            return (context, siteId);
        }
        catch
        {
            await context.CloseAsync();
            throw;
        }
    }

    public string RepositoryPath(params string[] segments)
    {
        var all = new string[segments.Length + 1];
        all[0] = _repositoryRoot;
        Array.Copy(segments, 0, all, 1, segments.Length);
        return Path.Combine(all);
    }

    public string ArtifactPath(string category, string fileName)
    {
        var directory = Path.Combine(ArtifactRoot, category);
        Directory.CreateDirectory(directory);
        return Path.Combine(directory, fileName);
    }

    public async Task DisposeAsync()
    {
        Checkpoint("dispose:start");

        if (_browser is not null)
        {
            Checkpoint("dispose:browser:start");
            try
            {
                var disposeTask = _browser.DisposeAsync().AsTask();
                var completed = await Task.WhenAny(disposeTask, Task.Delay(TimeSpan.FromSeconds(10)));
                if (completed == disposeTask)
                {
                    await disposeTask;
                    Checkpoint("dispose:browser:ok");
                }
                else
                {
                    Checkpoint("dispose:browser:timeout");
                }
            }
            catch (Exception ex)
            {
                Checkpoint($"dispose:browser:error:{ex.GetType().Name}");
            }
        }

        try
        {
            _playwright?.Dispose();
            Checkpoint("dispose:playwright:ok");
        }
        catch (Exception ex)
        {
            Checkpoint($"dispose:playwright:error:{ex.GetType().Name}");
        }

        if (_appProcess is { HasExited: false })
        {
            Checkpoint("dispose:application:kill");
            try { _appProcess.Kill(entireProcessTree: true); }
            catch (Exception ex) { Checkpoint($"dispose:application:kill-error:{ex.GetType().Name}"); }

            try
            {
                using var shutdownCts = new CancellationTokenSource(TimeSpan.FromSeconds(10));
                await _appProcess.WaitForExitAsync(shutdownCts.Token);
                Checkpoint("dispose:application:exited");
            }
            catch (OperationCanceledException)
            {
                Checkpoint("dispose:application:timeout");
            }
        }

        if (!string.IsNullOrWhiteSpace(_repositoryRoot))
        {
            await File.WriteAllLinesAsync(ArtifactPath("logs", "web-host.log"), _appLog);
            await File.WriteAllLinesAsync(ArtifactPath("logs", "http-probe.log"), _httpProbeLog);
        }

        try { if (Directory.Exists(_runRoot)) Directory.Delete(_runRoot, recursive: true); }
        catch { }
        Checkpoint("dispose:complete");
    }

    private void StartApplication(int port)
    {
        _databasePath = Path.Combine(_runRoot, "ux-regression.db");
        var startInfo = new ProcessStartInfo("dotnet")
        {
            WorkingDirectory = _repositoryRoot,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true
        };
        startInfo.ArgumentList.Add("run");
        startInfo.ArgumentList.Add("--project");
        startInfo.ArgumentList.Add("src/AIWordPressManager.Web/AIWordPressManager.Web.csproj");
        startInfo.ArgumentList.Add("--configuration");
        startInfo.ArgumentList.Add("Release");
        startInfo.ArgumentList.Add("--no-build");
        startInfo.ArgumentList.Add("--no-launch-profile");
        startInfo.ArgumentList.Add("--urls");
        startInfo.ArgumentList.Add($"http://127.0.0.1:{port}");

        startInfo.Environment["ASPNETCORE_ENVIRONMENT"] = "Development";
        startInfo.Environment["Database__SetupComplete"] = "true";
        startInfo.Environment["Database__Provider"] = "SQLite";
        startInfo.Environment["Database__ConnectionString"] = $"Data Source={_databasePath};Foreign Keys=True;Pooling=False";
        startInfo.Environment["Application__PortableMode"] = "true";
        startInfo.Environment["HOME"] = _runRoot;
        startInfo.Environment["XDG_DATA_HOME"] = Path.Combine(_runRoot, ".local", "share");
        startInfo.Environment["DOTNET_NOLOGO"] = "1";
        startInfo.Environment["Logging__LogLevel__Microsoft.AspNetCore.Hosting.Diagnostics"] = "Information";
        startInfo.Environment["Logging__LogLevel__Microsoft.AspNetCore.Routing.EndpointMiddleware"] = "Information";

        _appProcess = new Process { StartInfo = startInfo, EnableRaisingEvents = true };
        _appProcess.OutputDataReceived += (_, e) => { if (e.Data is not null) lock (_appLog) _appLog.Add("OUT " + e.Data); };
        _appProcess.ErrorDataReceived += (_, e) => { if (e.Data is not null) lock (_appLog) _appLog.Add("ERR " + e.Data); };
        if (!_appProcess.Start()) throw new InvalidOperationException("Failed to start the web application.");
        _appProcess.BeginOutputReadLine();
        _appProcess.BeginErrorReadLine();
    }

    private AppDbContext CreateDbContext()
    {
        if (string.IsNullOrWhiteSpace(_databasePath))
            throw new InvalidOperationException("UX database path is not initialized.");

        var options = new DbContextOptionsBuilder<AppDbContext>()
            .UseSqlite($"Data Source={_databasePath};Foreign Keys=True;Pooling=False")
            .Options;
        return new AppDbContext(options);
    }

    private static void ConfigureContext(IBrowserContext context)
    {
        context.SetDefaultTimeout(PlaywrightTimeoutMs);
        context.SetDefaultNavigationTimeout(PlaywrightTimeoutMs);
    }

    private static Task AddDefaultClientStateAsync(IBrowserContext context) =>
        context.AddInitScriptAsync("""
            try {
              if (!localStorage.getItem('aiwp-language')) localStorage.setItem('aiwp-language', 'en');
              if (!localStorage.getItem('aiwp-appearance')) localStorage.setItem('aiwp-appearance', 'light');
            } catch (_) { }
            """);

    private async Task WaitForHealthAsync()
    {
        using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(3) };
        var deadline = DateTime.UtcNow.AddSeconds(60);
        Exception? lastError = null;
        while (DateTime.UtcNow < deadline)
        {
            if (_appProcess?.HasExited == true)
                throw new InvalidOperationException($"Web application exited before becoming healthy. Exit code: {_appProcess.ExitCode}");

            try
            {
                using var response = await client.GetAsync(BaseUrl + "/health/live");
                if (response.StatusCode == HttpStatusCode.OK) return;
            }
            catch (Exception ex) { lastError = ex; }
            await Task.Delay(500);
        }
        throw new TimeoutException("Web application did not become healthy in time.", lastError);
    }

    private async Task<string> CreateAuthenticatedStorageStateAsync()
    {
        Checkpoint("authentication:context:create");
        await using var context = await Browser.NewContextAsync(new BrowserNewContextOptions
        {
            ViewportSize = new ViewportSize { Width = 1440, Height = 900 }
        });
        ConfigureContext(context);
        var page = await context.NewPageAsync();

        Checkpoint("authentication:login-navigation:start");
        await page.GotoAsync(BaseUrl + "/login?returnUrl=%2F", new PageGotoOptions
        {
            WaitUntil = WaitUntilState.DOMContentLoaded,
            Timeout = PlaywrightTimeoutMs
        });
        Checkpoint("authentication:login-navigation:ok");

        await page.Locator("input[name='userName']").FillAsync("Admin");
        await page.Locator("input[name='password']").FillAsync("Admin@123");
        Checkpoint("authentication:submit:start");
        await page.Locator("button[type='submit']").ClickAsync(new LocatorClickOptions { Timeout = PlaywrightTimeoutMs });
        Checkpoint($"authentication:submit:returned:{page.Url}");

        var deadline = DateTime.UtcNow.AddSeconds(15);
        while (DateTime.UtcNow < deadline && page.Url.Contains("/login", StringComparison.OrdinalIgnoreCase))
            await Task.Delay(100);

        if (page.Url.Contains("/login", StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("UX regression fixture could not authenticate the seeded administrator.");
        Checkpoint($"authentication:navigation-complete:{page.Url}");

        var cookies = await context.CookiesAsync();
        var authCookie = cookies.FirstOrDefault(cookie => string.Equals(cookie.Name, "AIWM.Auth", StringComparison.Ordinal));
        if (authCookie is null)
            throw new InvalidOperationException("UX regression fixture completed login navigation without receiving the AIWM.Auth cookie.");
        Checkpoint("authentication:cookie:ok");

        await context.StorageStateAsync(new BrowserContextStorageStateOptions { Path = _storageStatePath });
        Checkpoint("authentication:storage-state:ok");
        return authCookie.Value;
    }

    private async Task ProbeResponseAsync(
        string path,
        string? authCookieValue,
        bool expectBodyBytes,
        string? expectedMediaTypeFragment = null)
    {
        using var handler = new HttpClientHandler { AllowAutoRedirect = false };
        using var client = new HttpClient(handler) { Timeout = Timeout.InfiniteTimeSpan };
        using var request = new HttpRequestMessage(HttpMethod.Get, BaseUrl + path);
        request.Headers.TryAddWithoutValidation("Accept", "text/html,application/javascript,*/*");
        if (!string.IsNullOrWhiteSpace(authCookieValue))
            request.Headers.TryAddWithoutValidation("Cookie", $"AIWM.Auth={authCookieValue}");

        using var headersCts = new CancellationTokenSource(TimeSpan.FromSeconds(5));
        using var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, headersCts.Token);
        var location = response.Headers.Location?.ToString() ?? "-";
        var contentType = response.Content.Headers.ContentType?.ToString() ?? "-";
        var mediaType = response.Content.Headers.ContentType?.MediaType ?? string.Empty;
        _httpProbeLog.Add($"{path} status={(int)response.StatusCode} location={location} content-type={contentType}");

        var firstBytes = Array.Empty<byte>();
        Exception? readError = null;
        try
        {
            await using var stream = await response.Content.ReadAsStreamAsync();
            var buffer = new byte[512];
            using var bodyCts = new CancellationTokenSource(TimeSpan.FromSeconds(5));
            var read = await stream.ReadAsync(buffer.AsMemory(0, buffer.Length), bodyCts.Token);
            firstBytes = buffer[..read];
        }
        catch (Exception ex) when (ex is OperationCanceledException or IOException)
        {
            readError = ex;
        }

        var preview = firstBytes.Length == 0 ? "<no bytes>" : Encoding.UTF8.GetString(firstBytes).Replace("\r", " ").Replace("\n", " ");
        _httpProbeLog.Add($"{path} bytes={firstBytes.Length} preview={preview}");

        var wrongStatus = response.StatusCode != HttpStatusCode.OK;
        var missingBody = expectBodyBytes && firstBytes.Length == 0;
        var wrongMediaType = !string.IsNullOrWhiteSpace(expectedMediaTypeFragment) &&
            !mediaType.Contains(expectedMediaTypeFragment, StringComparison.OrdinalIgnoreCase);

        if (wrongStatus || missingBody || wrongMediaType)
        {
            var reason = readError is null ? string.Empty : $"; read={readError.GetType().Name}: {readError.Message}";
            throw new InvalidOperationException(
                $"UX HTTP probe failed for {path}: status={(int)response.StatusCode}, location={location}, content-type={contentType}, firstBytes={firstBytes.Length}, expected-media~={expectedMediaTypeFragment ?? "-"}{reason}.");
        }
    }

    private void Checkpoint(string message)
    {
        var line = $"{DateTime.UtcNow:O} {message}";
        Console.WriteLine($"[UX-HOST] {message}");
        if (string.IsNullOrWhiteSpace(_repositoryRoot)) return;

        lock (_checkpointLock)
        {
            File.AppendAllText(ArtifactPath("logs", "fixture-checkpoints.log"), line + Environment.NewLine);
        }
    }

    private static int ReserveTcpPort()
    {
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        var port = ((IPEndPoint)listener.LocalEndpoint).Port;
        listener.Stop();
        return port;
    }

    private static string FindRepositoryRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "AIWordPressManager.Web.sln"))) return current.FullName;
            current = current.Parent;
        }
        throw new DirectoryNotFoundException("Could not locate AIWordPressManager.Web.sln from the test output directory.");
    }
}

[CollectionDefinition(Name, DisableParallelization = true)]
public sealed class UxRegressionCollection : ICollectionFixture<UxTestHost>
{
    public const string Name = "UX regression";
}