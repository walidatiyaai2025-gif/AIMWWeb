using System.Security.Claims;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;

namespace AIWordPressManager.Tests;

public sealed class WordPressMediaPermissionBoundaryTests
{
    [Fact]
    public async Task Upload_rejects_view_only_before_file_validation_or_execution_work()
    {
        var service = CreateService(ApplicationPermissionCatalog.ContentView);

        var action = () => service.UploadAsync(Guid.NewGuid(), null!, null, null, null);

        await AssertContentEditRequired(action);
    }

    [Fact]
    public async Task Metadata_update_rejects_view_only_before_validation_or_execution_work()
    {
        var service = CreateService(ApplicationPermissionCatalog.ContentView);

        var action = () => service.UpdateMetadataAsync(
            Guid.NewGuid(),
            0,
            new MediaMetadataUpdate(null, null, null, null, null));

        await AssertContentEditRequired(action);
    }

    [Fact]
    public async Task Replace_rejects_view_only_before_media_or_file_validation()
    {
        var service = CreateService(ApplicationPermissionCatalog.ContentView);

        var action = () => service.ReplaceAsync(Guid.NewGuid(), 0, null!, null);

        await AssertContentEditRequired(action);
    }

    [Fact]
    public async Task Delete_rejects_view_only_before_validation_or_execution_work()
    {
        var service = CreateService(ApplicationPermissionCatalog.ContentView);

        var action = () => service.DeleteAsync(Guid.NewGuid(), 0);

        await AssertContentEditRequired(action);
    }

    [Fact]
    public void Bulk_delete_request_rejects_view_only_before_validation_or_approval_submission()
    {
        var service = CreateService(ApplicationPermissionCatalog.ContentView);

        var action = () => service.RequestBulkDelete(Guid.NewGuid(), "Site", Array.Empty<int>(), "User");

        action.Should().Throw<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.ContentEdit}*");
    }

    [Fact]
    public void Metadata_queue_rejects_view_only_before_validation_or_job_enqueue()
    {
        var service = CreateService(ApplicationPermissionCatalog.ContentView);

        var action = () => service.QueueMetadataUpdate(Guid.NewGuid(), "Site", Array.Empty<int>());

        action.Should().Throw<UnauthorizedAccessException>()
            .WithMessage($"*{ApplicationPermissionCatalog.ContentEdit}*");
    }

    [Fact]
    public async Task Content_edit_grant_passes_metadata_boundary_and_reaches_validation()
    {
        var service = CreateService(ApplicationPermissionCatalog.ContentEdit);

        var result = await service.UpdateMetadataAsync(
            Guid.NewGuid(),
            0,
            new MediaMetadataUpdate(null, null, null, null, null));

        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Be("Invalid media ID.");
    }

    [Fact]
    public async Task Content_edit_grant_passes_delete_boundary_and_reaches_validation()
    {
        var service = CreateService(ApplicationPermissionCatalog.ContentEdit);

        var result = await service.DeleteAsync(Guid.NewGuid(), 0);

        result.IsSuccess.Should().BeFalse();
        result.Message.Should().Be("Invalid media ID.");
    }

    [Fact]
    public void Content_edit_grant_passes_bulk_delete_boundary_and_reaches_validation()
    {
        var service = CreateService(ApplicationPermissionCatalog.ContentEdit);

        var action = () => service.RequestBulkDelete(Guid.NewGuid(), "Site", Array.Empty<int>(), "User");

        action.Should().Throw<InvalidOperationException>()
            .WithMessage("*valid media item*");
    }

    [Fact]
    public void Content_edit_grant_passes_metadata_queue_boundary_and_reaches_validation()
    {
        var service = CreateService(ApplicationPermissionCatalog.ContentEdit);

        var action = () => service.QueueMetadataUpdate(Guid.NewGuid(), "Site", Array.Empty<int>());

        action.Should().Throw<InvalidOperationException>()
            .WithMessage("*media item*");
    }

    private static WordPressMediaWebService CreateService(params string[] permissions) =>
        new(
            null!,
            null!,
            null!,
            null!,
            null!,
            null!,
            null!,
            CurrentUser(permissions),
            null!,
            null!);

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
