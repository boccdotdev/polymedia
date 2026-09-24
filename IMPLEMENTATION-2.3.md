# Polymedia 2.3 implementation

## Scope

- One developer-selected hosting provider for new uploads and library imports.
- Provider-neutral author-facing library and upload controls.
- Bunny Stream browsing, import, direct uploads, synchronization and posters.
- HLS defaults, with per-provider MP4 preference and template overrides.
- Native MP4 routing is Pro-only, opt-in and restricted to selected volumes.
- Ordinary uploads remain unchanged unless routing explicitly applies.
- Lite can create a reference to an existing Craft-hosted video without moving or deleting its source.
- Existing assets retain their provider and remain playable after a provider switch.

## Build checklist

- [x] Prove native upload routing without bypassing Craft's batch accounting.
- [x] Add settings, credential validation and independent management/playback gating.
- [x] Implement shared HLS/MP4 resolution and Mux rendition tracking.
- [x] Implement Bunny library, uploads, state synchronization and poster retries.
- [x] Add server-side upload destination preflight and authorization.
- [x] Add provider-neutral CP controls and source-asset selection.
- [x] Add Craft source references and dynamic source URL resolution.
- [x] Run PHP, JavaScript, style and static-analysis regression checks.
- [x] Document setup, opt-in routing, supported uploaders and upgrade behavior.
- [ ] Verify real Craft uploads and playback with approved test-provider credentials.

## Rules

Secrets are environment references, never persisted literal credentials. Upload
credentials are short-lived and created server-side. Rendering does not contact
provider APIs. MP4 preference falls back to available HLS and never starts bulk
generation. No hosted Bunny player, DRM or signed playback is included.

Native routing targets new MP4 uploads, not replacement or conversion of existing
files. Unsupported custom uploaders must not be presented as supported. Failed
hosted uploads must not silently become raw native uploads. Mixed batches retain
Craft's ordering, limits and completion accounting.

The selected provider governs ingestion, not existing assets. Old credentials
may still be needed to synchronize or delete old remote videos. Remote deletion
is separately opt-in; soft deletion never deletes a remote video or source asset.

## Release gate

Target 2.3.0, not a patch release. No tag or publication until the actual Craft
workflow has been exercised. Automated tests alone do not establish browser,
filesystem-uploader or live-provider compatibility.

## Verification

- PHPUnit: 269 tests and 960 assertions passed.
- JavaScript: 43 tests passed, with no skips. Includes the real jQuery/blueimp
  transport using Craft's installed uploader implementation.
- PHPStan and Easy Coding Standard passed.
- Settings Twig syntax and Composer metadata validated. Composer metadata
  validation used `--no-plugins` because the locally installed Craft installer
  fails its relative-path calculation in this standalone plugin checkout.
- PHP 8.5 emits dependency deprecations; CI continues to cover PHP 8.2 and 8.3.

No live credentials were used. Before release, test the actual CP, public HLS/MP4
playback, webhooks and configured filesystem plugins in an attached Craft site.
Custom uploader classes cannot use automatic routing. A conflicting configuration
refuses native upload controls for that filesystem type rather than storing MP4s
in the wrong place; remove its volumes from routing to restore those controls.
