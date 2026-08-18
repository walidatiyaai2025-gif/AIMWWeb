using System.Net;
using System.Security.Claims;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Components.Forms;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class MediaBatchUploadTests
{
    [Fact]
    public async Task UploadAsync_rejects_ContentView_only_before_database_tracker_or_remote_work()
    {
        var api = new FakeApiClient();
        var service = new WordPressMediaBatchUploadService(
            api,
            null!,
            null!,
            CurrentUser(ApplicationPermissionCatalog.ContentView),
            null!,
            NullLogger<WordPressMediaBatchUploadService>.Instance);

        var action = () => service.UploadAsync(
            Guid.NewGuid(),
            [new MediaBatchUploadItem(File("one.png", "image/png"), "One", "Alt", "Caption")]);

        await action.Should().ThrowAsync<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.ContentEdit}*");
        api.SendContentCalls.Should().Be(0);
    }

    [Fact]
    public async Task UploadAsync_rejects_unowned_site_before_execution_job_or_remote_post()
    {
        await using var fixture = await BatchFixture.CreateAsync();
        var otherOwnerSite = new Site(
            "Other owner",
            new Uri("https://other-owner.example.test"),
            DateTime.UtcNow,
            Guid.NewGuid());
        fixture.Db.Sites.Add(otherOwnerSite);
        await fixture.Db.SaveChangesAsync();

        var service = fixture.CreateService();
        var action = () => service.UploadAsync(
            otherOwnerSite.Id,
            [new MediaBatchUploadItem(File("one.png", "image/png"), "One", "Alt", "Caption")]);

        await action.Should().ThrowAsync<UnauthorizedAccessException>()
            .WithMessage("*does not belong to the current user*");
        fixture.Api.SendContentCalls.Should().Be(0);
        fixture.ExecutionCenter.GetJobs(fixture.OwnerUserId).Should().BeEmpty();
    }

    [Fact]
    public async Task UploadAsync_valid_batch_uses_one_owned_job_and_preserves_per_file_metadata()
    {
        await using var fixture = await BatchFixture.CreateAsync();
        fixture.Api.Responses.Enqueue(SuccessResponse(101, "https://example.test/uploads/first.png"));
        fixture.Api.Responses.Enqueue(SuccessResponse(102, "https://example.test/uploads/second.jpg"));
        var service = fixture.CreateService();
        var progress = new List<MediaBatchUploadProgress>();

        var result = await service.UploadAsync(
            fixture.Site.Id,
            [
                new MediaBatchUploadItem(File("first.png", "image/png"), "First title", "First alt", "First caption"),
                new MediaBatchUploadItem(File("second.jpg", "image/jpeg"), "Second title", "Second alt", "Second caption")
            ],
            update => { progress.Add(update); return Task.CompletedTask; });

        result.Succeeded.Should().Be(2);
        result.Failed.Should().Be(0);
        result.Rejected.Should().Be(0);
        result.ExecutionJobId.Should().NotBeNull();
        fixture.Api.SendContentCalls.Should().Be(2);
        fixture.Api.Uploads.Should().ContainInOrder(
            new CapturedUpload("first.png", "First title", "First alt", "First caption"),
            new CapturedUpload("second.jpg", "Second title", "Second alt", "Second caption"));
        progress.Select(x => x.Completed).Should().Equal(1, 2);

        var jobs = fixture.ExecutionCenter.GetJobs(fixture.OwnerUserId);
        jobs.Should().ContainSingle();
        jobs[0].Id.Should().Be(result.ExecutionJobId);
        jobs[0].OwnerUserId.Should().Be(fixture.OwnerUserId);
        jobs[0].SiteId.Should().Be(fixture.Site.Id);
        jobs[0].Status.Should().Be("Completed");

        var notification = fixture.Notifications.Get(fixture.OwnerUserId).Single();
        notification.SiteId.Should().Be(fixture.Site.Id);
        notification.ExecutionJobId.Should().Be(result.ExecutionJobId);
        notification.Severity.Should().Be(NotificationSeverity.Success);
    }

    [Fact]
    public async Task UploadAsync_mixed_batch_rejects_invalid_without_POST_and_preserves_partial_remote_success()
    {
        await using var fixture = await BatchFixture.CreateAsync();
        fixture.Api.Responses.Enqueue(SuccessResponse(201, "https://example.test/uploads/good.png"));
        fixture.Api.Responses.Enqueue(WordPressApiResponse<JsonDocument>.Failure(HttpStatusCode.BadGateway, "remote upload failed"));
        var service = fixture.CreateService();

        var result = await service.UploadAsync(
            fixture.Site.Id,
            [
                new MediaBatchUploadItem(File("good.png", "image/png"), "Good", "Good alt", "Good caption"),
                new MediaBatchUploadItem(File("blocked.exe", "application/octet-stream"), "Blocked", "", ""),
                new MediaBatchUploadItem(File("remote.jpg", "image/jpeg"), "Remote", "Remote alt", "Remote caption")
            ]);

        result.Succeeded.Should().Be(1);
        result.Failed.Should().Be(1);
        result.Rejected.Should().Be(1);
        result.Items.Should().HaveCount(3);
        result.Items[0].State.Should().Be(MediaBatchUploadState.Succeeded);
        result.Items[1].State.Should().Be(MediaBatchUploadState.Rejected);
        result.Items[2].State.Should().Be(MediaBatchUploadState.Failed);
        result.Items[2].Message.Should().Contain("remote upload failed");
        fixture.Api.SendContentCalls.Should().Be(2, "validation-rejected files must never reach WordPress");

        var jobs = fixture.ExecutionCenter.GetJobs(fixture.OwnerUserId);
        jobs.Should().ContainSingle();
        jobs[0].Status.Should().Be("Completed", "partial success must be preserved instead of rolling the whole batch back");
        fixture.Notifications.Get(fixture.OwnerUserId).Single().Severity.Should().Be(NotificationSeverity.Warning);
    }

    private static IBrowserFile File(string name, string contentType, string content = "test-content") =>
        new FakeBrowserFile(name, contentType, System.Text.Encoding.UTF8.GetBytes(content));

    private static WordPressApiResponse<JsonDocument> SuccessResponse(int id, string sourceUrl) =>
        WordPressApiResponse<JsonDocument>.Success(
            HttpStatusCode.Created,
            JsonDocument.Parse($$"""{"id":{{id}},"source_url":"{{sourceUrl}}"}"""),
            new Dictionary<string, string>());

    private static CurrentUserContext CurrentUser(string permission, Guid? userId = null)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, (userId ?? Guid.NewGuid()).ToString()),
            new(ApplicationPermissionCatalog.ClaimType, permission)
        };
        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"))
        };
        return new CurrentUserContext(new TestAccessor(context));
    }

    private sealed class BatchFixture : IAsyncDisposable
    {
        private readonly string _root;
        private readonly SqliteConnection _databaseConnection;

        private BatchFixture(
            string root,
            Guid ownerUserId,
            AppDbContext db,
            SqliteConnection databaseConnection,
            Site site,
            FakeApiClient api,
            ExecutionCenterService executionCenter,
            ExecutionOperationTracker tracker,
            NotificationInboxService notifications)
        {
            _root = root;
            OwnerUserId = ownerUserId;
            Db = db;
            _databaseConnection = databaseConnection;
            Site = site;
            Api = api;
            ExecutionCenter = executionCenter;
            Tracker = tracker;
            Notifications = notifications;
        }

        public Guid OwnerUserId { get; }
        public AppDbContext Db { get; }
        public Site Site { get; }
        public FakeApiClient Api { get; }
        public ExecutionCenterService ExecutionCenter { get; }
        public ExecutionOperationTracker Tracker { get; }
        public NotificationInboxService Notifications { get; }

        public static async Task<BatchFixture> CreateAsync()
        {
            var root = Path.Combine(Path.GetTempPath(), "aiwm-media-batch-" + Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(root);
            var ownerUserId = Guid.NewGuid();
            var dbConnection = new SqliteConnection("Data Source=:memory:");
            await dbConnection.OpenAsync();
            var dbOptions = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(dbConnection).Options;
            var db = new AppDbContext(dbOptions);
            await db.Database.EnsureCreatedAsync();
            var site = new Site("Owned site", new Uri("https://owned.example.test"), DateTime.UtcNow, ownerUserId);
            db.Sites.Add(site);
            await db.SaveChangesAsync();

            var executionPath = Path.Combine(root, "execution.db");
            var executionCenter = new ExecutionCenterService(executionPath, enableBackgroundWorker: false, enableSeedData: false);
            var tracker = new ExecutionOperationTracker(executionCenter, executionPath);
            var notifications = NotificationInboxService.ForDatabase(Path.Combine(root, "notifications.db"));
            return new BatchFixture(
                root,
                ownerUserId,
                db,
                dbConnection,
                site,
                new FakeApiClient(),
                executionCenter,
                tracker,
                notifications);
        }

        public WordPressMediaBatchUploadService CreateService() =>
            new(
                Api,
                Tracker,
                Notifications,
                CurrentUser(ApplicationPermissionCatalog.ContentEdit, OwnerUserId),
                Db,
                NullLogger<WordPressMediaBatchUploadService>.Instance);

        public async ValueTask DisposeAsync()
        {
            ExecutionCenter.Dispose();
            await Db.DisposeAsync();
            await _databaseConnection.DisposeAsync();
            try { Directory.Delete(_root, recursive: true); } catch { }
        }
    }

    private sealed class FakeApiClient : IWordPressApiClient
    {
        public Queue<WordPressApiResponse<JsonDocument>> Responses { get; } = new();
        public List<CapturedUpload> Uploads { get; } = [];
        public int SendContentCalls { get; private set; }

        public Task<WordPressApiResponse<JsonDocument>> GetAsync(Guid siteId, string relativePath, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<WordPressApiResponse<JsonDocument>> SendAsync(Guid siteId, HttpMethod method, string relativePath, object? payload = null, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public async Task<WordPressApiResponse<JsonDocument>> SendContentAsync(
            Guid siteId,
            HttpMethod method,
            string relativePath,
            HttpContent content,
            CancellationToken cancellationToken = default)
        {
            SendContentCalls++;
            relativePath.Should().Be("/wp-json/wp/v2/media");
            method.Should().Be(HttpMethod.Post);
            var multipart = content.Should().BeOfType<MultipartFormDataContent>().Subject;
            string fileName = string.Empty, title = string.Empty, alt = string.Empty, caption = string.Empty;
            foreach (var part in multipart)
            {
                var name = part.Headers.ContentDisposition?.Name?.Trim('"') ?? string.Empty;
                if (name == "file") fileName = part.Headers.ContentDisposition?.FileName?.Trim('"') ?? string.Empty;
                else if (name == "title") title = await part.ReadAsStringAsync(cancellationToken);
                else if (name == "alt_text") alt = await part.ReadAsStringAsync(cancellationToken);
                else if (name == "caption") caption = await part.ReadAsStringAsync(cancellationToken);
            }
            Uploads.Add(new CapturedUpload(fileName, title, alt, caption));
            return Responses.Dequeue();
        }
    }

    private sealed class FakeBrowserFile(string name, string contentType, byte[] bytes) : IBrowserFile
    {
        public string Name { get; } = name;
        public DateTimeOffset LastModified { get; } = DateTimeOffset.UtcNow;
        public long Size => bytes.LongLength;
        public string ContentType { get; } = contentType;

        public Stream OpenReadStream(long maxAllowedSize = 512000, CancellationToken cancellationToken = default)
        {
            if (Size > maxAllowedSize) throw new IOException("File exceeds allowed size.");
            return new MemoryStream(bytes, writable: false);
        }
    }

    private sealed class TestAccessor(HttpContext context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }

    private sealed record CapturedUpload(string FileName, string Title, string AltText, string Caption);
}