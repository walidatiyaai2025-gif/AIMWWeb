using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class SiteOnboardingProfileFlowTests
{
    [Fact]
    public async Task PersistAsync_WithoutExistingProfile_CreatesNewProfile()
    {
        var createdId = Guid.NewGuid();
        var createCalls = 0;
        var updateCalls = 0;

        var result = await SiteOnboardingProfileFlow.PersistAsync(
            null,
            () =>
            {
                createCalls++;
                return Task.FromResult(createdId);
            },
            _ =>
            {
                updateCalls++;
                return Task.CompletedTask;
            });

        result.Should().Be(createdId);
        createCalls.Should().Be(1);
        updateCalls.Should().Be(0);
    }

    [Fact]
    public async Task PersistAsync_WithExistingProfile_UpdatesSameProfileInsteadOfCreatingDuplicate()
    {
        var existingId = Guid.NewGuid();
        var createCalls = 0;
        Guid? updatedId = null;

        var result = await SiteOnboardingProfileFlow.PersistAsync(
            existingId,
            () =>
            {
                createCalls++;
                return Task.FromResult(Guid.NewGuid());
            },
            id =>
            {
                updatedId = id;
                return Task.CompletedTask;
            });

        result.Should().Be(existingId);
        createCalls.Should().Be(0);
        updatedId.Should().Be(existingId);
    }
}
