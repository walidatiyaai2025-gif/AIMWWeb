using System.Net;
using System.Text;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Tests.Fixtures;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging.Abstractions;

namespace AIWordPressManager.Tests;

public sealed class WordPressApiClientTests : IAsyncLifetime
{
    private readonly SqliteConnection _connection = new("Data Source=:memory:");
    private AppDbContext _db = null!;
    private Guid _siteId;

    public async Task InitializeAsync()
    {
        await _connection.OpenAsync();
        var options = new DbContextOptionsBuilder<AppDbContext>()
            .UseSqlite(_connection)
            .Options;
        _db = new AppDbContext(options);
        await _db.Database.EnsureCreatedAsync();

        var now = DateTime.UtcNow;
        var site = new Site("Test WordPress", new Uri("https://wordpress.test"), now);
        var credential = new SiteCredential(site.Id, "api-user", "app-password", now);
        _db.Sites.Add(site);
        _db.SiteCredentials.Add(credential);
        await _db.SaveChangesAsync();
        _siteId = site.Id;
    }

    public async Task DisposeAsync()
    {
        await _db.DisposeAsync();
        await _connection.DisposeAsync();
    }

    [Fact]
    public async Task GetAsync_SendsBasicAuthenticationAndReturnsPagingHeaders()
    {
        var handler = new MockWordPressHttpHandler()
            .AddJson(
                HttpMethod.Get,
                "/wp-json/wp/v2/posts?page=2",
                HttpStatusCode.OK,
                new[] { new { id = 20, title = new { rendered = "Post" } } },
                new Dictionary<string, string>
                {
                    ["X-WP-Total"] = "21",
                    ["X-WP-TotalPages"] = "3"
                },
                "api-user",
                "app-password");
        var client = CreateClient(handler);

        using var result = (await client.GetAsync(_siteId, "/wp-json/wp/v2/posts?page=2")).Value;

        result.Should().NotBeNull();
        result!.RootElement.GetArrayLength().Should().Be(1);
        handler.Requests.Should().ContainSingle();
        handler.Requests[0].AuthorizationScheme.Should().Be("Basic");
        DecodeBasic(handler.Requests[0].AuthorizationParameter).Should().Be("api-user:app-password");

        var response = await client.GetAsync(_siteId, "/wp-json/wp/v2/posts?page=2");
        response.IsSuccess.Should().BeTrue();
        response.Headers["X-WP-Total"].Should().Be("21");
        response.Headers["X-WP-TotalPages"].Should().Be("3");
        response.Value?.Dispose();
    }

    [Fact]
    public async Task GetAsync_MapsUnauthorizedWithoutRetrying()
    {
        var handler = new MockWordPressHttpHandler()
            .AddJson(HttpMethod.Get, "/wp-json/wp/v2/users/me", HttpStatusCode.Unauthorized,
                new { code = "rest_not_logged_in" }, expectedBasicUserName: "different", expectedBasicPassword: "wrong");
        var client = CreateClient(handler);

        var response = await client.GetAsync(_siteId, "/wp-json/wp/v2/users/me");

        response.IsSuccess.Should().BeFalse();
        response.StatusCode.Should().Be(HttpStatusCode.Unauthorized);
        response.ErrorMessage.Should().Contain("WordPress");
        handler.Requests.Should().ContainSingle();
    }

    [Fact]
    public async Task GetAsync_RetriesTransientFailureThenSucceeds()
    {
        var handler = new MockWordPressHttpHandler()
            .AddJson(HttpMethod.Get, "/wp-json/wp/v2/posts", HttpStatusCode.InternalServerError, new { message = "temporary" })
            .AddJson(HttpMethod.Get, "/wp-json/wp/v2/posts", HttpStatusCode.OK, new[] { new { id = 1 } });
        var client = CreateClient(handler);

        var response = await client.GetAsync(_siteId, "/wp-json/wp/v2/posts");

        response.IsSuccess.Should().BeTrue();
        handler.Requests.Should().HaveCount(2);
        response.Value?.Dispose();
    }

    [Fact]
    public async Task SendAsync_DoesNotRetryUnsafePost()
    {
        var handler = new MockWordPressHttpHandler()
            .AddJson(HttpMethod.Post, "/wp-json/wp/v2/posts", HttpStatusCode.InternalServerError, new { message = "failed" })
            .AddJson(HttpMethod.Post, "/wp-json/wp/v2/posts", HttpStatusCode.Created, new { id = 99 });
        var client = CreateClient(handler);

        var response = await client.SendAsync(_siteId, HttpMethod.Post, "/wp-json/wp/v2/posts", new { title = "Draft" });

        response.IsSuccess.Should().BeFalse();
        handler.Requests.Should().ContainSingle();
        handler.Requests[0].Body.Should().Contain("Draft");
    }

    [Fact]
    public async Task GetAsync_ReturnsBadGatewayForInvalidJson()
    {
        var handler = new MockWordPressHttpHandler()
            .AddJson(HttpMethod.Get, "/wp-json/wp/v2/posts", HttpStatusCode.OK, "{invalid-json");
        var client = CreateClient(handler);

        var response = await client.GetAsync(_siteId, "/wp-json/wp/v2/posts");

        response.IsSuccess.Should().BeFalse();
        response.StatusCode.Should().Be(HttpStatusCode.BadGateway);
        response.ErrorMessage.Should().Contain("JSON");
    }

    private WordPressApiClient CreateClient(HttpMessageHandler handler) => new(
        _db,
        new FixedHttpClientFactory(handler),
        new PassthroughSecretProtectionService(),
        NullLogger<WordPressApiClient>.Instance);

    private static string DecodeBasic(string? encoded) =>
        string.IsNullOrWhiteSpace(encoded)
            ? string.Empty
            : Encoding.UTF8.GetString(Convert.FromBase64String(encoded));

    private sealed class FixedHttpClientFactory(HttpMessageHandler handler) : IHttpClientFactory
    {
        public HttpClient CreateClient(string name) => new(handler, disposeHandler: false);
    }

    private sealed class PassthroughSecretProtectionService : ISecretProtectionService
    {
        public Task<string> ProtectAsync(string plainText, CancellationToken cancellationToken = default) =>
            Task.FromResult(plainText);

        public Task<string> UnprotectAsync(string protectedValue, CancellationToken cancellationToken = default) =>
            Task.FromResult(protectedValue);
    }
}