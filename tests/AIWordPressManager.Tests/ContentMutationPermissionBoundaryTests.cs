using System.Security.Claims;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class ContentMutationPermissionBoundaryTests
{
    [Fact]
    public async Task Bulk_status_rejects_view_only_before_site_or_job_work()
    {
        var service = new BulkStatusExecutionService(null!, null!, null!, null!, CurrentUser(ApplicationPermissionCatalog.ContentView));

        var action = () => service.RunAsync(
            Guid.NewGuid(),
            new BulkStatusRequest("publish", [new BulkStatusTarget("post", 42)]));

        await AssertContentEditRequired(action);
    }

    [Fact]
    public async Task Bulk_trash_rejects_view_only_before_site_or_job_work()
    {
        var service = new BulkTrashExecutionService(null!, null!, null!, null!, CurrentUser(ApplicationPermissionCatalog.ContentView));

        var action = () => service.RunAsync(
            Guid.NewGuid(),
            new BulkTrashRequest([new BulkTrashTarget("post", 42)]));

        await AssertContentEditRequired(action);
    }

    [Fact]
    public async Task Taxonomy_mutation_rejects_view_only_before_validation_or_api_work()
    {
        var service = new WordPressTaxonomyWebService(null!, null!, null!, CurrentUser(ApplicationPermissionCatalog.ContentView));

        var action = () => service.CreateAsync(
            Guid.NewGuid(),
            "categories",
            new TaxonomyTermEditModel { Name = "Protected category" });

        await AssertContentEditRequired(action);
    }

    [Fact]
    public async Task Comment_mutation_rejects_view_only_before_validation_or_api_work()
    {
        var service = new WordPressCommentsWebService(null!, CurrentUser(ApplicationPermissionCatalog.ContentView));

        var action = () => service.ApproveAsync(Guid.NewGuid(), 42);

        await AssertContentEditRequired(action);
    }

    [Fact]
    public async Task WordPress_user_mutation_rejects_view_only_before_validation_tracking_or_api_work()
    {
        var service = new WordPressUsersWebService(null!, null!, null!, CurrentUser(ApplicationPermissionCatalog.ContentView));
        var model = new WordPressUserEditModel
        {
            Username = "protected-user",
            Email = "protected@example.test",
            Password = "password123"
        };

        var action = () => service.CreateAsync(Guid.NewGuid(), model);

        await AssertContentEditRequired(action);
    }

    [Fact]
    public async Task Content_edit_grant_passes_taxonomy_boundary_and_reaches_validation()
    {
        var service = new WordPressTaxonomyWebService(null!, null!, null!, CurrentUser(ApplicationPermissionCatalog.ContentEdit));

        var result = await service.CreateAsync(Guid.NewGuid(), "categories", new TaxonomyTermEditModel());

        result.Success.Should().BeFalse();
        result.Message.Should().NotBeNullOrWhiteSpace();
    }

    [Fact]
    public async Task Content_edit_grant_passes_comment_boundary_and_reaches_validation()
    {
        var service = new WordPressCommentsWebService(null!, CurrentUser(ApplicationPermissionCatalog.ContentEdit));

        var result = await service.ApproveAsync(Guid.NewGuid(), 0);

        result.Success.Should().BeFalse();
        result.Message.Should().NotBeNullOrWhiteSpace();
    }

    [Fact]
    public async Task Content_edit_grant_passes_WordPress_user_boundary_and_reaches_validation()
    {
        var service = new WordPressUsersWebService(null!, null!, null!, CurrentUser(ApplicationPermissionCatalog.ContentEdit));

        var result = await service.CreateAsync(Guid.NewGuid(), new WordPressUserEditModel());

        result.Success.Should().BeFalse();
        result.Message.Should().NotBeNullOrWhiteSpace();
    }

    private static async Task AssertContentEditRequired(Func<Task> action)
    {
        await action.Should().ThrowAsync<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.ContentEdit}*");
    }

    private static CurrentUserContext CurrentUser(params string[] permissions)
    {
        var claims = new List<Claim>
        {
            new(ClaimTypes.NameIdentifier, Guid.NewGuid().ToString())
        };
        claims.AddRange(permissions.Select(permission =>
            new Claim(ApplicationPermissionCatalog.ClaimType, permission)));

        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(claims, "Test"))
        };
        return new CurrentUserContext(new TestAccessor(context));
    }

    private sealed class TestAccessor(HttpContext context) : IHttpContextAccessor
    {
        public HttpContext? HttpContext { get; set; } = context;
    }
}
