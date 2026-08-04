using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Infrastructure;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Components;
using AIWordPressManager.Web.Services;
using AIWordPressManager.Web.Localization;

var builder = WebApplication.CreateBuilder(args);

builder.Services.AddRazorComponents()
    .AddInteractiveServerComponents();

builder.Services.AddHttpClient();
builder.Services.AddHealthChecks();

builder.Services.AddInfrastructure();
builder.Services.AddPersistence();
builder.Services.AddScoped<IWordPressConnectionTester, WordPressConnectionTester>();
builder.Services.AddScoped<IWordPressApiClient, WordPressApiClient>();
builder.Services.AddScoped<SiteWebService>();
builder.Services.AddScoped<DashboardLiveService>();
builder.Services.AddScoped<WordPressSyncWebService>();
builder.Services.AddScoped<IWordPressPostEditorService, WordPressPostEditorWebService>();
builder.Services.AddScoped<WordPressMediaWebService>();
builder.Services.AddScoped<WordPressTaxonomyWebService>();
builder.Services.AddScoped<WordPressCommentsWebService>();
builder.Services.AddScoped<WordPressUsersWebService>();
builder.Services.AddScoped<SeoAnalysisWebService>();
builder.Services.AddScoped<SeoAuditExecutionService>();
builder.Services.AddScoped<BulkTrashExecutionService>();
builder.Services.AddScoped<SystemHealthWebService>();
builder.Services.AddScoped<AppLanguageService>();
builder.Services.AddSingleton<BuildInformationService>();
builder.Services.AddSingleton<ExecutionCenterService>();
builder.Services.AddSingleton<ExecutionOperationTracker>();
builder.Services.AddSingleton<AutomationCenterService>();
builder.Services.AddSingleton<BulkContentOperationQueue>();
builder.Services.AddHostedService<AutomationSchedulerService>();
builder.Services.AddHostedService<BulkContentOperationWorker>();

var app = builder.Build();

if (!app.Environment.IsDevelopment())
{
    app.UseExceptionHandler("/Error", createScopeForErrors: true);
    app.UseHsts();
}

app.UseHttpsRedirection();
app.UseStaticFiles();
app.UseAntiforgery();

using (var scope = app.Services.CreateScope())
{
    var initializer = scope.ServiceProvider.GetRequiredService<IDatabaseInitializationService>();
    await initializer.InitializeAsync();
}

app.MapHealthChecks("/health/live");
app.MapGet("/health/details", async (SystemHealthWebService service, CancellationToken cancellationToken) =>
    Results.Ok(await service.CheckAsync(cancellationToken)));
app.MapGet("/api/build", (BuildInformationService service) => Results.Ok(service.Current));
app.MapGet("/api/dashboard", async (DashboardLiveService service, CancellationToken cancellationToken) =>
    Results.Ok(await service.GetAsync(cancellationToken)));
app.MapGet("/api/automations", (AutomationCenterService service) => Results.Ok(new
{
    jobs = service.GetJobs(),
    history = service.GetHistory(100)
}));
app.MapPost("/api/sites/{siteId:guid}/seo-audit/run", async (
    Guid siteId,
    SeoAuditExecutionService service,
    CancellationToken cancellationToken) =>
{
    try { return Results.Ok(await service.RunAsync(siteId, cancellationToken)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});
app.MapPost("/api/sites/{siteId:guid}/content/trash", async (
    Guid siteId,
    BulkTrashRequest request,
    BulkTrashExecutionService service,
    CancellationToken cancellationToken) =>
{
    try { return Results.Ok(await service.RunAsync(siteId, request, cancellationToken)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});

app.MapRazorComponents<App>()
    .AddInteractiveServerRenderMode();

app.Run();
