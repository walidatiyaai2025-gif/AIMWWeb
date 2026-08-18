using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Abstractions.WordPress;
using AIWordPressManager.Application.Abstractions.AI;
using AIWordPressManager.Application.Abstractions.Email;
using AIWordPressManager.Infrastructure;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Components;
using AIWordPressManager.Web.Services;
using AIWordPressManager.Web.Localization;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Authorization;

var builder = WebApplication.CreateBuilder(args);

DatabaseSetupService.BootstrapLegacySqliteIfNeeded(builder.Environment.EnvironmentName);
var databaseSetupConfigPath = DatabaseSetupService.GetConfigurationPath(builder.Environment.EnvironmentName);
builder.Configuration.AddJsonFile(databaseSetupConfigPath, optional: true, reloadOnChange: true);

builder.Services.AddRazorComponents().AddInteractiveServerComponents();
builder.Services.AddHttpClient();
builder.Services.AddHttpContextAccessor();
builder.Services.AddHealthChecks();
builder.Services.AddAuthentication(CookieAuthenticationDefaults.AuthenticationScheme)
    .AddCookie(options =>
    {
        options.LoginPath = "/login";
        options.AccessDeniedPath = "/login";
        options.Cookie.Name = "AIWM.Auth";
        options.Cookie.HttpOnly = true;
        options.Cookie.SameSite = SameSiteMode.Lax;
        options.ExpireTimeSpan = TimeSpan.FromHours(8);
        options.SlidingExpiration = true;
    });
builder.Services.AddAuthorization(options =>
{
    options.FallbackPolicy = new AuthorizationPolicyBuilder().RequireAuthenticatedUser().Build();
    ApplicationPermissionCatalog.AddPolicies(options);
});
builder.Services.AddSingleton<IAuthorizationMiddlewareResultHandler, BlazorFrameworkAuthorizationResultHandler>();

builder.Services.AddInfrastructure();
builder.Services.AddPersistence();
builder.Services.AddScoped<DatabaseSetupService>();
builder.Services.AddScoped<CurrentUserContext>();
builder.Services.AddScoped<LocalAuthenticationService>();
builder.Services.AddScoped<AccountProfileService>();
builder.Services.AddScoped<EmailScheduleService>();
builder.Services.AddScoped<IWordPressConnectionTester, WordPressConnectionTester>();
builder.Services.AddScoped<IWordPressApiClient, WordPressApiClient>();
builder.Services.AddScoped<SiteWebService>();
builder.Services.AddScoped<DashboardLiveService>();
builder.Services.AddScoped<WordPressSyncWebService>();
builder.Services.AddScoped<GlobalPostsExplorerService>();
builder.Services.AddScoped<GlobalMediaExplorerService>();
builder.Services.AddScoped<GlobalTaxonomyExplorerService>();
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
builder.Services.AddScoped<AIUsageWebService>();
builder.Services.AddScoped<IEmailTemplateRenderer, EmailTemplateRenderer>();
builder.Services.AddScoped(_ => { var language = new AppLanguageService(); language.SetCulture("en"); return language; });
builder.Services.AddSingleton<BuildInformationService>();
builder.Services.AddSingleton<ExecutionCenterService>();
builder.Services.AddSingleton<NotificationInboxService>();
builder.Services.AddSingleton<ApprovalWorkflowService>(sp =>
    new ApprovalWorkflowService(
        sp.GetRequiredService<ExecutionCenterService>(),
        sp.GetRequiredService<NotificationInboxService>(),
        sp.GetRequiredService<IServiceScopeFactory>(),
        sp.GetRequiredService<IHttpContextAccessor>()));
builder.Services.AddSingleton<ExecutionOperationTracker>();
builder.Services.AddSingleton<AutomationCenterService>();
builder.Services.AddSingleton<BulkContentOperationQueue>();
builder.Services.AddSingleton<SiteOperationHistoryService>();
builder.Services.AddHostedService<AutomationSchedulerService>();
builder.Services.AddHostedService<BulkContentOperationWorker>();
builder.Services.AddHostedService<EmailOutboxWorker>();
builder.Services.AddHostedService<EmailScheduleWorker>();

var app = builder.Build();

if (!app.Environment.IsDevelopment())
{
    app.UseExceptionHandler("/Error", createScopeForErrors: true);
    app.UseHsts();
}

app.UseHttpsRedirection();
app.UseStaticFiles();

