using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Identity;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;

namespace AIWordPressManager.Tests;

public sealed class LocalAuthenticationPermissionClaimsTests
{
    [Theory]
    [InlineData("Administrator", ApplicationPermissionCatalog.UsersManage, true)]
    [InlineData("Administrator", ApplicationPermissionCatalog.SettingsManage, true)]
    [InlineData("User", ApplicationPermissionCatalog.ContentEdit, true)]
    [InlineData("User", ApplicationPermissionCatalog.UsersView, false)]
    [InlineData("User", ApplicationPermissionCatalog.UsersManage, false)]
    public async Task Successful_sign_in_emits_permissions_from_persisted_role_only(
        string role,
        string permission,
        bool expected)
    {
        await using var fixture = await Fixture.CreateAsync(role);

        var result = await fixture.Service.SignInAsync(
            fixture.HttpContext,
            fixture.User.UserName,
            Fixture.Password,
            rememberMe: false);

        result.IsSuccess.Should().BeTrue();
        fixture.Authentication.SignedInPrincipal.Should().NotBeNull();
        var permissionValues = fixture.Authentication.SignedInPrincipal!.FindAll(ApplicationPermissionCatalog.ClaimType)
            .Select(claim => claim.Value)
            .ToArray();

        permissionValues.Contains(permission, StringComparer.Ordinal).Should().Be(expected);
        permissionValues.Should().BeEquivalentTo(ApplicationPermissionCatalog.ForRole(role));
    }

    private sealed class Fixture : IAsyncDisposable
    {
        public const string Password = "StrongPass1";
        private readonly SqliteConnection _connection;
        public AppDbContext Context { get; }
        public AuthUser User { get; }
        public CapturingAuthenticationService Authentication { get; }
        public DefaultHttpContext HttpContext { get; }
        public LocalAuthenticationService Service { get; }

        private Fixture(
            SqliteConnection connection,
            AppDbContext context,
            AuthUser user,
            CapturingAuthenticationService authentication,
            DefaultHttpContext httpContext,
            LocalAuthenticationService service)
        {
            _connection = connection;
            Context = context;
            User = user;
            Authentication = authentication;
            HttpContext = httpContext;
            Service = service;
        }

        public static async Task<Fixture> CreateAsync(string role)
        {
            var connection = new SqliteConnection("Data Source=:memory:");
            await connection.OpenAsync();
            var context = new AppDbContext(new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options);
            await context.Database.EnsureCreatedAsync();

            var now = DateTime.UtcNow;
            var user = new AuthUser("permission.user", "temporary", now, role);
            user.SetPasswordHash(new PasswordHasher<AuthUser>().HashPassword(user, Password), now);
            context.AuthUsers.Add(user);
            await context.SaveChangesAsync();

            var authentication = new CapturingAuthenticationService();
            var services = new ServiceCollection()
                .AddSingleton<IAuthenticationService>(authentication)
                .BuildServiceProvider();
            var httpContext = new DefaultHttpContext { RequestServices = services };
            var service = new LocalAuthenticationService(context);
            return new Fixture(connection, context, user, authentication, httpContext, service);
        }

        public async ValueTask DisposeAsync()
        {
            if (HttpContext.RequestServices is IAsyncDisposable asyncDisposable)
                await asyncDisposable.DisposeAsync();
            else if (HttpContext.RequestServices is IDisposable disposable)
                disposable.Dispose();
            await Context.DisposeAsync();
            await _connection.DisposeAsync();
        }
    }

    private sealed class CapturingAuthenticationService : IAuthenticationService
    {
        public System.Security.Claims.ClaimsPrincipal? SignedInPrincipal { get; private set; }

        public Task<AuthenticateResult> AuthenticateAsync(HttpContext context, string? scheme) =>
            Task.FromResult(AuthenticateResult.NoResult());

        public Task ChallengeAsync(HttpContext context, string? scheme, AuthenticationProperties? properties) =>
            Task.CompletedTask;

        public Task ForbidAsync(HttpContext context, string? scheme, AuthenticationProperties? properties) =>
            Task.CompletedTask;

        public Task SignInAsync(
            HttpContext context,
            string? scheme,
            System.Security.Claims.ClaimsPrincipal principal,
            AuthenticationProperties? properties)
        {
            SignedInPrincipal = principal;
            return Task.CompletedTask;
        }

        public Task SignOutAsync(HttpContext context, string? scheme, AuthenticationProperties? properties) =>
            Task.CompletedTask;
    }
}