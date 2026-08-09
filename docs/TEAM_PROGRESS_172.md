# TEAM_PROGRESS_172

## Task
`CNT-011` — Upload, delete, and optimize media.

## Scope
Complete the production-facing WordPress media workflow using the existing live media services, with tenant isolation, safe upload validation, metadata/accessibility optimization, AI alt-text assistance, destructive-action confirmation, conflict protection, and cache reconciliation.

## Implemented
- Hardened upload validation before any stream is opened or WordPress request is sent.
- Added a 25 MB server-side limit and an explicit allowlist for JPG/JPEG, PNG, GIF, WebP, AVIF, PDF, DOC/DOCX, XLS/XLSX, and ZIP.
- Rejects executable/unsupported extensions and MIME/extension mismatches.
- Sanitizes the client file name before sending multipart content to WordPress.
- Added live `context=edit` media-detail loading for title, slug, alt text, caption, description, media type, MIME type, source URL, modified time, dimensions, and file size when available.
- Added a site-level Optimize workflow for title, slug, alt text, caption, and description.
- Added an explicit metadata quality indicator/checklist.
- Added AI alt-text suggestions for image media; generated text is reviewable and is not saved until the user explicitly saves the optimization.
- Added optimistic concurrency using WordPress `modified_gmt` so metadata changes are not silently overwritten when the remote media item changed after opening it.
- Added permanent-delete confirmation explaining `force=true`, lack of WordPress trash recovery, and possible impact on pages using the file.
- After a confirmed remote delete, reconciles the local media cache to `Unavailable` without advancing the delta-sync watermark.
- If remote deletion succeeds but local cache reconciliation fails, reports the remote success accurately and warns about the cache instead of encouraging a dangerous delete retry.
- Global Media cards now link directly to the site Media Manager for management/optimization.
- Added owner preflight to media API metadata, AI alt-text, bulk-delete approval, and bulk-metadata queue routes.
- Added automated validation tests for allowed/blocked uploads, MIME mismatch, size limit, file-name sanitation, and metadata version comparison.

## Optimization boundary
This release optimizes WordPress media metadata, SEO/accessibility fields, and AI-assisted alt text. It does **not** recompress, resize, or transcode the binary image/file. Binary image processing requires a dedicated image-processing engine and remains outside this vertical slice.

## Safety rules
- User ownership is verified before media API operations.
- Upload validation is enforced server-side; the browser `accept` attribute is not trusted as the security boundary.
- AI suggestions never auto-write to WordPress.
- Metadata save performs a remote version preflight.
- Permanent delete requires explicit UI confirmation.
- Local delete reconciliation preserves the synchronization cursor.

## Validation gate
- Full solution/Razor build.
- Full automated test suite.
- Media upload/concurrency tests green.
- GitHub Actions `Build` and `.NET Build Verification` green before merge.

## Version
`155.119.0`

## Status
Implemented — CI validation pending.
