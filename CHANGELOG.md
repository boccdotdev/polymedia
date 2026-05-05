# Changelog

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
