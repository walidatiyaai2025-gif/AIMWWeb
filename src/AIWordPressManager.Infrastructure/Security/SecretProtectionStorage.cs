using System.Security.Cryptography;

namespace AIWordPressManager.Infrastructure.Security;

public static class SecretProtectionStorage
{
    public const string KeyFileName = ".secret-key";
    public const string RuntimeLockFileName = ".secret-recovery-runtime.lock";
    public const int MasterKeySize = 32;

    public static string GetSecurityDirectory(string? localApplicationData = null)
    {
        var localRoot = string.IsNullOrWhiteSpace(localApplicationData)
            ? Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData)
            : Path.GetFullPath(localApplicationData);
        if (!string.IsNullOrWhiteSpace(localRoot))
            return Path.Combine(localRoot, "AIWordPressManager", "Security");

        return Path.Combine(AppContext.BaseDirectory, "Data", "Security");
    }

    public static string GetKeyPath(string? localApplicationData = null) =>
        Path.Combine(GetSecurityDirectory(localApplicationData), KeyFileName);

    public static RuntimeLockLease AcquireRuntimeLock(string? localApplicationData = null)
    {
        var directory = GetSecurityDirectory(localApplicationData);
        Directory.CreateDirectory(directory);
        var path = Path.Combine(directory, RuntimeLockFileName);
        try
        {
            var stream = new FileStream(path, FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None);
            stream.SetLength(0);
            using var writer = new StreamWriter(stream, leaveOpen: true);
            writer.Write($"pid={Environment.ProcessId};startedUtc={DateTime.UtcNow:O}");
            writer.Flush();
            stream.Flush(flushToDisk: true);
            stream.Position = 0;
            return new RuntimeLockLease(path, stream);
        }
        catch (IOException ex)
        {
            throw new InvalidOperationException(
                "The AI WordPress Manager runtime lock is already held. Stop the web application before installing or replacing the secret-protection key.",
                ex);
        }
    }

    public static byte[] ReadAndValidateKey(string path)
    {
        try
        {
            var key = Convert.FromBase64String(File.ReadAllText(path).Trim());
            if (key.Length != MasterKeySize)
            {
                CryptographicOperations.ZeroMemory(key);
                throw new CryptographicException("The application encryption key has an invalid length.");
            }
            return key;
        }
        catch (FormatException ex)
        {
            throw new CryptographicException("The application encryption key is not valid Base64 data.", ex);
        }
    }

    public static void WriteKeyAtomically(string path, ReadOnlySpan<byte> key, bool replaceExisting)
    {
        if (key.Length != MasterKeySize)
            throw new ArgumentException($"The secret-protection key must be exactly {MasterKeySize} bytes.", nameof(key));

        var directory = Path.GetDirectoryName(Path.GetFullPath(path))
                        ?? throw new InvalidOperationException("The secret-protection key path does not have a parent directory.");
        Directory.CreateDirectory(directory);
        var temp = Path.Combine(directory, $".{KeyFileName}.{Guid.NewGuid():N}.tmp");
        var backup = Path.Combine(directory, $".{KeyFileName}.{Guid.NewGuid():N}.bak");
        try
        {
            File.WriteAllText(temp, Convert.ToBase64String(key));
            HardenKeyFilePermissions(temp);

            if (!File.Exists(path))
            {
                File.Move(temp, path);
            }
            else
            {
                if (!replaceExisting)
                    throw new InvalidOperationException("A secret-protection key already exists. Use explicit replacement only after verifying that the existing key is no longer authoritative.");

                try
                {
                    File.Replace(temp, path, backup, ignoreMetadataErrors: true);
                }
                catch (PlatformNotSupportedException)
                {
                    File.Copy(path, backup, overwrite: false);
                    File.Move(temp, path, overwrite: true);
                }
            }

            HardenKeyFilePermissions(path);
            var written = ReadAndValidateKey(path);
            try
            {
                if (!CryptographicOperations.FixedTimeEquals(written, key))
                    throw new CryptographicException("The installed secret-protection key failed post-write verification.");
            }
            finally
            {
                CryptographicOperations.ZeroMemory(written);
            }

            if (File.Exists(backup)) File.Delete(backup);
        }
        catch
        {
            if (File.Exists(backup))
            {
                try { File.Move(backup, path, overwrite: true); }
                catch { }
            }
            throw;
        }
        finally
        {
            if (File.Exists(temp)) File.Delete(temp);
        }
    }

    private static void HardenKeyFilePermissions(string path)
    {
        if (OperatingSystem.IsWindows()) return;
        try
        {
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
        }
        catch (PlatformNotSupportedException)
        {
            // The containing application security directory remains the fallback boundary.
        }
    }
}

public sealed class RuntimeLockLease : IDisposable
{
    private FileStream? _stream;

    internal RuntimeLockLease(string path, FileStream stream)
    {
        Path = path;
        _stream = stream;
    }

    public string Path { get; }

    public void Dispose()
    {
        var stream = Interlocked.Exchange(ref _stream, null);
        stream?.Dispose();
    }
}
