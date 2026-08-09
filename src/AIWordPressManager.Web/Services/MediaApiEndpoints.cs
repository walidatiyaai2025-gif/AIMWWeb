namespace AIWordPressManager.Web.Services;

public static class MediaApiEndpoints
{
    public static WebApplication MapMediaApi(this WebApplication app)
    {
        app.MapPut("/api/sites/{siteId:guid}/media/{mediaId:int}/metadata", async (
            Guid siteId,
            int mediaId,
            MediaMetadataUpdate request,
            SiteWebService sites,
            WordPressMediaWebService service,
            CancellationToken cancellationToken) =>
        {
            try
            {
                await sites.EnsureOwnershipAsync(siteId, cancellationToken);
                var result = await service.UpdateMetadataAsync(siteId, mediaId, request, cancellationToken);
                return result.IsSuccess ? Results.Ok(result) : Results.BadRequest(result);
            }
            catch (UnauthorizedAccessException ex) { return Forbidden(ex); }
        });

        app.MapPost("/api/sites/{siteId:guid}/media/{mediaId:int}/alt-text", async (
            Guid siteId,
            int mediaId,
            MediaAltTextRequest request,
            SiteWebService sites,
            WordPressMediaWebService service,
            CancellationToken cancellationToken) =>
        {
            try
            {
                await sites.EnsureOwnershipAsync(siteId, cancellationToken);
                var altText = await service.SuggestAltTextAsync(siteId, mediaId, request.Context, request.Culture ?? "en", request.UserId, cancellationToken);
                return Results.Ok(new { mediaId, altText });
            }
            catch (UnauthorizedAccessException ex) { return Forbidden(ex); }
            catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
        });

        app.MapPost("/api/sites/{siteId:guid}/media/bulk-delete/request", async (
            Guid siteId,
            MediaBulkRequest request,
            SiteWebService sites,
            WordPressMediaWebService service,
            CancellationToken cancellationToken) =>
        {
            try
            {
                await sites.EnsureOwnershipAsync(siteId, cancellationToken);
                return Results.Ok(service.RequestBulkDelete(siteId, request.SiteName, request.MediaIds, request.RequestedBy ?? "System"));
            }
            catch (UnauthorizedAccessException ex) { return Forbidden(ex); }
            catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
        });

        app.MapPost("/api/sites/{siteId:guid}/media/bulk-metadata/queue", async (
            Guid siteId,
            MediaBulkRequest request,
            SiteWebService sites,
            WordPressMediaWebService service,
            CancellationToken cancellationToken) =>
        {
            try
            {
                await sites.EnsureOwnershipAsync(siteId, cancellationToken);
                return Results.Ok(service.QueueMetadataUpdate(siteId, request.SiteName, request.MediaIds));
            }
            catch (UnauthorizedAccessException ex) { return Forbidden(ex); }
            catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
        });

        return app;
    }

    private static IResult Forbidden(UnauthorizedAccessException exception) =>
        Results.Json(new { error = exception.Message }, statusCode: StatusCodes.Status403Forbidden);
}
