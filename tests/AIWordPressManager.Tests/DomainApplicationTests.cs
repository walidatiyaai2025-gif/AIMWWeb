using System.Net;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Domain.Enums;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class DomainApplicationTests
{
    [Fact]
    public void Site_Constructor_Normalizes_Name_And_Url()
    {
        var now = new DateTime(2026, 8, 5, 9, 0, 0, DateTimeKind.Utc);

        var site = new Site("  Demo Site  ", new Uri("https://example.com/path/?x=1"), now);

        site.Name.Should().Be("Demo Site");
        site.SiteUrl.Should().Be("https://example.com");
        site.ConnectionStatus.Should().Be(SiteConnectionStatus.Unknown);
        site.UpdatedAtUtc.Should().Be(now);
    }

    [Fact]
    public void SiteConnectionStatus_NotTested_Remains_Compatible_With_Unknown()
    {
        ((int)SiteConnectionStatus.NotTested).Should().Be((int)SiteConnectionStatus.Unknown);
        SiteConnectionStatus.NotTested.Should().Be(SiteConnectionStatus.Unknown);
    }

    [Theory]
    [InlineData("ftp://example.com")]
    [InlineData("file:///c:/wordpress")]
    public void Site_Rejects_Non_Http_Urls(string value)
    {
        var action = () => new Site("Demo", new Uri(value), DateTime.UtcNow);

        action.Should().Throw<ArgumentException>();
    }

    [Fact]
    public void Site_Records_Discovery_And_Connection_Status()
    {
        var site = new Site("Demo", new Uri("https://example.com"), DateTime.UtcNow);
        var now = new DateTime(2026, 8, 5, 10, 0, 0, DateTimeKind.Utc);

        site.UpdateDiscovery("https://example.com/home/", "6.8.1", "en-US", now);
        site.RecordConnectionStatus(SiteConnectionStatus.Connected, now);

        site.HomeUrl.Should().Be("https://example.com/home");
        site.WordPressVersion.Should().Be("6.8.1");
        site.LanguageCode.Should().Be("en-US");
        site.ConnectionStatus.Should().Be(SiteConnectionStatus.Connected);
        site.LastConnectionTestAtUtc.Should().Be(now);
    }

    [Fact]
    public void SiteCredential_Trims_UserName_And_Updates_Protected_Value()
    {
        var now = new DateTime(2026, 8, 5, 11, 0, 0, DateTimeKind.Utc);
        var credential = new SiteCredential(Guid.NewGuid(), "  editor  ", "protected-v1", now);

        credential.UserName.Should().Be("editor");
        credential.ProtectedApplicationPassword.Should().Be("protected-v1");

        credential.SetProtectedApplicationPassword("protected-v2", now.AddMinutes(1));

        credential.ProtectedApplicationPassword.Should().Be("protected-v2");
        credential.UpdatedAtUtc.Should().Be(now.AddMinutes(1));
    }

    [Fact]
    public void SiteCredential_Rejects_Empty_Site_Id()
    {
        var action = () => new SiteCredential(Guid.Empty, "editor", "protected", DateTime.UtcNow);

        action.Should().Throw<ArgumentException>();
    }

    [Fact]
    public void WordPressApiResponse_Success_Preserves_Value_And_Headers()
    {
        using var document = JsonDocument.Parse("{\"id\":42}");
        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            ["X-WP-Total"] = "1"
        };

        var response = WordPressApiResponse<JsonDocument>.Success(HttpStatusCode.OK, document, headers);

        response.IsSuccess.Should().BeTrue();
        response.StatusCode.Should().Be(HttpStatusCode.OK);
        response.Value!.RootElement.GetProperty("id").GetInt32().Should().Be(42);
        response.Headers["x-wp-total"].Should().Be("1");
        response.ErrorMessage.Should().BeEmpty();
    }

    [Fact]
    public void WordPressApiResponse_Failure_Uses_Empty_Case_Insensitive_Headers_When_Not_Provided()
    {
        var response = WordPressApiResponse<JsonDocument>.Failure(HttpStatusCode.BadRequest, "invalid request");

        response.IsSuccess.Should().BeFalse();
        response.Value.Should().BeNull();
        response.ErrorMessage.Should().Be("invalid request");
        response.Headers.Should().BeEmpty();
    }
}
