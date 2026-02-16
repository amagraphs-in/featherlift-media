# FeatherLift Media

FeatherLift Media (formerly Enhanced S3 Media Upload) is a WordPress plugin that lets teams optimize images, offload media to Amazon S3, and serve assets through CloudFront with optional SQS-based automation. The plugin also ships with AI helpers for alt text, bulk processing utilities, and an opinionated release pipeline powered by GitHub Actions.

## Key Capabilities
- Toggleable media optimization (resize + compression) and offload/CDN workflows
- Guided AWS provisioning for S3 buckets, SQS queues, and CloudFront distributions
- Background queue management with granular progress logging
- Manual and bulk media controls inside the WordPress Media Library
- Optional AI-generated alt tags with provider/model selection
- Automated GitHub release packaging on semantic tags (`v*`)

## Installation
1. Clone or download this repository into your WordPress `wp-content/plugins` directory.
2. Activate **FeatherLift Media** from the WordPress Plugins screen.
3. Open **Media → FeatherLift Media** to configure AWS credentials, optimization rules, and automation preferences.

## Configuration Checklist
- AWS access key, secret, and preferred region
- Bucket naming strategy and prefix (if required)
- CloudFront domain / distribution ID (manual or auto-provisioned)
- Optional TinyPNG API key and resizing caps (default 2560px max width)
- Automation strategy for future uploads plus bulk tooling for backfills
- AI provider credentials (OpenAI, Anthropic, or custom endpoint)

## Release Workflow
1. Commit changes to `master`.
2. Bump the plugin version header and `$version` property.
3. Tag the release using the format `vX.Y.Z`.
4. Push `master` and the tag (`git push origin master && git push origin vX.Y.Z`).
5. GitHub Actions (`.github/workflows/release.yml`) builds the distributable ZIP and publishes it as a GitHub Release.

## Release Notes
### v1.0.7 — 2026-02-16
- Fixed a syntax error in the admin JavaScript bundle that was blocking WordPress’ media-frame scripts from rendering attachments, restoring the Media Library grid/list views.

### v1.0.6 — 2026-02-16
- Declared all legacy option fields on the main plugin class to silence PHP 8.2 dynamic property notices during bootstrap.
- Hardened secret encryption to fall back on defined salts/keys (or a deterministic fingerprint) when `wp_salt()` is unavailable, preventing fatal errors when the plugin loads before WordPress pluggables.

### v1.0.5 — 2026-02-16
- Introduced the FeatherLite Optimization Box across settings, log, and bulk pages so recent uploads can be scanned, multi-selected, and queued without leaving the screen.
- Updated the TinyPNG API key control to remain visible with contextual hints and disabled styling until the service is enabled, preventing confusion over missing fields.

### v1.0.4 — 2026-02-16
- Rebranded all plugin UI strings, notices, and documentation to FeatherLift Media while keeping legacy functionality intact.
- Hardened the onboarding flow so optimization/offload controls stay hidden until settings are saved, and disabled AI helpers by default.
- Simplified the admin layout to a single-column stack to avoid grid breakage and improve readability.

### v1.0.3 — 2026-02-16
- Fixed a PHP parse error introduced in the admin script enqueue logic that prevented plugin activation.
- Confirmed assets/admin.js and assets/admin.css load correctly on all media and settings screens.

### v1.0.2 — 2026-02-16
- Added in-modal attachment description preview for quick editorial review.
- Updated plugin version metadata to 1.0.2 and published via release pipeline.

### v1.0.1 — 2025-??-??
- Introduced automated GitHub release workflow triggered by tags.
- Minor maintenance updates and documentation cleanup.

### v1.0.0 — 2025-??-??
- Initial public release with S3/SQS automation, optimization controls, and AI alt text features.

## Updating This Document
- Append new entries to the **Release Notes** section whenever you create a release tag.
- If the release workflow changes, edit both this README and `.github/workflows/release.yml` to keep the process in sync.
- For significant feature work, consider adding usage notes or configuration screenshots to help downstream teams adopt the change quickly.
