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

## Editions (Lite / Pro)

Polymedia ships with two Craft Plugin Store editions:

| Edition | Includes |
|---------|----------|
| **Lite** (free) | URL media for all providers (including **paste** a Mux stream URL), field, player, posters, tracks |
| **Pro** | Everything in Lite **+** Mux Token settings, **Browse Mux library**, **Upload to Mux** (direct upload) |

Paste-a-Mux-URL playback never requires Pro. Library browse and direct upload do.

On non-public domains Craft allows unlicensed Pro for development (normal Craft trial rules).

## Adding Media

Open the **Add media** menu and choose **From URL**, paste any supported URL, and give it a title. The plugin auto-detects the provider type and creates a `.pmedia` manifest asset. (With Pro + Mux credentials, the same menu also offers **Browse Mux library** and **Upload to Mux**.) The menu is available in two places:

- **Assets index** — sits beside **Upload files**. The manifest lands in the volume/folder you're currently browsing, exactly like an uploaded file.
- **Field selection modals** — when picking media for a Polymedia or Assets field. The manifest lands in the field's upload location.

No volume picker — the target follows your current location, falling back to the configured default volume (then the first volume you can write to) if none can be resolved.

For providers that can't be auto-detected (Shaka, Video.js, PeerTube), use the "Force Type" dropdown.

You can also set a poster image right on the **From URL** screen — image-only, with inline upload landing in the new item's folder. Posters and tracks can still be managed later on the asset edit screen.

### Mux library & upload (Pro)

1. Install **Pro** and open **Settings → Plugins → Polymedia → Mux**.
2. Enter a Mux API **Token ID** and **Token Secret** (env vars supported, e.g. `$MUX_TOKEN_ID`).
3. On the Assets index (or field asset modal), open the **Add media** menu and use:
   - **Browse Mux library** — live list from your Mux account; import creates a `.pmedia` or reuses one matched by **playback ID**.
   - **Upload to Mux** — browser direct upload (UpChunk); when Mux has a playback ID, Craft creates/reuses the `.pmedia`.

**Playback policy:** v1 imports **public** playback only. Signed-only assets are flagged in the browse UI and cannot be imported yet.

**Mux service fees** are separate from the Polymedia Pro license.

Optional setting **Delete Mux asset when Craft asset is deleted** (default **off**): when enabled, **hard-deleting** a Mux `.pmedia` also deletes the video in Mux. Soft-delete / trash never calls Mux.

**Notes on delete-from-Mux:** the remote delete runs **synchronously** in the hard-delete request (not a queue job). Deleting many Mux items at once may take longer. If the site is on **Lite** or credentials are missing while this setting is still on, Craft logs a **warning** and skips the Mux API call (the remote asset may remain until deleted in Mux).

### Per-item folders

Each `.pmedia` is created inside its own folder (named after the title slug plus a short uid), keeping its poster and track files together and the parent volume tidy. Hard-deleting the item removes the folder and everything in it.

To reorganise items created before this behaviour existed, run:

```
./craft polymedia/migrate/folders --dry-run   # preview
./craft polymedia/migrate/folders             # apply
```

Items already in their own folder are skipped, so it's safe to re-run.

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

Options: `autoplay`, `loop`, `muted`, `playsinline`, `preload`, `crossorigin`, `poster`, `class`, `id`, `attrs`, `mediaAttrs`, `children`, `tracks`.

> **Note:** `controls` is intentionally **not** applied inside `player()`. Media Chrome supplies the control UI; native controls would conflict with `<media-controller>`.
>
> - `attrs` — HTML attributes on the outer `<media-controller>`
> - `mediaAttrs` — HTML attributes on the inner media element (e.g. `title`, `referrerpolicy`)

### `craft.polymedia.element(asset, options)`

Renders just the media element (no controller wrapper). Use this when you want a bare provider element without Media Chrome.

Options: `autoplay`, `loop`, `muted`, **`controls`**, `playsinline`, `preload`, `crossorigin`, `poster`, `mediaAttrs`. Defaults include `controls: true` so bare `<video>`/`<audio>` elements are usable without a custom control bar.

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

### Front-end player

Poster resolution order (highest priority first):
1. Explicit `poster` option in Twig (`false` suppresses entirely)
2. Item-level poster (attached via asset edit screen)
3. Derived thumbnail from manifest (auto-generated for YouTube, Vimeo, Mux, Cloudflare, Wistia)

For audio types, the poster is emitted as `<img slot="poster" class="polymedia-cover">` inside `<media-controller audio>` — available to themes that render a "now playing" cover.

### Auto-fetch & create-time priority

When creating media **without** a user-selected poster:

