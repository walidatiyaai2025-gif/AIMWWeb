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

// Required by services that create outbound HTTP requests, such as the broken-link scanner.
builder.Services.AddHttpClient();

builder.Services.AddInfrastructure();
builder.Services.AddPersistence();
builder.Services.AddScoped<IWordPressConnectionTester, WordPressConnectionTester>();
builder.Services.AddScoped<SiteWebService>();
builder.Services.AddScoped<WordPressSyncWebService>();
builder.Services.AddScoped<IWordPressPostEditorService, WordPressPostEditorWebService>();
builder.Services.AddScoped<WordPressMediaWebService>();
builder.Services.AddScoped<WordPressTaxonomyWebService>();
builder.Services.AddScoped<WordPressCommentsWebService>();
builder.Services.AddScoped<WordPressUsersWebService>();
builder.Services.AddScoped<SeoAnalysisWebService>();
builder.Services.AddScoped<AppLanguageService>();

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

app.MapRazorComponents<App>()
    .AddInteractiveServerRenderMode();

app.Run();
