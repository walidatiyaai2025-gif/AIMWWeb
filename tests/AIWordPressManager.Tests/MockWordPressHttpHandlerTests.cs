using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AIWordPressManager.Tests.Fixtures;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class MockWordPressHttpHandlerTests
{
    [Fact]
    public async Task Returns_Configured_Json_Response()
    {
        using var handler = new MockWordPressHttpHandler()
            .AddJson(HttpMethod.Get, "/wp-json/wp/v2/posts/10", HttpStatusCode.OK, new { id = 10, status = "publish" });
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://example.test") };

        using var response = await client.GetAsync("/wp-json/wp/v2/posts/10");
        using var json = JsonDocument.Parse(await response.Content.ReadAsStringAsync());

        response.StatusCode.Should().Be(HttpStatusCode.OK);
        json.RootElement.GetProperty("id").GetInt32().Should().Be(10);
        handler.Requests.Should().ContainSingle();
    }

    [Fact]
    public async Task Enforces_Application_Password_Authentication()
    {
        using var handler = new MockWordPressHttpHandler()
            .AddJson(
                HttpMethod.Get,
                "/wp-json/wp/v2/users/me",
                HttpStatusCode.OK,
                new { id = 1, name = "Editor" },
                expectedBasicUserName: "editor",
                expectedBasicPassword: "abcd efgh");
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://example.test") };

        using var unauthorized = await client.GetAsync("/wp-json/wp/v2/users/me");
        unauthorized.StatusCode.Should().Be(HttpStatusCode.Unauthorized);

        var token = Convert.ToBase64String(Encoding.UTF8.GetBytes("editor:abcd efgh"));
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Basic", token);
        using var authorized = await client.GetAsync("/wp-json/wp/v2/users/me");

        authorized.StatusCode.Should().Be(HttpStatusCode.OK);
    }

    [Fact]
    public async Task Returns_WordPress_Style_NotFound_For_Unknown_Route()
    {
        using var handler = new MockWordPressHttpHandler();
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://example.test") };

        using var response = await client.GetAsync("/wp-json/wp/v2/missing");
        var json = await response.Content.ReadAsStringAsync();

        response.StatusCode.Should().Be(HttpStatusCode.NotFound);
        json.Should().Contain("rest_no_route");
    }

    [Fact]
    public async Task Exposes_WordPress_Paging_Headers()
    {
        using var handler = new MockWordPressHttpHandler()
            .AddWordPressPage(
                "/wp-json/wp/v2/posts",
                page: 2,
                totalPages: 4,
                totalItems: 75,
                items: new[] { new { id = 26 }, new { id = 27 } });
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://example.test") };

        using var response = await client.GetAsync("/wp-json/wp/v2/posts?page=2");

        response.Headers.GetValues("X-WP-Total").Single().Should().Be("75");
        response.Headers.GetValues("X-WP-TotalPages").Single().Should().Be("4");
    }

    [Fact]
    public async Task Records_Method_Query_And_Request_Body()
    {
        using var handler = new MockWordPressHttpHandler()
            .AddJson(HttpMethod.Post, "/wp-json/wp/v2/posts?context=edit", HttpStatusCode.Created, new { id = 40 });
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://example.test") };
        using var content = new StringContent("{\"title\":\"New post\"}", Encoding.UTF8, "application/json");

        using var response = await client.PostAsync("/wp-json/wp/v2/posts?context=edit", content);

        response.StatusCode.Should().Be(HttpStatusCode.Created);
        handler.Requests.Single().Method.Should().Be(HttpMethod.Post);
        handler.Requests.Single().Uri.Query.Should().Be("?context=edit");
        handler.Requests.Single().Body.Should().Contain("New post");
    }
}