1. **User poster** (create screen or asset editor) always wins — never overwritten by auto-fetch.
2. Else if **Auto-Fetch Poster** is on (URL create), or always for **Mux library/upload** imports: download a still into the item’s dedicated folder and attach it as the poster.
3. **Mux** uses the Image API first frame: `https://image.mux.com/{playbackId}/thumbnail.jpg?time=0` (Mux’s default without `time` is mid-video).
4. If the Mux image is not ready yet, a queue job retries with backoff.
5. Last resort: remote thumbnail URL only in manifest metadata (CP may show it until a local poster exists).

CP asset index / picker thumbs for `.pmedia` files use the related poster when present, else the remote thumbnail URL.

**Folder covers:** Craft has no first-class folder-cover API. Posters are co-located in each item’s dedicated folder so folder contents show the image; the parent index still uses Craft’s folder icon for the folder row itself.

## Accessibility

Attach `.vtt` files to video items via the asset edit screen, in three roles: **Captions**, **Subtitles**, and **Descriptions**. Each role is a multi-select picker that can also upload straight into the item's folder. The pickers appear only on video items (audio items show the poster picker alone).

Tracks are site-scoped — attach different language files to different Craft sites. On save, each track's `srclang` and `label` are derived from the current site (primary language subtag and locale display name).

Tracks auto-emit as `<track>` elements inside the player. `crossorigin="anonymous"` is set automatically when VTT files are on a different domain.

## Security

The plugin warns when saving a URL that appears to contain a signed token (e.g. Mux signed playback, S3 presigned URLs) into a publicly accessible volume. The manifest file is readable by anyone with volume access — signed tokens in public manifests may leak.

Disable the warning in plugin settings if your setup handles access control at the filesystem level.

## Troubleshooting

### YouTube playback error 153

YouTube refuses embedded playback (error code 153) when the embed request reaches `youtube.com` with no `Referer` header. The `<youtube-video>` element builds its `<iframe>` inside a shadow root, so this can't be fixed from your template — it's governed by your site's **referrer policy**, not the plugin.

Browsers default to `strict-origin-when-cross-origin`, which works. Error 153 means something on your site has overridden it to a stricter value such as `no-referrer` or `same-origin`, stripping the referrer to YouTube.

Fix it at the document level. Either send the header:

```
Referrer-Policy: strict-origin-when-cross-origin
```

…or add a meta tag to your page `<head>`:

```html
<meta name="referrer" content="strict-origin-when-cross-origin">
```

If you intentionally enforce a strict global policy, scope the relaxation to pages that embed YouTube rather than site-wide.

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

Assets expose a `polymedia` field on the GraphQL Asset interface — `null` for anything that isn't a `.pmedia` asset. It works through any query that returns assets (asset fields on entries, the root `assets` query), so headless front ends need no special queries:

```graphql
{
  assets(kind: "polymedia") {
    title
    polymedia {
      type          # mux | youtube | vimeo | hls | …
      providerId    # Mux playback ID, YouTube video ID, …
      element       # mux-video, youtube-video, video, …
      url
      title
      duration      # seconds, if known
      width
      height
      poster        # attached poster asset URL, falling back to the remote thumbnail
      tracks(role: ["captions", "subtitles"], siteId: 1) {
        kind        # captions | subtitles | descriptions
        url
        srclang
        label
        isDefault
        siteId
      }
      transcriptUrl
      metadata      # raw metadata as a JSON-encoded string
    }
  }
}
```

- **Enable per schema.** The field is gated behind a **Polymedia → View polymedia data** schema component (GraphQL → Schemas in the control panel). It is off by default everywhere, including the public schema — the field doesn't exist in a schema until you enable it.
- `tracks` accepts optional `role` (defaults to all three kinds) and `siteId` (defaults to the site the asset was queried in) arguments.
- Resolution is batched: any number of media items in a query costs a fixed number of database queries, and the `.pmedia` manifest file is never read.
- GraphQL itself requires Craft Pro (a Craft constraint, not a plugin one).
- The `polymedia` handle is reserved on asset field layouts — a custom field with that handle on a volume's layout would collide with the interface field.

## Roadmap

- [x] GraphQL types and queries
- [ ] Client-side metadata writeback (`loadedmetadata` → duration/dimensions)
- [ ] Live streaming UI hints
- [ ] Console command for self-host script bundling

## Credits

Built on [Media Chrome](https://www.media-chrome.org/) and the [media-elements](https://github.com/muxinc/media-elements) monorepo by Mux.

## License

[Craft License](https://craftcms.github.io/license/). Lite is free to install from the Plugin Store; Pro is a paid edition.
