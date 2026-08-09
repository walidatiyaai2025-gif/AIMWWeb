using AIWordPressManager.Web.Services;
using FluentAssertions;

namespace AIWordPressManager.Tests;

public sealed class MediaUploadPolicyTests
{
    [Theory]
    [InlineData("photo.jpg", "image/jpeg")]
    [InlineData("graphic.png", "image/png")]
    [InlineData("document.pdf", "application/pdf")]
    [InlineData("archive.zip", "application/x-zip-compressed")]
    public void Validate_AcceptsSupportedFiles(string fileName, string contentType)
    {
        var result = MediaUploadPolicy.Validate(fileName, 1024, contentType);

        result.IsValid.Should().BeTrue();
        result.SafeFileName.Should().Be(fileName);
    }

    [Fact]
    public void Validate_RejectsExecutableContent()
    {
        var result = MediaUploadPolicy.Validate("payload.exe", 1024, "application/x-msdownload");

        result.IsValid.Should().BeFalse();
        result.Message.Should().Contain("not allowed");
    }

    [Fact]
    public void Validate_RejectsMimeMismatch()
    {
        var result = MediaUploadPolicy.Validate("photo.jpg", 1024, "application/x-msdownload");

        result.IsValid.Should().BeFalse();
        result.Message.Should().Contain("do not match");
    }

    [Fact]
    public void Validate_RejectsOversizedFiles()
    {
        var result = MediaUploadPolicy.Validate("photo.jpg", MediaUploadPolicy.MaxUploadSize + 1, "image/jpeg");

        result.IsValid.Should().BeFalse();
        result.Message.Should().Contain("25 MB");
    }

    [Fact]
    public void Validate_NormalizesOctetStreamUsingExtension()
    {
        var result = MediaUploadPolicy.Validate("report.pdf", 1024, "application/octet-stream");

        result.IsValid.Should().BeTrue();
        result.ContentType.Should().Be("application/pdf");
    }

    [Fact]
    public void SanitizeFileName_RemovesPathAndControlCharacters()
    {
        var result = MediaUploadPolicy.SanitizeFileName("../folder/evil\u0000name.jpg");

        result.Should().Be("evilname.jpg");
    }

    [Fact]
    public void MetadataVersionComparison_TreatsEquivalentOffsetsAsSameInstant()
    {
        var expected = DateTimeOffset.Parse("2026-08-09T10:00:00+00:00");
        var remote = DateTimeOffset.Parse("2026-08-09T13:00:00+03:00");

        WordPressMediaWebService.HasRemoteChanged(expected, remote).Should().BeFalse();
    }

    [Fact]
    public void MetadataVersionComparison_DetectsRemoteChange()
    {
        var expected = DateTimeOffset.Parse("2026-08-09T10:00:00+00:00");
        var remote = expected.AddSeconds(1);

        WordPressMediaWebService.HasRemoteChanged(expected, remote).Should().BeTrue();
    }
}