app.Use(async (context, next) =>
{
    var configuration = context.RequestServices.GetRequiredService<IConfiguration>();
    var setupComplete = configuration.GetValue<bool>("Database:SetupComplete");
    var path = context.Request.Path;

    if (!setupComplete &&
        !path.StartsWithSegments("/setup") &&
        !path.StartsWithSegments("/health/live") &&
        !path.StartsWithSegments("/_framework") &&
        !path.StartsWithSegments("/_blazor"))
    {
        context.Response.Redirect("/setup");
        return;
    }

    await next();
});

app.UseAuthentication();
app.Use(async (context, next) =>
{
    if (PublicEntryRouting.ShouldRedirectToLanding(
            context.Request.Path.Value,
            context.Request.Method,
            context.User.Identity?.IsAuthenticated == true))
    {
        context.Response.Redirect(PublicEntryRouting.LandingPath);
        return;
    }

    await next();
});
app.UseAuthorization();
app.UseAntiforgery();

if (builder.Configuration.GetValue<bool>("Database:SetupComplete"))
{
    try
    {
        using var scope = app.Services.CreateScope();
        await scope.ServiceProvider.GetRequiredService<IDatabaseInitializationService>().InitializeAsync();
        await scope.ServiceProvider.GetRequiredService<LocalAuthenticationService>().SeedAsync();
    }
    catch (Exception ex)
    {
        app.Logger.LogError(ex, "Configured database initialization failed during startup. Returning application to first-run setup mode.");
        DatabaseSetupRecovery.MarkIncomplete(
            builder.Configuration,
            builder.Environment.EnvironmentName,
            app.Logger);
    }
}

app.MapGet("/setup", (DatabaseSetupService setup) =>
{
    if (setup.IsComplete) return Results.Redirect(PublicEntryRouting.LandingPath);
    return Results.Content(setup.RenderPage(), "text/html; charset=utf-8");
}).AllowAnonymous();

app.MapPost("/setup", async (HttpContext context, DatabaseSetupService setup, CancellationToken cancellationToken) =>
{
    var form = await context.Request.ReadFormAsync(cancellationToken);
    int? port = null;
    if (int.TryParse(form["port"].ToString(), out var parsedPort)) port = parsedPort;

    var request = new DatabaseSetupRequest(
        form["provider"].ToString(),
        form["sqlitePath"].ToString(),
        form["host"].ToString(),
        port,
        form["databaseName"].ToString(),
        form["userName"].ToString(),
        form["password"].ToString(),
        form["integratedSecurity"] == "true",
        form["trustServerCertificate"] == "true",
        form["adminUserName"].ToString(),
        form["adminPassword"].ToString(),
        form["adminConfirmPassword"].ToString());

    try
    {
        await setup.ApplyAsync(request, cancellationToken);
        return Results.Redirect(PublicEntryRouting.LandingPath);
    }
    catch (Exception ex)
    {
        var message = app.Environment.IsDevelopment() ? ex.ToString() : ex.Message;
        return Results.Content(setup.RenderPage(message, request), "text/html; charset=utf-8", statusCode: StatusCodes.Status400BadRequest);
    }
}).AllowAnonymous().DisableAntiforgery();

