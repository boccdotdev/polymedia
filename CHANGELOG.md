# Changelog

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
