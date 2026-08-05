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
builder.Services.AddScoped<ContentPlannerService>();
builder.Services.AddScoped(_ =>
{
    var language = new AppLanguageService();
    language.SetCulture("en");
    return language;
});
builder.Services.AddSingleton<BuildInformationService>();
builder.Services.AddSingleton<ExecutionCenterService>();
builder.Services.AddSingleton<ApprovalWorkflowService>();
builder.Services.AddSingleton<NotificationInboxService>();
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

app.MapGet("/api/approvals", (string? status, int? take, ApprovalWorkflowService service) =>
{
    ApprovalStatus? parsed = null;
    if (!string.IsNullOrWhiteSpace(status))
    {
        if (!Enum.TryParse<ApprovalStatus>(status, true, out var value))
            return Results.BadRequest(new { error = "Invalid approval status." });
        parsed = value;
    }
    return Results.Ok(service.GetItems(parsed, take ?? 200));
});
app.MapGet("/api/approvals/{id:guid}", (Guid id, ApprovalWorkflowService service) =>
{
    var item = service.GetById(id);
    return item is null ? Results.NotFound() : Results.Ok(item);
});
app.MapGet("/api/approvals/{id:guid}/audit", (Guid id, int? take, ApprovalWorkflowService service) =>
    Results.Ok(service.GetAudit(id, take ?? 200)));
app.MapPost("/api/approvals", (ApprovalSubmission request, ApprovalWorkflowService service) =>
{
    try { return Results.Ok(service.Submit(request)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});
app.MapPost("/api/approvals/{id:guid}/approve", (Guid id, ApprovalDecision request, ApprovalWorkflowService service) =>
{
    try { return Results.Ok(service.Approve(id, request.Reviewer, request.Notes, request.ExecuteImmediately)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});
app.MapPost("/api/approvals/{id:guid}/reject", (Guid id, ApprovalDecision request, ApprovalWorkflowService service) =>
{
    try { return Results.Ok(service.Reject(id, request.Reviewer, request.Notes)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});
app.MapPut("/api/approvals/{id:guid}/proposal", (Guid id, ApprovalEditRequest request, ApprovalWorkflowService service) =>
{
    try { return Results.Ok(service.UpdateProposal(id, request.After, request.Actor, request.Notes)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});

app.MapGet("/api/planner", (Guid? siteId, string? status, DateTime? fromUtc, DateTime? toUtc, ContentPlannerService service) =>
{
    PlannerItemStatus? parsed = null;
    if (!string.IsNullOrWhiteSpace(status))
    {
        if (!Enum.TryParse<PlannerItemStatus>(status, true, out var value))
            return Results.BadRequest(new { error = "Invalid planner status." });
        parsed = value;
    }
    return Results.Ok(service.GetItems(siteId, parsed, fromUtc, toUtc));
});
app.MapPost("/api/planner", (CreatePlannerItem request, ContentPlannerService service) =>
{
    try { return Results.Ok(service.Create(request)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});
app.MapPut("/api/planner/{id:guid}", (Guid id, UpdatePlannerItem request, ContentPlannerService service) =>
{
    try { return Results.Ok(service.Update(id, request)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});
app.MapPost("/api/planner/{id:guid}/generate-brief", async (Guid id, PlannerAIRequest request, ContentPlannerService service, CancellationToken cancellationToken) =>
{
    try { return Results.Ok(await service.GenerateBriefAsync(id, request.Culture ?? "en", request.UserId, cancellationToken)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});
app.MapPost("/api/planner/{id:guid}/generate-draft", async (Guid id, PlannerAIRequest request, ContentPlannerService service, CancellationToken cancellationToken) =>
{
    try { return Results.Ok(await service.GenerateDraftAsync(id, request.Culture ?? "en", request.UserId, cancellationToken)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});
app.MapPost("/api/planner/{id:guid}/queue", (Guid id, ContentPlannerService service) =>
{
    try { return Results.Ok(service.QueueForExecution(id)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
});

app.MapGet("/api/notifications", (string? userId, bool? unreadOnly, int? take, NotificationInboxService service) =>
    Results.Ok(service.Get(userId, unreadOnly ?? false, take ?? 100)));
app.MapPost("/api/notifications/{id:guid}/read", (Guid id, NotificationInboxService service) =>
{
    service.MarkRead(id);
    return Results.NoContent();
});

app.MapMediaApi();

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

public sealed record PlannerAIRequest(string? Culture, string? UserId);
