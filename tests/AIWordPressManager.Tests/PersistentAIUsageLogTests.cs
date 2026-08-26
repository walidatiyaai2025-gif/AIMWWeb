using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Infrastructure.AI;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class PersistentAIUsageLogTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "aiwm-ai-usage-tests", Guid.NewGuid().ToString("N"));

    [Fact]
    public void Records_Are_Persisted_And_Reloaded_With_Tenant_Filtering()
    {
        var ownerA = Guid.NewGuid();
        var ownerB = Guid.NewGuid();
        var siteA = Guid.NewGuid();
        var paths = new TestPaths(_root);
        var log = Create(paths);

        log.Record(Entry(ownerA, siteA, "OpenAI", true, 120, 40, 0.012m));
        log.Record(Entry(ownerB, Guid.NewGuid(), "Gemini", false, 50, 0, 0));

        var reloaded = Create(paths);
        var ownerAEntries = reloaded.GetRecent(100, null, ownerA.ToString("D"));
        var ownerBEntries = reloaded.GetRecent(100, null, ownerB.ToString("D"));

        var first = Assert.Single(ownerAEntries);
        Assert.Equal(siteA, first.SiteId);
        Assert.Equal("OpenAI", first.Provider);
        Assert.Equal(0.012m, first.EstimatedCost);
        Assert.Single(ownerBEntries);
        Assert.Empty(reloaded.GetRecent(100, siteA, ownerB.ToString("D")));
    }

    [Fact]
    public void Tenant_Filtering_Is_Applied_Before_The_Per_Request_Limit()
    {
        var ownerA = Guid.NewGuid();
        var ownerB = Guid.NewGuid();
        var paths = new TestPaths(_root);
        var log = Create(paths);
        var start = DateTime.UtcNow.AddMinutes(-5);

        log.Record(new AIUsageEntry(start, "OpenAI", "a", "owner-a-older", null, ownerA.ToString("D"), 1, 1, 0, true, null));
        for (var index = 0; index < 25; index++)
        {
            log.Record(new AIUsageEntry(
                start.AddSeconds(index + 1),
                "Gemini",
                "b",
                $"owner-b-{index}",
                null,
                ownerB.ToString("D"),
                1,
                1,
                0,
                true,
                null));
        }
        log.Record(new AIUsageEntry(start.AddMinutes(1), "OpenAI", "a", "owner-a-newer", null, ownerA.ToString("D"), 1, 1, 0, true, null));

        var ownerAEntries = log.GetRecent(2, null, ownerA.ToString("D"));

        Assert.Equal(2, ownerAEntries.Count);
        Assert.Equal("owner-a-newer", ownerAEntries[0].Operation);
        Assert.Equal("owner-a-older", ownerAEntries[1].Operation);
        Assert.DoesNotContain(ownerAEntries, x => string.Equals(x.UserId, ownerB.ToString("D"), StringComparison.OrdinalIgnoreCase));
    }

    [Fact]
    public void Retention_Is_Bounded_To_The_Most_Recent_Entries()
    {
        var owner = Guid.NewGuid();
        var paths = new TestPaths(_root);
        var log = Create(paths, 5);
        var start = DateTime.UtcNow.AddMinutes(-1);

        for (var index = 0; index < 8; index++)
        {
            log.Record(new AIUsageEntry(
                start.AddSeconds(index),
                "OpenAI",
                "test-model",
                $"op-{index}",
                null,
                owner.ToString("D"),
                index,
                1,
                0,
                true,
                null));
        }

        var reloaded = Create(paths, 5);
        var entries = reloaded.GetRecent(100, null, owner.ToString("D"));

        Assert.Equal(5, entries.Count);
        Assert.Equal("op-7", entries[0].Operation);
        Assert.Equal("op-3", entries[^1].Operation);
        Assert.DoesNotContain(entries, x => x.Operation == "op-0");
    }

    [Fact]
    public void Corrupt_File_Is_Quarantined_And_Startup_Recovers()
    {
        var dataDirectory = Path.Combine(_root, "Data");
        Directory.CreateDirectory(dataDirectory);
        File.WriteAllText(Path.Combine(dataDirectory, "ai-usage-log.json"), "{not-json");

        var log = Create(new TestPaths(_root));

        Assert.Empty(log.GetRecent());
        Assert.NotEmpty(Directory.GetFiles(dataDirectory, "ai-usage-log.json.corrupt-*"));

        log.Record(Entry(Guid.NewGuid(), null, "Puter", true, 10, 20, 0));
        Assert.Single(log.GetRecent());
        Assert.True(File.Exists(Path.Combine(dataDirectory, "ai-usage-log.json")));
    }

    [Fact]
    public void Record_Normalizes_Negative_Counters_And_User_Id()
    {
        var owner = Guid.NewGuid();
        var log = Create(new TestPaths(_root));

        log.Record(new AIUsageEntry(DateTime.Now, "  OpenAI  ", " model ", " op ", null, owner.ToString("N"), -2, -3, -1, false, " error "));

        var entry = Assert.Single(log.GetRecent(100, null, owner.ToString("D")));
        Assert.Equal("OpenAI", entry.Provider);
        Assert.Equal("model", entry.Model);
        Assert.Equal("op", entry.Operation);
        Assert.Equal(owner.ToString("D"), entry.UserId);
        Assert.Equal(0, entry.InputTokens);
        Assert.Equal(0, entry.OutputTokens);
        Assert.Equal(0m, entry.EstimatedCost);
        Assert.Equal(DateTimeKind.Utc, entry.CreatedAtUtc.Kind);
    }

    private static PersistentAIUsageLog Create(IApplicationPathService paths, int? maxEntries = null) =>
        maxEntries.HasValue
            ? new PersistentAIUsageLog(paths, NullLogger<PersistentAIUsageLog>.Instance, maxEntries.Value)
            : new PersistentAIUsageLog(paths, NullLogger<PersistentAIUsageLog>.Instance);

    private static AIUsageEntry Entry(Guid owner, Guid? siteId, string provider, bool success, int input, int output, decimal cost) =>
        new(DateTime.UtcNow, provider, "test-model", "test-operation", siteId, owner.ToString("D"), input, output, cost, success, success ? null : "failed");

    public void Dispose()
    {
        try { if (Directory.Exists(_root)) Directory.Delete(_root, recursive: true); }
        catch { }
    }

    private sealed class TestPaths(string root) : IApplicationPathService
    {
        public string GetApplicationDataDirectory() { var path = Path.Combine(root, "Data"); Directory.CreateDirectory(path); return path; }
        public string GetDatabasePath() => Path.Combine(GetApplicationDataDirectory(), "test.db");
        public string GetLogsDirectory() => Directory.CreateDirectory(Path.Combine(root, "Logs")).FullName;
        public string GetScreenshotsDirectory() => Directory.CreateDirectory(Path.Combine(root, "Screenshots")).FullName;
        public string GetBackupsDirectory() => Directory.CreateDirectory(Path.Combine(root, "Backups")).FullName;
        public string GetExportsDirectory() => Directory.CreateDirectory(Path.Combine(root, "Exports")).FullName;
        public string GetTemporaryDirectory() => Directory.CreateDirectory(Path.Combine(root, "Temp")).FullName;
    }
}