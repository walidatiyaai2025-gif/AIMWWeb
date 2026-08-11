using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Text;
using Microsoft.Playwright;
using Xunit;

namespace AIWordPressManager.UxTests;

public sealed class UxTestHost : IAsyncLifetime
{
    private readonly List<string> _appLog = [];
    private readonly List<string> _httpProbeLog = [];
    private IPlaywright? _playwright;
    private IBrowser? _browser;
    private Process? _appProcess;
    private string _repositoryRoot = string.Empty;
    private string _runRoot = string.Empty;
    private string _storageStatePath = string.Empty;

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

        var port = ReserveTcpPort();
        BaseUrl = $"http://127.0.0.1:{port}";
        StartApplication(port);
        await WaitForHealthAsync();
        await ProbeResponseAsync("/welcome", null, expectHtmlBytes: true);

        _playwright = await Playwright.CreateAsync();
        _browser = await _playwright.Chromium.LaunchAsync(new BrowserTypeLaunchOptions { Headless = true });
        var authCookie = await CreateAuthenticatedStorageStateAsync();
        await ProbeResponseAsync("/", authCookie, expectHtmlBytes: true);
    }

    public async Task<IBrowserContext> CreateContextAsync(UxViewport viewport, bool authenticated = true)
    {
        var context = await Browser.NewContextAsync(new BrowserNewContextOptions
        {
            ViewportSize = new ViewportSize { Width = viewport.Width, Height = viewport.Height },
            StorageStatePath = authenticated ? _storageStatePath : null
        });

        await context.AddInitScriptAsync("""
            try {
              if (!localStorage.getItem('aiwp-language')) localStorage.setItem('aiwp-language', 'en');
              if (!localStorage.getItem('aiwp-appearance')) localStorage.setItem('aiwp-appearance', 'light');
            } catch (_) { }
            """);
        return context;
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
        if (_browser is not null) await _browser.DisposeAsync();
        _playwright?.Dispose();

        if (_appProcess is { HasExited: false })
        {
            try { _appProcess.Kill(entireProcessTree: true); }
            catch { }
            await _appProcess.WaitForExitAsync();
        }

        if (!string.IsNullOrWhiteSpace(_repositoryRoot))
        {
            await File.WriteAllLinesAsync(ArtifactPath("logs", "web-host.log"), _appLog);
            await File.WriteAllLinesAsync(ArtifactPath("logs", "http-probe.log"), _httpProbeLog);
        }

        try { if (Directory.Exists(_runRoot)) Directory.Delete(_runRoot, recursive: true); }
        catch { }
    }

    private void StartApplication(int port)
    {
        var databasePath = Path.Combine(_runRoot, "ux-regression.db");
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
        startInfo.Environment["Database__ConnectionString"] = $"Data Source={databasePath};Foreign Keys=True;Pooling=False";
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
        await using var context = await Browser.NewContextAsync(new BrowserNewContextOptions
        {
            ViewportSize = new ViewportSize { Width = 1440, Height = 900 }
        });
        var page = await context.NewPageAsync();
        await page.GotoAsync(BaseUrl + "/login?returnUrl=%2F", new PageGotoOptions { WaitUntil = WaitUntilState.DOMContentLoaded });
        await page.Locator("input[name='userName']").FillAsync("Admin");
        await page.Locator("input[name='password']").FillAsync("Admin@123");
        await page.Locator("button[type='submit']").ClickAsync();

        var deadline = DateTime.UtcNow.AddSeconds(15);
        while (DateTime.UtcNow < deadline && page.Url.Contains("/login", StringComparison.OrdinalIgnoreCase))
            await Task.Delay(100);

        if (page.Url.Contains("/login", StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("UX regression fixture could not authenticate the seeded administrator.");

        var cookies = await context.CookiesAsync();
        var authCookie = cookies.FirstOrDefault(cookie => string.Equals(cookie.Name, "AIWM.Auth", StringComparison.Ordinal));
        if (authCookie is null)
            throw new InvalidOperationException("UX regression fixture completed login navigation without receiving the AIWM.Auth cookie.");

        await context.StorageStateAsync(new BrowserContextStorageStateOptions { Path = _storageStatePath });
        return authCookie.Value;
    }

    private async Task ProbeResponseAsync(string path, string? authCookieValue, bool expectHtmlBytes)
    {
        using var handler = new HttpClientHandler { AllowAutoRedirect = false };
        using var client = new HttpClient(handler) { Timeout = Timeout.InfiniteTimeSpan };
        using var request = new HttpRequestMessage(HttpMethod.Get, BaseUrl + path);
        request.Headers.TryAddWithoutValidation("Accept", "text/html");
        if (!string.IsNullOrWhiteSpace(authCookieValue))
            request.Headers.TryAddWithoutValidation("Cookie", $"AIWM.Auth={authCookieValue}");

        using var headersCts = new CancellationTokenSource(TimeSpan.FromSeconds(5));
        using var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, headersCts.Token);
        var location = response.Headers.Location?.ToString() ?? "-";
        var contentType = response.Content.Headers.ContentType?.ToString() ?? "-";
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

        if (expectHtmlBytes && (response.StatusCode != HttpStatusCode.OK || firstBytes.Length == 0))
        {
            var reason = readError is null ? string.Empty : $"; read={readError.GetType().Name}: {readError.Message}";
            throw new InvalidOperationException($"UX HTTP probe failed for {path}: status={(int)response.StatusCode}, location={location}, content-type={contentType}, firstBytes={firstBytes.Length}{reason}.");
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
