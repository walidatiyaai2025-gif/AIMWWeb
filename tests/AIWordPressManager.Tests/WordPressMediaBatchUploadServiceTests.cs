using System.Net;
using System.Security.Claims;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Components.Forms;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class WordPressMediaBatchUploadServiceTests
{
    [Fact]
    public async Task Valid_batch_uploads_every_file_under_one_execution_job()
    {
        using var harness = CreateHarness(ApplicationPermissionCatalog.ContentEdit);
        harness.Api.EnqueueSuccess(101, "https://example.test/a.jpg");
        harness.Api.EnqueueSuccess(102, "https://example.test/b.png");

        var result = await harness.Service.UploadAsync(harness.SiteId,
        [
            Request("a.jpg", "image/jpeg"),
            Request("b.png", "image/png")
        ]);

        result.IsSuccess.Should().BeTrue();
        result.Succeeded.Should().Be(2);
        result.Failed.Should().Be(0);
        result.Rejected.Should().Be(0);
        harness.Api.UploadCalls.Should().Be(2);
        harness.Api.SiteIds.Should().OnlyContain(siteId => siteId == harness.SiteId);

        var job = harness.Execution.GetJobs(harness.OwnerUserId).Single(x => x.Id == result.ExecutionJobId);
        job.Status.Should().Be("Completed");
        job.TotalItems.Should().Be(2);
        job.ProcessedItems.Should().Be(2);
    }

    [Fact]
    public async Task Mixed_selection_rejects_invalid_file_before_remote_post()
    {
        using var harness = CreateHarness(ApplicationPermissionCatalog.ContentEdit);
        harness.Api.EnqueueSuccess(201, "https://example.test/ok.jpg");

        var result = await harness.Service.UploadAsync(harness.SiteId,
        [
            Request("ok.jpg", "image/jpeg"),
            Request("payload.exe", "application/octet-stream")
        ]);

        result.Succeeded.Should().Be(1);
        result.Failed.Should().Be(0);
        result.Rejected.Should().Be(1);
        result.IsPartialSuccess.Should().BeTrue();
        harness.Api.UploadCalls.Should().Be(1);
        result.Items.Should().ContainSingle(x => x.State == MediaBatchItemState.Rejected && x.FileName == "payload.exe");
    }

    [Fact]
    public async Task View_only_user_is_rejected_before_batch_validation_or_remote_work()
    {
        using var harness = CreateHarness(ApplicationPermissionCatalog.ContentView);

        var action = () => harness.Service.UploadAsync(harness.SiteId,
        [
            Request("payload.exe", "application/octet-stream")
        ]);

        await action.Should().ThrowAsync<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.ContentEdit}*");
        harness.Api.UploadCalls.Should().Be(0);
        harness.Execution.GetJobs(harness.OwnerUserId).Should().BeEmpty();
    }

    [Fact]
    public async Task Remote_failure_does_not_roll_back_or_skip_other_valid_uploads()
    {
        using var harness = CreateHarness(ApplicationPermissionCatalog.ContentEdit);
        harness.Api.EnqueueSuccess(301, "https://example.test/first.jpg");
        harness.Api.EnqueueFailure("remote upload failed");
        harness.Api.EnqueueSuccess(303, "https://example.test/third.jpg");

        var result = await harness.Service.UploadAsync(harness.SiteId,
        [
            Request("first.jpg", "image/jpeg"),
            Request("second.jpg", "image/jpeg"),
            Request("third.jpg", "image/jpeg")
        ]);

        result.Succeeded.Should().Be(2);
        result.Failed.Should().Be(1);
        result.Rejected.Should().Be(0);
        result.IsPartialSuccess.Should().BeTrue();
        harness.Api.UploadCalls.Should().Be(3);
        result.Items.Select(x => x.State).Should().Equal(
            MediaBatchItemState.Succeeded,
            MediaBatchItemState.Failed,
            MediaBatchItemState.Succeeded);
    }

    [Fact]
    public async Task Execution_and_notification_records_are_scoped_to_initiating_owner_and_site()
    {
        using var harness = CreateHarness(ApplicationPermissionCatalog.ContentEdit);
        harness.Api.EnqueueSuccess(401, "https://example.test/scoped.jpg");

        var result = await harness.Service.UploadAsync(harness.SiteId,
        [
            Request("scoped.jpg", "image/jpeg")
        ]);

        var job = harness.Execution.GetJobs(harness.OwnerUserId).Single(x => x.Id == result.ExecutionJobId);
        job.OwnerUserId.Should().Be(harness.OwnerUserId);
        job.SiteId.Should().Be(harness.SiteId);

        var notification = harness.Notifications.Get(harness.OwnerUserId)
            .Single(x => x.ExecutionJobId == result.ExecutionJobId);
        notification.OwnerUserId.Should().Be(harness.OwnerUserId);
        notification.SiteId.Should().Be(harness.SiteId);
        notification.Source.Should().Be("WordPressMediaBatch");
    }

    private static MediaBatchUploadRequest Request(string fileName, string contentType) =>
        new(new TestBrowserFile(fileName, contentType, Encoding.UTF8.GetBytes("test media")));

    private static Harness CreateHarness(params string[] permissions)
    {
        var ownerUserId = Guid.NewGuid();
        var siteId = Guid.NewGuid();
        var directory = Path.Combine(Path.GetTempPath(), $"aimw-media-batch-{Guid.NewGuid():N}");
        Directory.CreateDirectory(directory);
        var executionPath = Path.Combine(directory, "execution.db");
        var notificationPath = Path.Combine(directory, "notifications.db");

        var execution = new ExecutionCenterService(executionPath);
        var tracker = new ExecutionOperationTracker(execution, executionPath);
        var notifications = NotificationInboxService.ForDatabase(notificationPath);
        var api = new FakeWordPressApiClient();
        var service = new WordPressMediaBatchUploadService(
            api,
            tracker,
            notifications,
            CurrentUser(ownerUserId, permissions),
            NullLogger<WordPressMediaBatchUploadService>.Instance);

        return new Harness(directory, ownerUserId, siteId, execution, notifications, api, service);
    }

    private static CurrentUserContext CurrentUser(Guid ownerUserId, params string[] permissions)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, ownerUserId.ToString())
        };
        claims.AddRange(permissions.Select(permission =>
            new Claim(ApplicationPermissionCatalog.ClaimType, permission)));
        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"))
        };
        return new CurrentUserContext(new TestAccessor(context));
    }

    private sealed record Harness(
        string Directory,
        Guid OwnerUserId,
        Guid SiteId,
        ExecutionCenterService Execution,
        NotificationInboxService Notifications,
        FakeWordPressApiClient Api,
        WordPressMediaBatchUploadService Service) : IDisposable
    {
        public void Dispose()
        {
            Execution.Dispose();
            try { System.IO.Directory.Delete(Directory, recursive: true); }
            catch (IOException) { }
            catch (UnauthorizedAccessException) { }
        }
    }

    private sealed class TestAccessor(HttpContext context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }

    private sealed class TestBrowserFile(string name, string contentType, byte[] bytes) : IBrowserFile
    {
        public string Name { get; } = name;
        public DateTimeOffset LastModified { get; } = DateTimeOffset.UtcNow;
        public long Size => bytes.LongLength;
        public string ContentType { get; } = contentType;

        public Stream OpenReadStream(long maxAllowedSize = 512000, CancellationToken cancellationToken = default)
        {
            if (Size > maxAllowedSize) throw new IOException("File exceeds the configured stream limit.");
            cancellationToken.ThrowIfCancellationRequested();
            return new MemoryStream(bytes, writable: false);
        }
    }

    private sealed class FakeWordPressApiClient : IWordPressApiClient
    {
        private readonly Queue<Func<WordPressApiResponse<JsonDocument>>> _responses = new();
        public int UploadCalls { get; private set; }
        public List<Guid> SiteIds { get; } = [];

        public void EnqueueSuccess(int mediaId, string sourceUrl) =>
            _responses.Enqueue(() => WordPressApiResponse<JsonDocument>.Success(
                HttpStatusCode.Created,
                JsonDocument.Parse($"{{\"id\":{mediaId},\"source_url\":{JsonSerializer.Serialize(sourceUrl)}}}"),
                new Dictionary<string, string>()));

        public void EnqueueFailure(string message) =>
            _responses.Enqueue(() => WordPressApiResponse<JsonDocument>.Failure(HttpStatusCode.BadGateway, message));

        public Task<WordPressApiResponse<JsonDocument>> GetAsync(Guid siteId, string relativePath, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<WordPressApiResponse<JsonDocument>> SendAsync(Guid siteId, HttpMethod method, string relativePath, object? payload = null, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();

        public Task<WordPressApiResponse<JsonDocument>> SendContentAsync(Guid siteId, HttpMethod method, string relativePath, HttpContent content, CancellationToken cancellationToken = default)
        {
            UploadCalls++;
            SiteIds.Add(siteId);
            relativePath.Should().Be("/wp-json/wp/v2/media");
            method.Should().Be(HttpMethod.Post);
            if (_responses.Count == 0) throw new InvalidOperationException("No fake response was queued.");
            return Task.FromResult(_responses.Dequeue()());
        }
    }
}