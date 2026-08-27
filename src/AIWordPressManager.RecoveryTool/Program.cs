using System.Security.Cryptography;
using AIWordPressManager.Infrastructure.Security;

namespace AIWordPressManager.RecoveryTool;

internal static class Program
{
    private static int Main(string[] args)
    {
        if (args.Length == 0)
        {
            WriteUsage();
            return 2;
        }

        try
        {
            return args[0].ToLowerInvariant() switch
            {
                "install-secret-key" => InstallSecretKey(args),
                "restore" => Restore(args),
                _ => UnknownCommand()
            };
        }
        catch (Exception ex) when (ex is ArgumentException or InvalidDataException or InvalidOperationException or IOException or FileNotFoundException or CryptographicException or System.Text.Json.JsonException)
        {
            Console.Error.WriteLine($"Recovery failed: {ex.Message}");
            return 3;
        }
    }

    private static int InstallSecretKey(string[] args)
    {
        var backupPath = GetOption(args, "--backup")
            ?? throw new ArgumentException("--backup <path> is required.");
        var localApplicationData = GetOption(args, "--local-app-data");
        var replaceExisting = HasFlag(args, "--replace-existing");
        var secretFromStdin = HasFlag(args, "--recovery-secret-stdin");
        RejectUnsupportedSecretArguments(args);

        Console.WriteLine("AI WordPress Manager offline secret-key recovery");
        Console.WriteLine("Stop every web application worker before continuing.");
        Console.WriteLine($"Backup: {Path.GetFullPath(backupPath)}");
        if (replaceExisting)
            Console.WriteLine("Existing key replacement: explicitly enabled");

        var recoverySecret = secretFromStdin ? ReadSecretFromStdin() : ReadSecretInteractively();
        try
        {
            var result = new OfflineSecretRecoveryInstaller().InstallFromBackup(
                backupPath,
                recoverySecret,
                replaceExisting,
                localApplicationData);

            Console.WriteLine(result.Message);
            Console.WriteLine($"Key path: {result.KeyPath}");
            Console.WriteLine($"Status: {result.Status}");
            return 0;
        }
        finally
        {
            recoverySecret = string.Empty;
        }
    }

    private static int Restore(string[] args)
    {
        var backupPath = GetOption(args, "--backup")
            ?? throw new ArgumentException("--backup <path> is required.");
        var applicationRoot = GetOption(args, "--application-root");
        var localApplicationData = GetOption(args, "--local-app-data");
        var replaceExistingKey = HasFlag(args, "--replace-existing-key");
        var secretFromStdin = HasFlag(args, "--recovery-secret-stdin");
        RejectUnsupportedSecretArguments(args);

        Console.WriteLine("AI WordPress Manager full offline restore");
        Console.WriteLine("Stop every web application worker before continuing. The restore is blocked while a runtime lease is held.");
        Console.WriteLine($"Backup: {Path.GetFullPath(backupPath)}");
        if (!string.IsNullOrWhiteSpace(applicationRoot))
            Console.WriteLine($"Application root: {Path.GetFullPath(applicationRoot)}");
        if (replaceExistingKey)
            Console.WriteLine("Existing secret key replacement: explicitly enabled");

        var recoverySecret = secretFromStdin ? ReadSecretFromStdin() : ReadSecretInteractively();
        try
        {
            var result = new OfflineApplicationRestoreService().RestoreFromBackup(
                backupPath,
                recoverySecret,
                replaceExistingKey,
                applicationRoot,
                localApplicationData);

            Console.WriteLine(result.Message);
            Console.WriteLine($"Application root: {result.ApplicationRoot}");
            Console.WriteLine($"Database: {result.DatabasePath}");
            Console.WriteLine($"Managed files restored: {result.ManagedFileCount}");
            Console.WriteLine($"Backup created UTC: {result.BackupCreatedAtUtc:O}");
            Console.WriteLine($"Secret key status: {result.SecretKeyStatus}");
            Console.WriteLine("Provenance: AIMW-LAST-OFFLINE-RESTORE.txt");
            return 0;
        }
        finally
        {
            recoverySecret = string.Empty;
        }
    }

    private static int UnknownCommand()
    {
        WriteUsage();
        return 2;
    }

    private static void RejectUnsupportedSecretArguments(string[] args)
    {
        foreach (var arg in args.Skip(1))
        {
            if (arg.StartsWith("--recovery-secret=", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(arg, "--recovery-secret", StringComparison.OrdinalIgnoreCase))
            {
                throw new ArgumentException("The recovery secret is never accepted as a command-line value. Use masked interactive entry or --recovery-secret-stdin.");
            }
        }
    }

    private static string ReadSecretInteractively()
    {
        if (Console.IsInputRedirected)
            throw new InvalidOperationException("Interactive recovery-secret input is unavailable. Use --recovery-secret-stdin with redirected standard input.");

        Console.Write("Recovery secret: ");
        var buffer = new List<char>(64);
        try
        {
            while (true)
            {
                var key = Console.ReadKey(intercept: true);
                if (key.Key == ConsoleKey.Enter)
                {
                    Console.WriteLine();
                    var value = new string(buffer.ToArray());
                    if (string.IsNullOrWhiteSpace(value))
                        throw new InvalidOperationException("Recovery secret is required.");
                    return value;
                }

                if (key.Key == ConsoleKey.Backspace)
                {
                    if (buffer.Count > 0) buffer.RemoveAt(buffer.Count - 1);
                    continue;
                }

                if (!char.IsControl(key.KeyChar) && buffer.Count < 1024)
                    buffer.Add(key.KeyChar);
            }
        }
        finally
        {
            for (var i = 0; i < buffer.Count; i++) buffer[i] = '\0';
            buffer.Clear();
        }
    }

    private static string ReadSecretFromStdin()
    {
        var secret = Console.ReadLine();
        if (string.IsNullOrWhiteSpace(secret))
            throw new InvalidOperationException("No recovery secret was received on standard input.");
        return secret;
    }

    private static string? GetOption(string[] args, string name)
    {
        for (var i = 1; i < args.Length; i++)
        {
            if (!string.Equals(args[i], name, StringComparison.OrdinalIgnoreCase)) continue;
            if (i + 1 >= args.Length || args[i + 1].StartsWith("--", StringComparison.Ordinal))
                throw new ArgumentException($"{name} requires a value.");
            return args[i + 1];
        }
        return null;
    }

    private static bool HasFlag(string[] args, string name) =>
        args.Skip(1).Any(x => string.Equals(x, name, StringComparison.OrdinalIgnoreCase));

    private static void WriteUsage()
    {
        Console.WriteLine("Usage:");
        Console.WriteLine("  AIWordPressManager.RecoveryTool install-secret-key --backup <backup.zip> [--replace-existing] [--local-app-data <path>] [--recovery-secret-stdin]");
        Console.WriteLine("  AIWordPressManager.RecoveryTool restore --backup <backup.zip> [--replace-existing-key] [--application-root <path>] [--local-app-data <path>] [--recovery-secret-stdin]");
        Console.WriteLine();
        Console.WriteLine("The recovery secret is never accepted as a command-line value. Enter it interactively or redirect it through standard input.");
        Console.WriteLine("Full restore supports manifest-v5 SQLite backups with authenticated wrapped secret-recovery material.");
    }
}