app.MapGet("/login", (HttpContext context, string? returnUrl, string? error) =>
{
    var safeReturnUrl = string.IsNullOrWhiteSpace(returnUrl)
        ? string.Empty
        : LocalAuthenticationService.ResolveRedirectPath(returnUrl, "/");

    if (context.User.Identity?.IsAuthenticated == true)
        return Results.Redirect(string.IsNullOrWhiteSpace(safeReturnUrl) ? "/" : safeReturnUrl);

    const string htmlTemplate = """
<!doctype html><html lang="en" dir="ltr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI WordPress Manager - Login</title><style>
body{margin:0;font-family:Segoe UI,Arial;background:radial-gradient(circle at 50% -10%,#0f513f55,transparent 34%),#0b0f17;color:#f9fafb;display:grid;place-items:center;min-height:100vh}.card{width:min(410px,90vw);background:#111827ee;padding:32px;border:1px solid #243244;border-radius:20px;box-shadow:0 24px 70px #0009}.brand{font-size:24px;font-weight:750;margin-bottom:6px}.sub{color:#9ca3af;margin-bottom:22px}.error{padding:11px 12px;margin-bottom:16px;border:1px solid #ef444455;border-radius:10px;background:#7f1d1d33;color:#fecaca;font-size:13px}label{display:block;margin:14px 0 6px}input{width:100%;box-sizing:border-box;padding:12px;border-radius:9px;border:1px solid #374151;background:#0b0f17;color:#fff;outline:none}input:focus{border-color:#10b981;box-shadow:0 0 0 3px #10b98122}button{width:100%;margin-top:20px;padding:12px;border:0;border-radius:9px;background:#10b981;color:#062a1f;font-weight:800;cursor:pointer}.remember{display:flex;gap:8px;align-items:center}.remember input{width:auto}.back{display:block;margin-top:18px;text-align:center;color:#9ca3af;text-decoration:none;font-size:13px}.back:hover{color:#d1fae5}</style></head>
<body><form class="card" method="post" action="/login"><div class="brand">AI WordPress Manager</div><div class="sub">Sign in to continue to your workspace</div>__ERROR_BLOCK__<input type="hidden" name="returnUrl" value="__RETURN_URL__"><label for="login-user-name">Username</label><input id="login-user-name" name="userName" autocomplete="username" required autofocus><label for="login-password">Password</label><input id="login-password" type="password" name="password" autocomplete="current-password" required><label class="remember"><input type="checkbox" name="rememberMe" value="true"> Remember me</label><button type="submit">Sign in</button><a class="back" href="/welcome">← Back to product overview</a></form></body></html>
""";

    var encodedReturnUrl = System.Net.WebUtility.HtmlEncode(safeReturnUrl);
    var errorBlock = string.IsNullOrWhiteSpace(error)
        ? string.Empty
        : $"<div class=\"error\">{System.Net.WebUtility.HtmlEncode(error)}</div>";
    var html = htmlTemplate
        .Replace("__RETURN_URL__", encodedReturnUrl, StringComparison.Ordinal)
        .Replace("__ERROR_BLOCK__", errorBlock, StringComparison.Ordinal);

    return Results.Content(html, "text/html");
}).AllowAnonymous();

app.MapPost("/login", async (HttpContext context, LocalAuthenticationService authentication, CancellationToken cancellationToken) =>
{
    var form = await context.Request.ReadFormAsync(cancellationToken);
    var returnUrl = form["returnUrl"].ToString();
    var result = await authentication.SignInAsync(
        context,
        form["userName"].ToString(),
        form["password"].ToString(),
        form["rememberMe"] == "true",
        returnUrl,
        cancellationToken);

    if (result.IsSuccess) return Results.Redirect(result.RedirectPath);

    var message = Uri.EscapeDataString(result.Message);
    var safeReturnUrl = string.IsNullOrWhiteSpace(returnUrl)
        ? string.Empty
        : LocalAuthenticationService.ResolveRedirectPath(returnUrl, "/");
    var returnQuery = string.IsNullOrWhiteSpace(safeReturnUrl)
        ? string.Empty
        : $"&returnUrl={Uri.EscapeDataString(safeReturnUrl)}";
    return Results.Redirect($"/login?error={message}{returnQuery}");
}).AllowAnonymous().DisableAntiforgery();

app.MapPost("/logout", async (HttpContext context) =>
{
    await context.SignOutAsync(CookieAuthenticationDefaults.AuthenticationScheme);
    return Results.Redirect(PublicEntryRouting.LandingPath);
}).DisableAntiforgery();

app.Use(async (context, next) =>
{
    await next();
    if (context.User.Identity?.IsAuthenticated != true || context.Request.Method != HttpMethods.Get || context.Response.StatusCode >= 400) return;
    var path = context.Request.Path.Value ?? "/";
    if (path.StartsWith("/api", StringComparison.OrdinalIgnoreCase) || path.StartsWith("/health", StringComparison.OrdinalIgnoreCase) || path.StartsWith("/_", StringComparison.OrdinalIgnoreCase) || path == "/login" || path == "/setup") return;
    using var scope = context.RequestServices.CreateScope();
    await scope.ServiceProvider.GetRequiredService<LocalAuthenticationService>().SaveLastPageAsync(context.User, path + context.Request.QueryString);
});

app.MapHealthChecks("/health/live").AllowAnonymous();
app.MapGet("/health/details", async (SystemHealthWebService service, CancellationToken cancellationToken) => Results.Ok(await service.CheckAsync(cancellationToken)))
    .RequireAuthorization(ApplicationPermissionCatalog.OperationsView);
app.MapGet("/api/build", (BuildInformationService service) => Results.Ok(service.Current))
    .RequireAuthorization(ApplicationPermissionCatalog.OperationsView);
