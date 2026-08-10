using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class AppNavigationCatalogTests
{
    [Fact]
    public void Catalog_has_unique_group_keys_and_routes()
    {
        AppNavigationCatalog.Groups.Select(group => group.Key)
            .Should().OnlyHaveUniqueItems();

        AppNavigationCatalog.AllItems.Select(item => item.Path)
            .Should().OnlyHaveUniqueItems();
    }

    [Fact]
    public void Catalog_has_complete_localized_metadata()
    {
        foreach (var group in AppNavigationCatalog.Groups)
        {
            group.EnglishName.Should().NotBeNullOrWhiteSpace();
            group.ArabicName.Should().NotBeNullOrWhiteSpace();
            group.EnglishDescription.Should().NotBeNullOrWhiteSpace();
            group.ArabicDescription.Should().NotBeNullOrWhiteSpace();
            group.Items.Should().NotBeEmpty();

            foreach (var item in group.Items)
            {
                item.GroupKey.Should().Be(group.Key);
                item.Icon.Should().NotBeNullOrWhiteSpace();
                item.EnglishName.Should().NotBeNullOrWhiteSpace();
                item.ArabicName.Should().NotBeNullOrWhiteSpace();
                item.EnglishDescription.Should().NotBeNullOrWhiteSpace();
                item.ArabicDescription.Should().NotBeNullOrWhiteSpace();
                item.Path.Should().StartWith("/");
            }
        }
    }

    [Theory]
    [InlineData("/automation-center")]
    [InlineData("/notifications")]
    [InlineData("/ai-center")]
    [InlineData("/content-planner")]
    [InlineData("/module/ai-usage")]
    [InlineData("/account/profile")]
    [InlineData("/account/email-settings")]
    [InlineData("/admin/application-users")]
    public void Catalog_contains_important_production_destinations(string path)
    {
        AppNavigationCatalog.AllItems.Should().Contain(item => item.Path == path);
    }

    [Fact]
    public void Route_matching_prefers_the_most_specific_destination()
    {
        AppNavigationCatalog.FindItem("/sites/connect", isAdministrator: false)!.EnglishName
            .Should().Be("Connect Site");

        AppNavigationCatalog.FindItem("/sites/11111111-1111-1111-1111-111111111111/explorer", isAdministrator: false)!.EnglishName
            .Should().Be("Sites");

        AppNavigationCatalog.FindItem("/settings/ai-providers", isAdministrator: true)!.EnglishName
            .Should().Be("AI Provider Settings");
    }

    [Fact]
    public void Root_route_does_not_claim_unknown_paths()
    {
        AppNavigationCatalog.FindItem("/not-a-real-workspace", isAdministrator: false)
            .Should().BeNull();
    }

    [Fact]
    public void Administrator_destinations_are_hidden_from_non_admin_users()
    {
        AppNavigationCatalog.VisibleItems(isAdministrator: false)
            .Should().NotContain(item => item.AdministratorOnly);

        AppNavigationCatalog.VisibleItems(isAdministrator: true)
            .Should().Contain(item => item.Path == "/admin/application-users")
            .And.Contain(item => item.Path == "/settings/ai-providers")
            .And.Contain(item => item.Path == "/settings/ai-prompts");
    }

    [Theory]
    [InlineData("jobs", "/module/execution")]
    [InlineData("automation", "/automation-center")]
    [InlineData("tokens", "/module/ai-usage")]
    [InlineData("اشعارات", "/notifications")]
    [InlineData("برومبت", "/module/prompts")]
    public void Search_metadata_finds_destinations_by_capability(string query, string expectedPath)
    {
        AppNavigationCatalog.VisibleItems(isAdministrator: false)
            .Where(item => item.MatchesSearch(query))
            .Should().Contain(item => item.Path == expectedPath);
    }
}
