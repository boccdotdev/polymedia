# Polymedia for Craft CMS

Universal media field for Craft CMS — HLS, YouTube, Vimeo, Spotify, Mux, TikTok, MP4, audio, and 10+ more providers as first-class assets, with [Media Chrome](https://www.media-chrome.org/) compatible player rendering.

## What it's for

Polymedia stores external media URLs as lightweight `.pmedia` manifest files inside your existing Craft asset volumes. Each manifest is a real Asset element — searchable, relatable, permission-gated — backed by a database record holding type metadata, playback defaults, and related poster/caption/transcript assets.

The front-end renders `<media-controller>` + the correct provider web component via a single Twig call. No iframes, no embed codes, no JavaScript framework lock-in.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- Media Chrome scripts on the front-end (loaded via the `scripts()` helper or your own bundler)

## Installation

```bash
composer require boccdotdev/polymedia
php craft plugin/install polymedia
```

## Field Setup

Create a **Polymedia** field (appears in the field type picker). It extends the native Assets field, so it inherits all Craft's relation features — min/max limits, eager loading, element conditions.

Field settings:
- **Allowed Providers** — restrict which provider types can be selected (e.g. only YouTube + Mux). Leave empty for all.

> **Note:** The native Assets field still works with `.pmedia` files for headless or advanced use cases, but loses provider filtering.

## Adding Media

Click **Add media URL** in the asset index toolbar. Paste any supported URL, give it a title, and choose a target volume. The plugin auto-detects the provider type and creates a `.pmedia` manifest asset.

For providers that can't be auto-detected (Shaka, Video.js, PeerTube), use the "Force Type" dropdown.

## Front-End Setup

### Scripts Helper

Add to your layout template:

```twig
{{ craft.polymedia.scripts() }}
```

This emits `<script type="module">` tags for Media Chrome and the providers listed in your plugin settings.

#### CDN Mode (default)

Zero-config. Scripts load from jsdelivr (or your configured CDN host).

```twig
{{ craft.polymedia.scripts({ providers: ['hls', 'youtube', 'vimeo', 'mux'] }) }}
```

#### Self-Host Mode

1. Set **Script Loader Mode** to `self-host` in plugin settings.
2. Set **Self-Host Base URL** (e.g. `/dist/polymedia/`).
3. Copy the built provider files into that directory.

#### None Mode

For Vite/webpack/import-map setups — `scripts()` emits nothing. Wire up the imports yourself.

## Twig API

### `craft.polymedia.player(asset, options)`

Renders a full `<media-controller>` player:

```twig
{{ craft.polymedia.player(media) }}
```

Options: `autoplay`, `loop`, `muted`, `controls`, `playsinline`, `preload`, `crossorigin`, `poster`, `class`, `id`, `attrs`, `children`, `tracks`.

### `craft.polymedia.element(asset, options)`

Renders just the media element (no controller wrapper).

### `craft.polymedia.data(asset)`

Returns the parsed manifest data as an array.

### `craft.polymedia.is(asset)`

Returns `true` if the value is a polymedia asset.

### `craft.polymedia.scripts(options)`

Renders script tags. Options: `providers` (array), `version` (Media Chrome major), `mode` (`cdn`/`self-host`/`none`).

### `craft.polymedia.poster(asset)`

Returns the resolved poster URL for a polymedia asset.

### `craft.polymedia.tracks(asset, role, siteId)`

Returns track-type related assets (captions, subtitles, descriptions).

### `craft.polymedia.transcript(asset)`

Returns the transcript related asset.

## Asset Methods

Polymedia attaches a behavior to every `Asset` element so you can call media accessors directly on the asset — no `craft.polymedia.*` wrapper required. The methods mirror the Twig API one-to-one.

```twig
{% set media = entry.heroMedia.one() %}

{{ media.getPlayer() }}
{{ media.getElement() }}
{{ media.getPoster() }}
{% set data = media.getData() %}
{% for track in media.getTracks('captions') %}…{% endfor %}
{% set transcript = media.getTranscript() %}

{% if media.isPolymedia %}…{% endif %}
```

Twig getter shorthand also works — drop the `get` and the parens:

```twig
{{ media.player }}    {# = media.getPlayer() #}
{{ media.poster }}    {# = media.getPoster() #}
{{ media.data.title }}
```

### Available methods

| Method | Returns | Equivalent |
|--------|---------|------------|
| `getPlayer(options = {})` | `Markup` | `craft.polymedia.player(asset, options)` |
| `getElement(options = {})` | `Markup` | `craft.polymedia.element(asset, options)` |
| `getData()` | `array` | `craft.polymedia.data(asset)` |
| `getPoster()` | `string\|null` | `craft.polymedia.poster(asset)` |
| `getTracks(role = 'captions', siteId = null)` | `Asset[]` | `craft.polymedia.tracks(asset, role, siteId)` |
| `getTranscript()` | `Asset\|null` | `craft.polymedia.transcript(asset)` |
| `getIsPolymedia()` | `bool` | `craft.polymedia.is(asset)` |

The behavior is attached to all assets, but the methods safely return empty values for non-polymedia assets — guard with `media.isPolymedia` if you mix asset kinds in the same template.

### Choosing between styles

Use the asset method style for terse, asset-centric templates:

```twig
{% for media in entry.gallery.all() %}
    <figure>
        {{ media.getPlayer({ controls: true }) }}
        <figcaption>{{ media.title }}</figcaption>
    </figure>
{% endfor %}
```

Use `craft.polymedia.*` when you're checking arbitrary values, in macros, or in shared partials where the input may not be an asset:

```twig
{% if craft.polymedia.is(value) %}
    {{ craft.polymedia.player(value) }}
{% endif %}
```

## Examples by Provider

### YouTube

```twig
{{ craft.polymedia.scripts({ providers: ['youtube'] }) }}
{{ craft.polymedia.player(media) }}
{# Renders: <media-controller><youtube-video slot="media" src="https://youtube.com/watch?v=..."></youtube-video></media-controller> #}
```

### Mux

```twig
{{ craft.polymedia.scripts({ providers: ['mux'] }) }}
{{ craft.polymedia.player(media) }}
{# Renders: <media-controller><mux-video slot="media" playback-id="abc123" stream-type="on-demand"></mux-video></media-controller> #}
```

**Important:** Mux uses `playback-id`, not `src`.

### HLS

```twig
{{ craft.polymedia.scripts({ providers: ['hls'] }) }}
{{ craft.polymedia.player(media) }}
{# Renders: <media-controller><hls-video slot="media" src="https://cdn.example.com/stream.m3u8"></hls-video></media-controller> #}
```

### Spotify / Audio

```twig
{{ craft.polymedia.scripts({ providers: ['spotify'] }) }}
{{ craft.polymedia.player(media) }}
{# Renders: <media-controller audio><spotify-audio slot="media" src="https://open.spotify.com/track/..."></spotify-audio></media-controller> #}
```

Audio types use `<media-controller audio>` and emit a slotted `<img slot="poster">` for cover artwork.

### Native MP4/Audio

```twig
{{ craft.polymedia.player(media) }}
{# Renders: <media-controller><video slot="media" src="https://cdn.example.com/video.mp4"></video></media-controller> #}
```

No additional provider scripts needed for native `<video>` and `<audio>`.

## Composing Controls

Use the `children` option to add Media Chrome control elements:

```twig
{{ craft.polymedia.player(media, {
    children: '<media-control-bar>
        <media-play-button></media-play-button>
        <media-time-range></media-time-range>
        <media-time-display></media-time-display>
        <media-mute-button></media-mute-button>
        <media-fullscreen-button></media-fullscreen-button>
    </media-control-bar>'
}) }}
```

## Posters and Cover Artwork

Poster resolution order (highest priority first):
1. Explicit `poster` option in Twig (`false` suppresses entirely)
2. Item-level poster (attached via asset edit screen)
3. Derived thumbnail from manifest (auto-generated for YouTube, Vimeo, Mux, Cloudflare, Wistia)

For audio types, the poster is emitted as `<img slot="poster" class="polymedia-cover">` inside `<media-controller audio>` — available to themes that render a "now playing" cover.

## Accessibility

Attach `.vtt` caption files via the asset edit screen. Captions are site-scoped — attach different language tracks to different Craft sites.

Tracks auto-emit as `<track>` elements inside the player. `crossorigin="anonymous"` is set automatically when VTT files are on a different domain.

## Security

The plugin warns when saving a URL that appears to contain a signed token (e.g. Mux signed playback, S3 presigned URLs) into a publicly accessible volume. The manifest file is readable by anyone with volume access — signed tokens in public manifests may leak.

Disable the warning in plugin settings if your setup handles access control at the filesystem level.

## Supported Providers

| Provider | Element | Auto-detect |
|----------|---------|-------------|
| HLS | `<hls-video>` | `.m3u8` URLs |
| DASH | `<dash-video>` | `.mpd` URLs |
| Shaka | `<shaka-video>` | Manual only |
| Mux | `<mux-video>` | `stream.mux.com` |
| YouTube | `<youtube-video>` | `youtube.com`, `youtu.be` |
| Vimeo | `<vimeo-video>` | `vimeo.com` |
| Spotify | `<spotify-audio>` | `open.spotify.com` |
| TikTok | `<tiktok-video>` | `tiktok.com`, `vm.tiktok.com` |
| Wistia | `<wistia-video>` | `*.wistia.com` |
| JW Player | `<jwplayer-video>` | `cdn.jwplayer.com` |
| Twitch | `<twitch-video>` | `twitch.tv` |
| Cloudflare | `<cloudflare-video>` | `videodelivery.net`, `cloudflarestream.com` |
| PeerTube | `<peertube-video>` | Manual only |
| Video.js | `<videojs-video>` | Manual only |
| MP4/WebM/MOV | `<video>` | `.mp4`, `.webm`, `.mov` |
| Audio | `<audio>` | `.mp3`, `.m4a`, `.ogg`, `.wav`, `.flac` |

## GraphQL

GraphQL support is planned for v2. Currently Twig-only.

## Roadmap

- [ ] GraphQL types and queries
- [ ] Client-side metadata writeback (`loadedmetadata` → duration/dimensions)
- [ ] Live streaming UI hints
- [ ] Console command for self-host script bundling

## Credits

Built on [Media Chrome](https://www.media-chrome.org/) and the [media-elements](https://github.com/muxinc/media-elements) monorepo by Mux.

## License

MIT
