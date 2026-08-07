using System.Data.Common;
using System.Net;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.Persistence;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.Configuration;

namespace AIWordPressManager.Web.Services;

public sealed record DatabaseSetupRequest(
    string Provider,
    string? SqlitePath,
    string? Host,
    int? Port,
    string? DatabaseName,
    string? UserName,
    string? Password,
    bool IntegratedSecurity,
    bool TrustServerCertificate,
    string? AdminUserName = null,
    string? AdminPassword = null,
    string? AdminConfirmPassword = null);

public sealed class DatabaseSetupService(
    IConfiguration configuration,
    IWebHostEnvironment environment,
    IApplicationPathService paths,
    IServiceScopeFactory scopeFactory,
    ILogger<DatabaseSetupService> logger)
{
    private static readonly HashSet<string> SupportedProviders = new(StringComparer.OrdinalIgnoreCase)
    {
        "SQLite", "SqlServer", "PostgreSQL", "MySQL", "MariaDB"
    };

    public bool IsComplete => configuration.GetValue<bool>("Database:SetupComplete");

    public string ConfigurationPath => GetConfigurationPath(environment.EnvironmentName);

    public string DefaultSqlitePath => paths.GetDatabasePath();

    public static string GetConfigurationPath(string environmentName)
    {
        var root = string.Equals(environmentName, "Development", StringComparison.OrdinalIgnoreCase)
            ? Path.Combine(AppContext.BaseDirectory, "Data")
            : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager", "Config");
        Directory.CreateDirectory(root);
        return Path.Combine(root, "setup.database.json");
    }

    public static string GetLegacyDatabasePath(string environmentName)
    {
        if (string.Equals(environmentName, "Development", StringComparison.OrdinalIgnoreCase))
            return Path.Combine(AppContext.BaseDirectory, "Data", "AIWordPressManager.Development.db");

        return Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AIWordPressManager",
            "Data",
            "AIWordPressManager.db");
    }

    public static void BootstrapLegacySqliteIfNeeded(string environmentName)
    {
        var configPath = GetConfigurationPath(environmentName);
        if (File.Exists(configPath)) return;

        var legacyPath = GetLegacyDatabasePath(environmentName);
        if (!File.Exists(legacyPath)) return;

        var connectionString = new SqliteConnectionStringBuilder
        {
            DataSource = legacyPath,
            ForeignKeys = true,
            Pooling = true
        }.ToString();

        WriteConfigurationFile(configPath, "SQLite", connectionString, true);
    }

    public async Task ApplyAsync(DatabaseSetupRequest request, CancellationToken cancellationToken = default)
    {
        var provider = NormalizeProvider(request.Provider);
        var connectionString = BuildConnectionString(provider, request);

        logger.LogInformation("Applying database setup for provider {Provider}.", provider);

        WriteConfigurationFile(ConfigurationPath, provider, connectionString, false);
        ReloadConfiguration();

        try
        {
            await using var scope = scopeFactory.CreateAsyncScope();
            await scope.ServiceProvider
                .GetRequiredService<IDatabaseInitializationService>()
                .InitializeAsync(cancellationToken);

            var authentication = scope.ServiceProvider.GetRequiredService<LocalAuthenticationService>();
            var hasExistingAccounts = await authentication.HasAccountsAsync(cancellationToken);

            if (!hasExistingAccounts)
            {
                if (string.IsNullOrWhiteSpace(request.AdminUserName))
                    throw new InvalidOperationException("Administrator username is required for a new database.");
                if (string.IsNullOrWhiteSpace(request.AdminPassword))
                    throw new InvalidOperationException("Administrator password is required for a new database.");
                if (string.IsNullOrWhiteSpace(request.AdminConfirmPassword))
                    throw new InvalidOperationException("Administrator password confirmation is required for a new database.");

                var accountResult = await authentication.CreateInitialAdministratorAsync(
                    request.AdminUserName,
                    request.AdminPassword,
                    request.AdminConfirmPassword,
                    cancellationToken);

                if (!accountResult.IsSuccess)
                    throw new InvalidOperationException(accountResult.Message);
            }

            // Preserve existing accounts on recovery and assign any legacy unowned sites
            // to the existing/custom administrator without creating a duplicate admin.
            await authentication.SeedAsync(cancellationToken);

            WriteConfigurationFile(ConfigurationPath, provider, connectionString, true);
            ReloadConfiguration();
            logger.LogInformation(
                "Database setup completed for provider {Provider}. Existing accounts detected: {ExistingAccounts}.",
                provider,
                hasExistingAccounts);
        }
        catch
        {
            WriteConfigurationFile(ConfigurationPath, provider, connectionString, false);
            ReloadConfiguration();
            throw;
        }
    }

    public string RenderPage(string? error = null, DatabaseSetupRequest? previous = null)
    {
        var provider = NormalizeProvider(previous?.Provider ?? "SQLite");
        var sqlitePath = previous?.SqlitePath ?? DefaultSqlitePath;
        var host = previous?.Host ?? "localhost";
        var database = previous?.DatabaseName ?? "AIWordPressManager";
        var user = previous?.UserName ?? string.Empty;
        var adminUser = previous?.AdminUserName ?? "Admin";
        var errorHtml = string.IsNullOrWhiteSpace(error)
            ? string.Empty
            : $"<div class=\"error\"><strong>Setup failed</strong><div>{WebUtility.HtmlEncode(error)}</div></div>";

        static string Selected(string actual, string expected) => actual.Equals(expected, StringComparison.OrdinalIgnoreCase) ? " selected" : string.Empty;
        static string H(string value) => WebUtility.HtmlEncode(value);

        return $$"""
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI WordPress Manager - First Run Setup</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#090d14;color:#edf2f7;min-height:100vh}.shell{max-width:980px;margin:0 auto;padding:36px 20px 60px}.brand{display:flex;gap:14px;align-items:center;margin-bottom:26px}.logo{width:52px;height:52px;border-radius:14px;background:#d7b45a;color:#151515;display:grid;place-items:center;font-weight:900;font-size:20px}.brand h1{margin:0;font-size:25px}.brand p{margin:4px 0 0;color:#9aa7b8}.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}.step{background:#121925;border:1px solid #273244;border-radius:12px;padding:12px;color:#9aa7b8}.step.active{border-color:#d7b45a;color:#fff}.card{background:#111823;border:1px solid #273244;border-radius:18px;padding:26px;box-shadow:0 20px 60px #0006}.section-title{grid-column:1/-1;margin:8px 0 0;padding-top:14px;border-top:1px solid #273244}.section-title:first-child{border-top:0;padding-top:0}.section-title h2{font-size:17px;margin:0 0 4px}.section-title p{margin:0;color:#8491a5;font-size:13px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}.full{grid-column:1/-1}label{display:block;font-size:13px;color:#b8c2d1;margin-bottom:6px}input,select{width:100%;padding:12px 13px;background:#0b111a;border:1px solid #344155;color:#fff;border-radius:9px;outline:none}input:focus,select:focus{border-color:#d7b45a}.help{font-size:12px;color:#8491a5;margin-top:6px}.row{display:flex;align-items:center;gap:10px}.row input[type=checkbox]{width:auto}.actions{display:flex;justify-content:space-between;align-items:center;margin-top:24px;gap:12px}.primary{border:0;background:#d7b45a;color:#111827;padding:13px 22px;border-radius:9px;font-weight:800;cursor:pointer}.note{color:#9aa7b8;font-size:13px}.error{background:#431c24;border:1px solid #a94455;color:#ffd7dc;padding:14px;border-radius:10px;margin-bottom:18px}.provider-fields{display:none}.provider-fields.show{display:contents}@media(max-width:700px){.grid{grid-template-columns:1fr}.full,.section-title{grid-column:auto}.steps{grid-template-columns:1fr}.actions{align-items:stretch;flex-direction:column}.primary{width:100%}}
</style>
</head>
<body>
<div class="shell">
  <div class="brand"><div class="logo">AI</div><div><h1>AI WordPress Manager</h1><p>First-run setup</p></div></div>
  <div class="steps"><div class="step active"><strong>1. Database</strong><br>Choose storage and connection</div><div class="step active"><strong>2. Administrator</strong><br>Create the first admin when needed</div><div class="step"><strong>3. Sign in</strong><br>Continue to the application</div></div>
  <div class="card">
    {{errorHtml}}
    <form method="post" action="/setup">
      <div class="grid">
        <div class="section-title"><h2>Database</h2><p>Select the provider and enter only the fields required for that provider.</p></div>
        <div class="full"><label>Database provider</label><select id="provider" name="provider" onchange="providerChanged()">
          <option value="SQLite"{{Selected(provider,"SQLite")}}>SQLite — easiest / local database</option>
          <option value="SqlServer"{{Selected(provider,"SqlServer")}}>Microsoft SQL Server</option>
          <option value="PostgreSQL"{{Selected(provider,"PostgreSQL")}}>PostgreSQL</option>
          <option value="MySQL"{{Selected(provider,"MySQL")}}>MySQL</option>
          <option value="MariaDB"{{Selected(provider,"MariaDB")}}>MariaDB</option>
        </select><div class="help">SQLite is recommended for one server. Choose a server database for shared or managed infrastructure.</div></div>

        <div id="sqliteFields" class="provider-fields"><div class="full"><label>SQLite database file</label><input name="sqlitePath" value="{{H(sqlitePath)}}"><div class="help">The application creates the file and parent directory when possible.</div></div></div>

        <div id="serverFields" class="provider-fields">
          <div><label>Database server / host</label><input name="host" value="{{H(host)}}" placeholder="db-server or 127.0.0.1"></div>
          <div><label>Port (optional)</label><input id="port" name="port" type="number" min="1" max="65535" placeholder="Default provider port"></div>
          <div><label>Database name</label><input name="databaseName" value="{{H(database)}}"></div>
          <div id="userField"><label>Database user</label><input name="userName" value="{{H(user)}}" autocomplete="username"></div>
          <div id="passwordField"><label>Database password</label><input name="password" type="password" autocomplete="current-password"></div>
          <div id="sqlOptions" class="full"><label class="row"><input id="integratedSecurity" type="checkbox" name="integratedSecurity" value="true" onchange="authChanged()"> Use Windows / Integrated authentication</label></div>
          <div class="full"><label class="row"><input type="checkbox" name="trustServerCertificate" value="true"> Trust server certificate</label><div class="help">Use only when the database uses an internal or self-signed TLS certificate.</div></div>
        </div>

        <div class="section-title"><h2>Administrator account</h2><p>Required for a new/empty database. If the selected database already contains application accounts, the existing accounts are preserved and these fields are ignored.</p></div>
        <div class="full"><label>Administrator username</label><input name="adminUserName" value="{{H(adminUser)}}" minlength="3" maxlength="64" autocomplete="username"></div>
        <div><label>Administrator password</label><input name="adminPassword" type="password" minlength="8" autocomplete="new-password"><div class="help">For a new database: at least 8 characters with uppercase, lowercase and a number.</div></div>
        <div><label>Confirm administrator password</label><input name="adminConfirmPassword" type="password" minlength="8" autocomplete="new-password"></div>
      </div>
      <div class="actions"><div class="note">Setup is stored locally on this machine and is not committed to Git.</div><button class="primary" type="submit">Test, initialize and continue →</button></div>
    </form>
  </div>
</div>
<script>
function providerChanged(){const p=document.getElementById('provider').value;const sqlite=p==='SQLite';document.getElementById('sqliteFields').classList.toggle('show',sqlite);document.getElementById('serverFields').classList.toggle('show',!sqlite);document.getElementById('sqlOptions').style.display=p==='SqlServer'?'block':'none';const port=document.getElementById('port');if(!port.value){port.placeholder=p==='PostgreSQL'?'5432':(p==='MySQL'||p==='MariaDB')?'3306':p==='SqlServer'?'1433':'Default provider port';}authChanged();}
function authChanged(){const integrated=document.getElementById('provider').value==='SqlServer'&&document.getElementById('integratedSecurity').checked;document.getElementById('userField').style.display=integrated?'none':'block';document.getElementById('passwordField').style.display=integrated?'none':'block';}
providerChanged();
</script>
</body></html>
""";
    }

    private string BuildConnectionString(string provider, DatabaseSetupRequest request)
    {
        if (provider.Equals("SQLite", StringComparison.OrdinalIgnoreCase))
        {
            var file = string.IsNullOrWhiteSpace(request.SqlitePath) ? DefaultSqlitePath : Path.GetFullPath(request.SqlitePath.Trim());
            var parent = Path.GetDirectoryName(file);
            if (!string.IsNullOrWhiteSpace(parent)) Directory.CreateDirectory(parent);
            return new SqliteConnectionStringBuilder { DataSource = file, ForeignKeys = true, Pooling = true }.ToString();
        }

        if (string.IsNullOrWhiteSpace(request.Host)) throw new InvalidOperationException("Database server/host is required.");
        if (string.IsNullOrWhiteSpace(request.DatabaseName)) throw new InvalidOperationException("Database name is required.");

        var builder = new DbConnectionStringBuilder();
        if (provider.Equals("SqlServer", StringComparison.OrdinalIgnoreCase))
        {
            builder["Data Source"] = request.Port is > 0 ? $"{request.Host},{request.Port}" : request.Host;
            builder["Initial Catalog"] = request.DatabaseName;
            builder["Encrypt"] = true;
            builder["TrustServerCertificate"] = request.TrustServerCertificate;
            if (request.IntegratedSecurity)
                builder["Integrated Security"] = true;
            else
            {
                RequireCredentials(request);
                builder["User ID"] = request.UserName!;
                builder["Password"] = request.Password!;
            }
            return builder.ConnectionString;
        }

        builder["Host"] = request.Host;
        builder["Port"] = request.Port is > 0 ? request.Port.Value : provider.Equals("PostgreSQL", StringComparison.OrdinalIgnoreCase) ? 5432 : 3306;
        builder["Database"] = request.DatabaseName;
        RequireCredentials(request);
        builder[provider.Equals("PostgreSQL", StringComparison.OrdinalIgnoreCase) ? "Username" : "User ID"] = request.UserName!;
        builder["Password"] = request.Password!;

        if (provider.Equals("PostgreSQL", StringComparison.OrdinalIgnoreCase))
        {
            builder["SSL Mode"] = "Prefer";
            if (request.TrustServerCertificate) builder["Trust Server Certificate"] = true;
        }
        else
        {
            builder["SslMode"] = request.TrustServerCertificate ? "Preferred" : "Required";
        }

        return builder.ConnectionString;
    }

    private static void RequireCredentials(DatabaseSetupRequest request)
    {
        if (string.IsNullOrWhiteSpace(request.UserName)) throw new InvalidOperationException("Database user is required.");
        if (string.IsNullOrWhiteSpace(request.Password)) throw new InvalidOperationException("Database password is required.");
    }

    private static string NormalizeProvider(string provider)
    {
        if (string.IsNullOrWhiteSpace(provider)) return "SQLite";
        if (!SupportedProviders.Contains(provider)) throw new InvalidOperationException($"Unsupported database provider: {provider}");
        return SupportedProviders.First(x => x.Equals(provider, StringComparison.OrdinalIgnoreCase));
    }

    private void ReloadConfiguration()
    {
        if (configuration is IConfigurationRoot root) root.Reload();
    }

    private static void WriteConfigurationFile(string path, string provider, string connectionString, bool complete)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        var payload = new
        {
            Database = new
            {
                SetupComplete = complete,
                Provider = provider,
                ConnectionString = connectionString,
                ConfiguredAtUtc = DateTime.UtcNow
            }
        };
        var json = JsonSerializer.Serialize(payload, new JsonSerializerOptions { WriteIndented = true });
        var temp = path + ".tmp";
        File.WriteAllText(temp, json);
        File.Move(temp, path, true);
    }
}
