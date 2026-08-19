using AIWordPressManager.Infrastructure.Security;

namespace AIWordPressManager.RecoveryTool;

internal static class Program
{
    private static int Main(string[] args)
    {
        if (args.Length == 0 || !string.Equals(args[0], "install-secret-key", StringComparison.OrdinalIgnoreCase))
        {
            WriteUsage();
            return 2;
        }

        try
        {
            var backupPath = GetOption(args, "--backup")
                ?? throw new ArgumentException("--backup <path> is required.");
            var localApplicationData = GetOption(args, "--local-app-data");
            var replaceExisting = HasFlag(args, "--replace-existing");
            var secretFromStdin = HasFlag(args, "--recovery-secret-stdin");

            Console.WriteLine("AI WordPress Manager offline secret-key recovery");
            Console.WriteLine("The web application must be stopped. The tool will refuse to continue if the runtime lock is held.");
            Console.WriteLine($"Backup: {Path.GetFullPath(backupPath)}");
            if (replaceExisting)
                Console.WriteLine("Existing key replacement: explicitly enabled");

            var recoverySecret = secretFromStdin ? ReadSecretFromStdin() : ReadSecretInteractively();
            try
            {
                var installer = new OfflineSecretRecoveryInstaller();
                var result = installer.InstallFromBackup(
                    backupPath,
                    recoverySecret,
                    replaceExisting,
                    localApplicationData);

                Console.WriteLine(result.Message);
                Console.WriteLine($"Key path: {result.KeyPath}");
                Console.WriteLine($"Status: {result.Status}");
                Console.WriteLine("No application data was restored by this command. Run the remaining offline restore workflow separately.");
                return 0;
            }
            finally
            {
                recoverySecret = string.Empty;
            }
        }
        catch (Exception ex) when (ex is ArgumentException or InvalidDataException or InvalidOperationException or IOException or System.Security.Cryptography.CryptographicException)
        {
            Console.Error.WriteLine($"Recovery failed: {ex.Message}");
            return 3;
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
                    return new string(buffer.ToArray());
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
        Console.WriteLine();
        Console.WriteLine("The recovery secret is never accepted as a command-line value. Enter it interactively or redirect it through standard input.");
    }
}
