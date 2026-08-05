using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Application.Abstractions.AI;
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
builder.Services.AddScoped<BulkStatusExecutionService>();
builder.Services.AddScoped<SystemHealthWebService>();
builder.Services.AddScoped<AppNotificationService>();
builder.Services.AddScoped(_ =>
{
    var language = new AppLanguageService();
    language.SetCulture("en");
    return language;
});
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

app.MapGet("/api/ai/prompts", (string? culture, IAIPromptRegistry registry) =>
    Results.Ok(registry.GetAll(string.IsNullOrWhiteSpace(culture) ? "en" : culture)));
app.MapGet("/api/ai/usage", (int? take, Guid? siteId, string? userId, IAIUsageLog usageLog) =>
    Results.Ok(usageLog.GetRecent(take ?? 100, siteId, userId)));
app.MapPost("/api/ai/generate", async (
    AIGenerateApiRequest input,
    IAIOrchestrator orchestrator,
    IAIPromptRegistry registry,
    CancellationToken cancellationToken) =>
{
    if (string.IsNullOrWhiteSpace(input.Content))
        return Results.BadRequest(new { error = "Content is required." });

    var instruction = string.IsNullOrWhiteSpace(input.PromptKey)
        ? input.SystemPrompt
        : registry.Get(input.PromptKey, input.Culture ?? "en");

    var result = await orchestrator.ExecuteAsync(new AIRequest(
        input.Content,
        instruction,
        input.Model,
        input.Temperature ?? 0.2,
        input.MaxOutputTokens ?? 1500,
        input.SiteId,
        input.UserId,
        input.PromptKey), cancellationToken);

    return result.IsSuccess ? Results.Ok(result) : Results.BadRequest(result);
});

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
app.MapPost("/api/sites/{siteId:guid}/content/status", async (
    Guid siteId,
    BulkStatusRequest request,
    BulkStatusExecutionService service,
    CancellationToken cancellationToken) =>
{
    try { return Results.Ok(await service.RunAsync(siteId, request, cancellationToken)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});

app.MapRazorComponents<App>()
    .AddInteractiveServerRenderMode();

app.Run();

public sealed record AIGenerateApiRequest(
    string Content,
    string? PromptKey,
    string? Culture,
    string? SystemPrompt,
    string? Model,
    double? Temperature,
    int? MaxOutputTokens,
    Guid? SiteId,
    string? UserId);
