using System.Net;
using System.Security.Claims;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class WordPressPostEditorPermissionTests
{
    [Fact]
    public async Task View_only_principal_is_rejected_before_any_WordPress_request()
    {
        var api = new TrackingWordPressApiClient();
        var service = CreateService(api, ApplicationPermissionCatalog.ContentView);

        var action = async () => await service.UpdateAsync(Guid.NewGuid(), CreateRequest());

        await action.Should().ThrowAsync<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.ContentEdit}*");
        api.TotalCalls.Should().Be(0);
    }

    [Fact]
    public async Task Edit_principal_reaches_the_WordPress_mutation_boundary()
    {
        var api = new TrackingWordPressApiClient();
        var service = CreateService(api, ApplicationPermissionCatalog.ContentEdit);

        var result = await service.UpdateAsync(Guid.NewGuid(), CreateRequest());

        result.IsFailure.Should().BeTrue();
        api.GetCalls.Should().Be(0);
        api.SendCalls.Should().Be(1);
        api.LastMethod.Should().Be(HttpMethod.Post);
    }

    private static WordPressPostEditorWebService CreateService(
        TrackingWordPressApiClient api,
        params string[] permissions)
    {
        var userId = Guid.NewGuid();
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, userId.ToString()),
            new(ClaimTypes.Name, "content-permission-user")
        };
        claims.AddRange(permissions.Select(permission =>
            new Claim(ApplicationPermissionCatalog.ClaimType, permission)));

        var accessor = new FixedHttpContextAccessor
        {
            HttpContext = new DefaultHttpContext
            {
                User = new ClaimsPrincipal(new ClaimsIdentity(claims, "test"))
            }
        };

        return new WordPressPostEditorWebService(
            api,
            new AppNotificationService(),
            new CurrentUserContext(accessor));
    }

    private static WordPressContentUpdateRequest CreateRequest() => new(
        "post",
        42,
        "Updated title",
        "updated-title",
        "draft",
        "<p>Updated content</p>",
        "Updated excerpt",
        null,
        0,
        [],
        [],
        string.Empty,
        "open",
        "open",
        "standard",
        false,
        null,
        true);

    private sealed class TrackingWordPressApiClient : IWordPressApiClient
    {
        public int GetCalls { get; private set; }
        public int SendCalls { get; private set; }
        public int SendContentCalls { get; private set; }
        public int TotalCalls => GetCalls + SendCalls + SendContentCalls;
        public HttpMethod? LastMethod { get; private set; }

        public Task<WordPressApiResponse<JsonDocument>> GetAsync(
            Guid siteId,
            string relativePath,
            CancellationToken cancellationToken = default)
        {
            GetCalls++;
            return Task.FromResult(Failure());
        }

        public Task<WordPressApiResponse<JsonDocument>> SendAsync(
            Guid siteId,
            HttpMethod method,
            string relativePath,
            object? payload = null,
            CancellationToken cancellationToken = default)
        {
            SendCalls++;
            LastMethod = method;
            return Task.FromResult(Failure());
        }

        public Task<WordPressApiResponse<JsonDocument>> SendContentAsync(
            Guid siteId,
            HttpMethod method,
            string relativePath,
            HttpContent content,
            CancellationToken cancellationToken = default)
        {
            SendContentCalls++;
            LastMethod = method;
            return Task.FromResult(Failure());
        }

        private static WordPressApiResponse<JsonDocument> Failure() =>
            WordPressApiResponse<JsonDocument>.Failure(
                HttpStatusCode.InternalServerError,
                "Synthetic WordPress failure after authorization.");
    }

    private sealed class FixedHttpContextAccessor : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; }
    }
}
