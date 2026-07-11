# Changelog

## 2.0.0 - 2026-07-11

### Added
- **Lite / Pro editions.** Lite remains free URL media (including Mux paste). Pro gates Mux credentials UI, library browse, and direct upload.
- Mux settings: Token ID, Token Secret (env-overridable), and optional “delete from Mux on Craft hard-delete” (default off).
- `Mux` service wrapping `muxinc/mux-php` (`isConfigured`, list/get assets, direct upload, delete, first-frame thumbnail URL).
- **Browse Mux library** CP modal (Pro + credentials): live Mux Assets API grid, “In Craft” badge, import/reuse by playback ID.
- `PosterFetcher` downloads provider stills into the item folder and attaches as poster; user-selected posters win.
- `FetchMuxPoster` queue job retries Mux first-frame downloads while assets are still processing.
- CP asset thumbs for `.pmedia` files use the related poster (or remote thumbnail fallback).
- `MediaItems::getByTypeAndProviderId()` / `getByTypeAndProviderIds()` for import reuse.
- CP config flag `Craft.Polymedia.muxEnabled` (Pro + credentials).
- **Upload to Mux** CP modal: direct upload via UpChunk, progress bar, poll until playback ID, create/reuse `.pmedia`, first-frame poster job.
- Vendored `@mux/upchunk` in the CP asset bundle.
- Optional **delete Mux asset on Craft hard-delete** (default off); soft-delete never calls Mux. Remote delete is synchronous (v1); Lite/unconfigured skips log a warning rather than failing silently.
- Light **Mux status badge** + asset id on the asset editor for imported/uploaded items.
- README: Lite/Pro editions, Mux credentials, browse/upload, poster priority, folder-image limits, public playback.

