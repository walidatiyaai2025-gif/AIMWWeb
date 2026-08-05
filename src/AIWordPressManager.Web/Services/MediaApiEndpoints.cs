namespace AIWordPressManager.Web.Services;

public static class MediaApiEndpoints
{
    public static WebApplication MapMediaApi(this WebApplication app)
    {
        app.MapPut("/api/sites/{siteId:guid}/media/{mediaId:int}/metadata", async (
            Guid siteId, int mediaId, MediaMetadataUpdate request, WordPressMediaWebService service, CancellationToken cancellationToken) =>
        {
            var result = await service.UpdateMetadataAsync(siteId, mediaId, request, cancellationToken);
            return result.IsSuccess ? Results.Ok(result) : Results.BadRequest(result);
        });

        app.MapPost("/api/sites/{siteId:guid}/media/{mediaId:int}/alt-text", async (
            Guid siteId, int mediaId, MediaAltTextRequest request, WordPressMediaWebService service, CancellationToken cancellationToken) =>
        {
            try
            {
                var altText = await service.SuggestAltTextAsync(siteId, mediaId, request.Context, request.Culture ?? "en", request.UserId, cancellationToken);
                return Results.Ok(new { mediaId, altText });
            }
            catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
        });

        app.MapPost("/api/sites/{siteId:guid}/media/bulk-delete/request", (
            Guid siteId, MediaBulkRequest request, WordPressMediaWebService service) =>
        {
            try { return Results.Ok(service.RequestBulkDelete(siteId, request.SiteName, request.MediaIds, request.RequestedBy ?? "System")); }
            catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
        });

        app.MapPost("/api/sites/{siteId:guid}/media/bulk-metadata/queue", (
            Guid siteId, MediaBulkRequest request, WordPressMediaWebService service) =>
        {
            try { return Results.Ok(service.QueueMetadataUpdate(siteId, request.SiteName, request.MediaIds)); }
            catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
        });

        return app;
    }
}
