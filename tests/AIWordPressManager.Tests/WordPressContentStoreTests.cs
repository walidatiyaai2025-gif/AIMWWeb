using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.WordPress;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

[Collection("Workflow database tests")]
public sealed class WordPressContentStoreTests
{
    [Fact]
    public async Task Full_snapshot_marks_remote_deletions_unavailable()
    {
        await using var fixture = await StoreFixture.CreateAsync();
        var siteId = Guid.NewGuid();

        await fixture.Store.SaveSnapshotAsync(siteId, Snapshot(
            posts: [Post(1, "First"), Post(2, "Second")], totalPosts: 2));

        var summary = await fixture.Store.SaveSnapshotAsync(siteId, Snapshot(
            posts: [Post(2, "Second updated")], totalPosts: 1));

        summary.ContentUnavailable.Should().Be(1);
        var records = await fixture.Db.WordPressContentRecords.OrderBy(x => x.WordPressId).ToListAsync();
        records.Single(x => x.WordPressId == 1).IsAvailable.Should().BeFalse();
        records.Single(x => x.WordPressId == 2).IsAvailable.Should().BeTrue();
        records.Single(x => x.WordPressId == 2).Title.Should().Be("Second updated");
    }

    [Fact]
    public async Task Partial_snapshot_does_not_mark_missing_remote_items_unavailable()
    {
        await using var fixture = await StoreFixture.CreateAsync();
        var siteId = Guid.NewGuid();

        await fixture.Store.SaveSnapshotAsync(siteId, Snapshot(
            posts: [Post(1, "First"), Post(2, "Second")], totalPosts: 2));

        var summary = await fixture.Store.SaveSnapshotAsync(siteId, Snapshot(
            posts: [Post(2, "Second")], totalPosts: 5));

        summary.ContentUnavailable.Should().Be(0);
        (await fixture.Db.WordPressContentRecords.SingleAsync(x => x.WordPressId == 1)).IsAvailable.Should().BeTrue();
    }

    [Fact]
    public async Task Duplicate_remote_items_use_the_latest_modified_version()
    {
        await using var fixture = await StoreFixture.CreateAsync();
        var siteId = Guid.NewGuid();
        var older = DateTimeOffset.UtcNow.AddHours(-2);
        var newer = DateTimeOffset.UtcNow;

        await fixture.Store.SaveSnapshotAsync(siteId, Snapshot(
            posts:
            [
                Post(7, "Old title", older),
                Post(7, "New title", newer)
            ],
            totalPosts: 1));

        var record = await fixture.Db.WordPressContentRecords.SingleAsync();
        record.Title.Should().Be("New title");
        record.ModifiedAtUtc.Should().Be(newer.UtcDateTime);
    }

    [Fact]
    public async Task Reappearing_item_is_restored_to_available_state()
    {
        await using var fixture = await StoreFixture.CreateAsync();
        var siteId = Guid.NewGuid();

        await fixture.Store.SaveSnapshotAsync(siteId, Snapshot(posts: [Post(1, "First")], totalPosts: 1));
        await fixture.Store.SaveSnapshotAsync(siteId, Snapshot(posts: [], totalPosts: 0));
        (await fixture.Db.WordPressContentRecords.SingleAsync()).IsAvailable.Should().BeFalse();

        await fixture.Store.SaveSnapshotAsync(siteId, Snapshot(posts: [Post(1, "First restored")], totalPosts: 1));

        var record = await fixture.Db.WordPressContentRecords.SingleAsync();
        record.IsAvailable.Should().BeTrue();
        record.Title.Should().Be("First restored");
    }

    private static WordPressContentItem Post(int id, string title, DateTimeOffset? modified = null) =>
        new(id, title, $"post-{id}", "publish", $"https://example.test/post-{id}", modified ?? DateTimeOffset.UtcNow, "<p>Content</p>", "Excerpt");

    private static WordPressExplorerSnapshot Snapshot(
        IReadOnlyList<WordPressContentItem>? posts = null,
        int totalPosts = 0) =>
        new(
            posts ?? [],
            [],
            [],
            [],
            [],
            totalPosts,
            0,
            0,
            0,
            0,
            DateTimeOffset.UtcNow,
            WordPressSyncSummary.Empty);

    private sealed class StoreFixture : IAsyncDisposable
    {
        private readonly SqliteConnection _connection;

        private StoreFixture(SqliteConnection connection, AppDbContext db)
        {
            _connection = connection;
            Db = db;
            Store = new WordPressContentStore(db, NullLogger<WordPressContentStore>.Instance);
        }

        public AppDbContext Db { get; }
        public WordPressContentStore Store { get; }

        public static async Task<StoreFixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>()
                .UseSqlite(connection)
                .Options;
            var db = new AppDbContext(options);
            await db.Database.EnsureCreatedAsync();
            return new StoreFixture(connection, db);
        }

        public async ValueTask DisposeAsync()
        {
            await Db.DisposeAsync();
            await _connection.DisposeAsync();
        }
    }
}