app.MapGet("/api/dashboard", async (DashboardLiveService service, CancellationToken cancellationToken) => Results.Ok(await service.GetAsync(cancellationToken)))
    .RequireAuthorization(ApplicationPermissionCatalog.OperationsView);
app.MapGet("/api/automations", (AutomationCenterService service) => Results.Ok(new { jobs = service.GetJobs(), history = service.GetHistory(100) }))
    .RequireAuthorization(ApplicationPermissionCatalog.OperationsView);

app.MapGet("/api/ai/prompts", (string? culture, IAIPromptRegistry registry) => Results.Ok(registry.GetAll(string.IsNullOrWhiteSpace(culture) ? "en" : culture)))
    .RequireAuthorization(ApplicationPermissionCatalog.ContentView);
app.MapGet("/api/ai/usage", async (int? take, Guid? siteId, AIUsageWebService service, CancellationToken cancellationToken) =>
{
    try { return Results.Ok(await service.GetRecentAsync(take ?? 100, siteId, cancellationToken)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
}).RequireAuthorization(ApplicationPermissionCatalog.ContentView);
app.MapGet("/api/ai/usage/summary", async (Guid? siteId, AIUsageWebService service, CancellationToken cancellationToken) =>
{
    try { return Results.Ok(await service.GetAsync(siteId, 5_000, cancellationToken)); }
    catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); }
}).RequireAuthorization(ApplicationPermissionCatalog.ContentView);
app.MapPost("/api/ai/generate", async (AIGenerateApiRequest input, IAIOrchestrator orchestrator, IAIPromptRegistry registry, CurrentUserContext currentUser, SiteWebService siteService, CancellationToken cancellationToken) =>
{
    if (string.IsNullOrWhiteSpace(input.Content)) return Results.BadRequest(new { error = "Content is required." });

    if (input.SiteId.HasValue)
    {
        var ownedSites = await siteService.GetSitesAsync(cancellationToken);
        if (ownedSites.All(x => x.Id != input.SiteId.Value))
            return Results.BadRequest(new { error = "Selected site is unavailable." });
    }

    var instruction = input.SystemPrompt;
    if (!string.IsNullOrWhiteSpace(input.PromptKey))
    {
        if (!registry.TryGet(input.PromptKey, input.Culture ?? "en", out var resolvedPrompt))
            return Results.BadRequest(new { error = "Prompt template was not found or is disabled." });
        instruction = resolvedPrompt;
    }

    var result = await orchestrator.ExecuteAsync(new AIRequest(
        input.Content,
        instruction,
        input.Model,
        input.Temperature ?? 0.2,
        input.MaxOutputTokens ?? 1500,
        input.SiteId,
        currentUser.UserId.ToString("D"),
        input.PromptKey), cancellationToken);
    return result.IsSuccess ? Results.Ok(result) : Results.BadRequest(result);
}).RequireAuthorization(ApplicationPermissionCatalog.ContentEdit);

app.MapGet("/api/approvals", (string? status, int? take, ApprovalWorkflowService service) =>
{
    ApprovalStatus? parsed = null;
    if (!string.IsNullOrWhiteSpace(status)) { if (!Enum.TryParse<ApprovalStatus>(status, true, out var value)) return Results.BadRequest(new { error = "Invalid approval status." }); parsed = value; }
    return Results.Ok(service.GetItems(parsed, take ?? 200));
}).RequireAuthorization(ApplicationPermissionCatalog.ApprovalsView);
app.MapGet("/api/approvals/{id:guid}", (Guid id, ApprovalWorkflowService service) => service.GetById(id) is { } item ? Results.Ok(item) : Results.NotFound())
    .RequireAuthorization(ApplicationPermissionCatalog.ApprovalsView);
app.MapGet("/api/approvals/{id:guid}/audit", (Guid id, int? take, ApprovalWorkflowService service) => Results.Ok(service.GetAudit(id, take ?? 200)))
    .RequireAuthorization(ApplicationPermissionCatalog.ApprovalsView);
