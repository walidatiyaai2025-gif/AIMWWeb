using System.Security.Claims;
using AIWordPressManager.Application.SeoAudit;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Audits;
using AIWordPressManager.Web.Services;
using FluentAssertions;
using Microsoft.AspNetCore.Http;
using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Tests;

[Collection("Workflow persistence")]
public sealed class SeoAuditTenantPersistenceTests
{
    [Fact]
    public async Task Analyzer_ReturnsNull_ForForeignTenantSite()
    {
        await using var fixture = await SeoFixture.CreateAsync();
        var owner = Guid.NewGuid();
        var otherUser = Guid.NewGuid();
        var site = await fixture.AddSiteWithContentAsync(owner);
        var service = new SeoAnalysisWebService(fixture.Context, UserContext(otherUser));

        var result = await service.AnalyzeAsync(site.Id);

        result.Should().BeNull();
    }

    [Fact]
    public async Task SaveAsync_RejectsForeignOwner_WithoutPersistingSnapshot()
    {
        await using var fixture = await SeoFixture.CreateAsync();
        var owner = Guid.NewGuid();
        var foreignUser = Guid.NewGuid();
        var site = await fixture.AddSiteWithContentAsync(owner);
        var service = new SeoAuditService(fixture.Context);
        var capture = Capture(71, DateTimeOffset.UtcNow);

        var result = await service.SaveAsync(site.Id, foreignUser, capture);

        result.IsFailure.Should().BeTrue();
        result.Error.Code.Should().Be("NotFound");
        (await fixture.Context.SeoAuditSnapshots.CountAsync()).Should().Be(0);
        (await fixture.Context.SeoAuditIssues.CountAsync()).Should().Be(0);
    }

    [Fact]
    public async Task SaveAsync_PersistsExactVisibleScore_AndOwnerScopedHistory()
    {
        await using var fixture = await SeoFixture.CreateAsync();
        var owner = Guid.NewGuid();
        var foreignUser = Guid.NewGuid();
        var site = await fixture.AddSiteWithContentAsync(owner);
        var service = new SeoAuditService(fixture.Context);
        var capturedAt = new DateTimeOffset(2026, 8, 9, 18, 30, 0, TimeSpan.Zero);
        var capture = Capture(73, capturedAt);

        var saved = await service.SaveAsync(site.Id, owner, capture);
        var ownerHistory = await service.LoadHistoryAsync(site.Id, owner, 12);
        var foreignHistory = await service.LoadHistoryAsync(site.Id, foreignUser, 12);
        var latest = await service.LoadLatestAsync(site.Id, owner);

        saved.IsSuccess.Should().BeTrue();
        saved.Value.Score.Should().Be(73);
        saved.Value.AuditedItems.Should().Be(1);
        saved.Value.HighIssues.Should().Be(1);
        ownerHistory.IsSuccess.Should().BeTrue();
        ownerHistory.Value.Should().ContainSingle();
        ownerHistory.Value[0].Score.Should().Be(73);
        ownerHistory.Value[0].CapturedAt.Should().Be(capturedAt);
        latest.IsSuccess.Should().BeTrue();
        latest.Value.Score.Should().Be(73);
        latest.Value.CompletedAt.Should().Be(capturedAt);
        foreignHistory.IsFailure.Should().BeTrue();
        foreignHistory.Error.Code.Should().Be("NotFound");
    }

    [Fact]
    public async Task Analyzer_AndPersistedCapture_CanShareTheSameAuditScore()
    {
        await using var fixture = await SeoFixture.CreateAsync();
        var owner = Guid.NewGuid();
        var site = await fixture.AddSiteWithContentAsync(owner);
        var analysisService = new SeoAnalysisWebService(fixture.Context, UserContext(owner));
        var persistence = new SeoAuditService(fixture.Context);

        var analysis = await analysisService.AnalyzeAsync(site.Id);
        analysis.Should().NotBeNull();

        var issues = analysis!.Items
            .SelectMany(item => item.Issues.Select(issue => new SeoAuditCaptureIssue(
                issue.Severity,
                issue.Code,
                item.ContentType,
                item.WordPressId,
                item.Title,
                issue.Code,
                item.Link)))
            .ToList();

        var saved = await persistence.SaveAsync(
            site.Id,
            owner,
            new SeoAuditCapture(analysis.Summary.AverageScore, analysis.Summary.Total, issues, DateTimeOffset.UtcNow));

        saved.IsSuccess.Should().BeTrue();
        saved.Value.Score.Should().Be(analysis.Summary.AverageScore);
        saved.Value.AuditedItems.Should().Be(analysis.Summary.Total);
        saved.Value.Issues.Should().HaveCount(analysis.Summary.TotalIssues);
    }

    private static SeoAuditCapture Capture(int score, DateTimeOffset capturedAt)
        => new(
            score,
            1,
            new[]
            {
                new SeoAuditCaptureIssue(
                    "High",
                    "ThinContent",
                    "post",
                    101,
                    "SEO test post",
                    "ThinContent detected.",
                    "https://example.test/seo-test-post")
            },
            capturedAt);

    private static CurrentUserContext UserContext(Guid userId)
    {
        var identity = new ClaimsIdentity(
            new[] { new Claim(ClaimTypes.NameIdentifier, userId.ToString()) },
            "TestAuthentication");
        var accessor = new HttpContextAccessor
        {
            HttpContext = new DefaultHttpContext { User = new ClaimsPrincipal(identity) }
        };
        return new CurrentUserContext(accessor);
    }

    private sealed class SeoFixture : IAsyncDisposable
    {
        private SeoFixture(SqliteConnection connection, AppDbContext context)
        {
            Connection = connection;
            Context = context;
        }

        public SqliteConnection Connection { get; }
        public AppDbContext Context { get; }

        public static async Task<SeoFixture> CreateAsync()
        {
            var connection = new SqliteConnection("Data Source=:memory:;Foreign Keys=True");
            await connection.OpenAsync();
            var options = new DbContextOptionsBuilder<AppDbContext>().UseSqlite(connection).Options;
            var context = new AppDbContext(options);
            await context.Database.EnsureCreatedAsync();
            return new SeoFixture(connection, context);
        }

        public async Task<Site> AddSiteWithContentAsync(Guid ownerUserId)
        {
            var now = DateTime.UtcNow;
            var site = new Site("SEO Tenant Site", new Uri("https://example.test"), now, ownerUserId);
            var content = new WordPressContentRecord(site.Id, 101, "post", now);
            content.Update(
                "SEO test post",
                "seo-test-post",
                "publish",
                "https://example.test/seo-test-post",
                "<p>Short body.</p>",
                string.Empty,
                now,
                now);

            Context.Sites.Add(site);
            Context.WordPressContentRecords.Add(content);
            await Context.SaveChangesAsync();
            return site;
        }

        public async ValueTask DisposeAsync()
        {
            await Context.DisposeAsync();
            await Connection.DisposeAsync();
        }
    }
}