### Changed
- CP: the separate **Add media URL**, **Browse Mux library**, and **Upload to Mux** buttons are now a single **Add media** disclosure menu (**From URL** / **Browse Mux library** / **Upload to Mux**) beside **Upload files**, on both the Assets index and field selection modals.
- Mux upload modal: Craft-style file picker (hidden input + upload button + filename), a cancel notice, and an auto-sizing shell so progress/status rows never clip.
- Mux thumbnail URLs use first frame (`?time=0`) instead of Mux’s mid-video default.
- `autoFetchPoster` is implemented and re-enabled in settings (URL create path downloads a poster when none is supplied).
- Mux library imports always attempt a first-frame poster when the item has no poster.
- License changed from MIT to the [Craft License](https://craftcms.github.io/license/) (`proprietary`) with the introduction of the commercial Pro edition. Lite remains free to install from the Plugin Store.

## 1.3.0 - 2026-07-11

### Added
- `metadata` column on `polymedia_items` so manifest extras (thumbnail URL, provider hints) live in the DB; enables DB-first `data()` / `ManifestWriter::read()` without a filesystem round-trip per render.
- Lazy metadata backfill when reading older rows that only have the `.pmedia` file populated.
- `options.mediaAttrs` on `player()` / `element()` to pass attributes through to the inner media element (`title`, `referrerpolicy`, etc.). `attrs` continues to target `<media-controller>` only.
- `EditorContent` service for CP editor fields and element-select configs (extracted from `Plugin`).
- Composer scripts: `check-cs`, `fix-cs`, `phpstan`, `test`.
- Easy Coding Standard (`ecs.php`) and PHPStan (`phpstan.neon`, level 5) config.
- GitHub Actions CI on PHP 8.2 and 8.3 (phpunit + ECS + PHPStan).

### Changed
- `MediaItems::getByAssetId()` is memoized per request; cache invalidated on `save()` / `deleteByAssetId()`.
- `ManifestWriter::read()` prefers the DB record; filesystem is fallback + backfill path.
- `savePoster()` / `saveTracks()` logic lives on `RelatedAssets`; `Plugin` keeps thin public proxies for BC.
- `Plugin.php` slimmed (~1,370 → ~1,070 lines) via `EditorContent` extraction.
- Schema version `1.1.0` → `1.2.0` (run `php craft up` / plugin migrate).

## 1.2.2 - 2026-07-11

### Fixed
- Soft-deleting a polymedia asset no longer drops its `MediaItemRecord` or related poster/track attachments. Restore from trash keeps metadata intact; cleanup still runs on hard delete (including the dedicated item folder).
- `craft.polymedia.element()` now emits the native `controls` attribute when enabled (default). `player()` still omits it so Media Chrome controls are not doubled.
- CDN host and self-host base URL settings with env vars (`$VAR` / aliases) are resolved via `App::parseEnv()` in `scripts()`.
- VTT validation (`validateVttOnUpload`) runs when attaching tracks from the asset editor; invalid files are skipped.
- Double-encoded `alt` on audio cover `<img>` posters.
- CP JS strings (`Add media URL`, `Media item created.`) are registered for translation.

### Changed
- Settings UI no longer shows unimplemented options: Attachments Volume, Auto-Fetch Poster, Require Captions for Video, Caption Language from Site, Restrict Asset Kinds. Model properties remain for project config BC (removal planned for 2.0).
- README clarifies that `controls` applies to `element()` / `getElement()`, not `player()`.

## 1.2.1 - 2026-05-29

### Fixed
- `craft up` could fail when a core or third-party migration re-saved sections before this plugin's `m260507_000000_field_settings` migration had run. The element-validation hook queried the not-yet-created `polymedia_field_settings` table and threw `The table does not exist`. `AssetFieldSettings::getAllowedProviders()` now returns no restrictions when the table is absent, so migrations complete cleanly.

## 1.2.0 - 2026-05-29

### Added
- Track pickers on the asset edit screen for video items — **Captions**, **Subtitles**, and **Descriptions**. Each is a multi-select `.vtt` picker that can also upload into the item's folder. Tracks are site-scoped, with `srclang` and `label` derived from the current site on save. Previously tracks could only be attached programmatically via `RelatedAssets::attach()`.
- Poster image picker on the **Add media URL** create screen, so a poster can be set when first creating an item — previously only available on the asset edit screen. The picker is image-only and supports inline upload, with uploads landing in the item's own folder.
- **Add media URL** button on the standalone Assets index toolbar, beside **Upload files**. Previously only available in field selection modals.
- Each new `.pmedia` is now created inside its own dedicated folder (named after the title slug plus a short uid), so its poster and track files sit alongside it instead of cluttering the parent folder. Posters and tracks uploaded inline are co-located into that folder automatically.
- `polymedia/migrate/folders` console command to move existing `.pmedia` items (and their co-located poster/track files) into per-item folders. Supports `--dry-run`; safe to run repeatedly — items already in their own folder are skipped.

### Changed
- Removed the target-volume dropdown from the **Add media URL** screen. New `.pmedia` manifests now land in the volume/folder the user is currently browsing (or the field's upload location), matching how Craft handles normal asset uploads. Falls back to the configured default volume, then the first writable volume.
- Hard-deleting a `.pmedia` now also removes its dedicated folder and the poster/track files inside it. A `.pmedia` that shares a folder with other items leaves the folder untouched.

## 1.1.0 - 2026-05-07

### Added
- "Allowed Providers" setting on plain `craft\fields\Assets` fields when the `polymedia` kind is enabled. Mirrors the same filter on `PolymediaField`, so any Assets field can restrict media URL selections to specific providers. Toggles live as the kind is checked/unchecked.
- `polymedia_field_settings` table for per-field plugin settings keyed by field UID.
- `AssetFieldSettings`, `ProviderFilter` services. `PolymediaAssetFieldBehavior` attached to native Assets fields.
- Plugin icon (`src/icon.svg`).

### Changed
- Provider filter validation extracted to a shared service. `PolymediaField` and plain Assets fields now run identical validation logic.

### Removed
- Unused per-placement poster override code (`FieldOverrides` service, `FieldRelationRecord`, `polymedia_field_relations` table). The schema, service, and `allowPosterOverride` config key were never reachable from Twig or the CP UI.

## 1.0.2 - 2026-05-06

### Added
- `PolymediaAssetBehavior` attached to all `Asset` elements, exposing `getPlayer()`, `getElement()`, `getData()`, `getPoster()`, `getTracks()`, `getTranscript()`, and `getIsPolymedia()` directly on assets in Twig.

## 1.0.1 - 2026-05-06

### Fixed
- Restored "Add Media URL" button in asset selection modals and slideouts.

## 1.0.0 - 2026-05-04

### Added
- Initial release.
- URL detection for 16 media providers (HLS, DASH, Mux, YouTube, Vimeo, Spotify, TikTok, Wistia, JW Player, Twitch, Cloudflare, Shaka, PeerTube, Video.js, MP4, Audio).
- `.pmedia` JSON manifest files stored as Craft assets.
- `MediaItemRecord` database mirror for fast index queries.
- Deterministic thumbnail derivation (YouTube, Vimeo, Mux, Cloudflare, Wistia).
- Custom `polymedia` file kind with asset index table attributes (Type, Provider, Duration).
- CP "Add media URL" modal with signed-URL warning.
- Asset edit sidebar panel with playback defaults.
- Related assets system (poster, captions, subtitles, descriptions, transcript).
- VTT validation with BOM stripping.
- `PolymediaField` field type (extends Assets) with provider filtering and per-placement poster overrides.
- `Renderer` service with `player()`, `element()`, `data()`, `is()`, `scripts()`.
- `craft.polymedia.*` Twig variable API.
- Media Chrome `<media-controller>` rendering with correct provider element tags.
- Script loader modes: CDN, self-host, none.
- Asset indexer reconciler for orphaned `.pmedia` files.
- 102 unit tests, 268 assertions.
