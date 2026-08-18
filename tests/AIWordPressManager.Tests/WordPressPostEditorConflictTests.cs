using System.Net;
using System.Security.Claims;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class WordPressPostEditorConflictTests
{
    [Fact]
    public async Task UpdateAsync_Blocks_Post_When_Remote_Modified_After_Editor_Load()
    {
        var siteId = Guid.NewGuid();
        var api = new FakeApiClient();
        api.GetResponses.Enqueue(ContentResponse(7, "2026-08-09T09:00:00Z"));
        api.GetResponses.Enqueue(ContentResponse(7, "2026-08-09T09:05:00Z"));
        var service = CreateService(api);

        var loaded = await service.GetAsync(siteId, "post", 7);
        loaded.IsSuccess.Should().BeTrue();

        var result = await service.UpdateAsync(siteId, Request(7));

        result.IsFailure.Should().BeTrue();
        result.Error.Code.Should().Be("Conflict");
        result.Error.Message.Should().Be(WordPressPostEditorWebService.ConflictMessage);
        api.SendCalls.Should().Be(0);
    }

    [Fact]
    public async Task UpdateAsync_Sends_Post_When_Remote_Version_Is_Unchanged()
    {
        var siteId = Guid.NewGuid();
        var api = new FakeApiClient();
        api.GetResponses.Enqueue(ContentResponse(7, "2026-08-09T09:00:00Z"));
        api.GetResponses.Enqueue(ContentResponse(7, "2026-08-09T09:00:00Z"));
        api.SendResponses.Enqueue(ContentResponse(7, "2026-08-09T09:01:00Z"));
        var service = CreateService(api);

        await service.GetAsync(siteId, "post", 7);
        var result = await service.UpdateAsync(siteId, Request(7));

        result.IsSuccess.Should().BeTrue();
        api.SendCalls.Should().Be(1);
        result.Value.ModifiedGmt.Should().Be(DateTimeOffset.Parse("2026-08-09T09:01:00Z"));
    }

    [Fact]
    public async Task UpdateAsync_Rejects_Unsupported_Status_Before_Remote_Call()
    {
        var api = new FakeApiClient();
        var service = CreateService(api);
        var request = Request(7) with { Status = "invalid-status" };

        var result = await service.UpdateAsync(Guid.NewGuid(), request);

        result.IsFailure.Should().BeTrue();
        result.Error.Code.Should().Be("Validation");
        api.GetCalls.Should().Be(0);
        api.SendCalls.Should().Be(0);
    }

    [Fact]
    public void HasRemoteChanged_Normalizes_TimeZones()
    {
        var expected = DateTimeOffset.Parse("2026-08-09T09:00:00Z");
        var sameInstant = DateTimeOffset.Parse("2026-08-09T12:00:00+03:00");

        WordPressPostEditorWebService.HasRemoteChanged(expected, sameInstant).Should().BeFalse();
        WordPressPostEditorWebService.HasRemoteChanged(expected, expected.AddSeconds(1)).Should().BeTrue();
    }

    private static WordPressPostEditorWebService CreateService(FakeApiClient api)
    {
        var userId = Guid.NewGuid();
        var identity = new ClaimsIdentity(
            [
                new Claim(ClaimTypes.NameIdentifier, userId.ToString()),
                new Claim(ClaimTypes.Name, "content-editor-test"),
                new Claim(ApplicationPermissionCatalog.ClaimType, ApplicationPermissionCatalog.ContentEdit)
            ],
            "test");
        var accessor = new FixedHttpContextAccessor
        {
            HttpContext = new DefaultHttpContext { User = new ClaimsPrincipal(identity) }
        };

        return new WordPressPostEditorWebService(
            api,
            new AppNotificationService(),
            new CurrentUserContext(accessor));
    }

    private static WordPressContentUpdateRequest Request(int id) => new(
        "post", id, "Updated title", "updated-title", "draft", "<p>Body</p>", "Excerpt", null,
        0, Array.Empty<int>(), Array.Empty<int>(), string.Empty, "open", "open", "standard", false);

    private static WordPressApiResponse<JsonDocument> ContentResponse(int id, string modifiedGmt)
    {
        var json = JsonDocument.Parse($$"""
        {
          "id": {{id}},
          "title": { "raw": "Title" },
          "slug": "title",
          "status": "draft",
          "content": { "raw": "<p>Body</p>" },
          "excerpt": { "raw": "Excerpt" },
          "link": "https://example.com/title",
          "date_gmt": "2026-08-09T08:00:00Z",
          "modified_gmt": "{{modifiedGmt}}",
          "featured_media": 0,
          "categories": [],
          "tags": [],
          "template": "",
          "author": 1,
          "comment_status": "open",
          "ping_status": "open",
          "format": "standard",
          "sticky": false,
          "password": ""
        }
        """);
        return WordPressApiResponse<JsonDocument>.Success(HttpStatusCode.OK, json, new Dictionary<string, string>());
    }

    private sealed class FakeApiClient : IWordPressApiClient
    {
        public Queue<WordPressApiResponse<JsonDocument>> GetResponses { get; } = new();
        public Queue<WordPressApiResponse<JsonDocument>> SendResponses { get; } = new();
        public int GetCalls { get; private set; }
        public int SendCalls { get; private set; }

        public Task<WordPressApiResponse<JsonDocument>> GetAsync(Guid siteId, string relativePath, CancellationToken cancellationToken = default)
        {
            GetCalls++;
            return Task.FromResult(GetResponses.Dequeue());
        }

        public Task<WordPressApiResponse<JsonDocument>> SendAsync(Guid siteId, HttpMethod method, string relativePath, object? payload = null, CancellationToken cancellationToken = default)
        {
            SendCalls++;
            return Task.FromResult(SendResponses.Dequeue());
        }

        public Task<WordPressApiResponse<JsonDocument>> SendContentAsync(Guid siteId, HttpMethod method, string relativePath, HttpContent content, CancellationToken cancellationToken = default) =>
            throw new NotSupportedException();
    }

    private sealed class FixedHttpContextAccessor : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; }
    }
}