app.MapPost("/api/approvals", (ApprovalSubmission request, ApprovalWorkflowService service) => { try { return Results.Ok(service.Submit(request)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.ApprovalsDecide);
app.MapPost("/api/approvals/{id:guid}/approve", (Guid id, ApprovalDecision request, ApprovalWorkflowService service) => { try { return Results.Ok(service.Approve(id, request.Reviewer, request.Notes, request.ExecuteImmediately)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.ApprovalsDecide);
app.MapPost("/api/approvals/{id:guid}/reject", (Guid id, ApprovalDecision request, ApprovalWorkflowService service) => { try { return Results.Ok(service.Reject(id, request.Reviewer, request.Notes)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.ApprovalsDecide);
app.MapPut("/api/approvals/{id:guid}/proposal", (Guid id, ApprovalEditRequest request, ApprovalWorkflowService service) => { try { return Results.Ok(service.UpdateProposal(id, request.After, request.Actor, request.Notes)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.ApprovalsDecide);

app.MapGet("/api/planner", (Guid? siteId, string? status, DateTime? fromUtc, DateTime? toUtc, ContentPlannerService service) =>
{
    PlannerItemStatus? parsed = null;
    if (!string.IsNullOrWhiteSpace(status)) { if (!Enum.TryParse<PlannerItemStatus>(status, true, out var value)) return Results.BadRequest(new { error = "Invalid planner status." }); parsed = value; }
    return Results.Ok(service.GetItems(siteId, parsed, fromUtc, toUtc));
}).RequireAuthorization(ApplicationPermissionCatalog.ContentView);
app.MapPost("/api/planner", (CreatePlannerItem request, ContentPlannerService service) => { try { return Results.Ok(service.Create(request)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.ContentEdit);
app.MapPut("/api/planner/{id:guid}", (Guid id, UpdatePlannerItem request, ContentPlannerService service) => { try { return Results.Ok(service.Update(id, request)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.ContentEdit);
app.MapPost("/api/planner/{id:guid}/generate-brief", async (Guid id, PlannerAIRequest request, ContentPlannerService service, CurrentUserContext currentUser, CancellationToken cancellationToken) => { try { return Results.Ok(await service.GenerateBriefAsync(id, request.Culture ?? "en", currentUser.UserId.ToString("D"), cancellationToken)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.ContentEdit);
app.MapPost("/api/planner/{id:guid}/generate-draft", async (Guid id, PlannerAIRequest request, ContentPlannerService service, CurrentUserContext currentUser, CancellationToken cancellationToken) => { try { return Results.Ok(await service.GenerateDraftAsync(id, request.Culture ?? "en", currentUser.UserId.ToString("D"), cancellationToken)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.ContentEdit);
app.MapPost("/api/planner/{id:guid}/queue", (Guid id, ContentPlannerService service) => { try { return Results.Ok(service.QueueForExecution(id)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.OperationsExecute);

app.MapGet("/api/notifications", (string? userId, bool? unreadOnly, int? take, NotificationInboxService service) => Results.Ok(service.Get(userId, unreadOnly ?? false, take ?? 100)))
    .RequireAuthorization(ApplicationPermissionCatalog.OperationsView);
app.MapPost("/api/notifications/{id:guid}/read", (Guid id, NotificationInboxService service) => { service.MarkRead(id); return Results.NoContent(); })
    .RequireAuthorization(ApplicationPermissionCatalog.OperationsView);
app.MapMediaApi();
app.MapPost("/api/sites/{siteId:guid}/seo-audit/run", async (Guid siteId, SeoAuditExecutionService service, CancellationToken cancellationToken) => { try { return Results.Ok(await service.RunAsync(siteId, cancellationToken)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.OperationsExecute);
app.MapPost("/api/sites/{siteId:guid}/content/trash", async (Guid siteId, BulkTrashRequest request, BulkTrashExecutionService service, CancellationToken cancellationToken) => { try { return Results.Ok(await service.RunAsync(siteId, request, cancellationToken)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.OperationsExecute);
app.MapPost("/api/sites/{siteId:guid}/content/status", async (Guid siteId, BulkStatusRequest request, BulkStatusExecutionService service, CancellationToken cancellationToken) => { try { return Results.Ok(await service.RunAsync(siteId, request, cancellationToken)); } catch (InvalidOperationException ex) { return Results.BadRequest(new { error = ex.Message }); } })
    .RequireAuthorization(ApplicationPermissionCatalog.OperationsExecute);

app.MapRazorComponents<App>()
    .AddInteractiveServerRenderMode()
    .RequireAuthorization();
app.Run();

public sealed record AIGenerateApiRequest(string Content, string? PromptKey, string? Culture, string? SystemPrompt, string? Model, double? Temperature, int? MaxOutputTokens, Guid? SiteId, string? UserId);
public sealed record PlannerAIRequest(string? Culture, string? UserId);