# Polymedia for Craft CMS

Store media URLs as Craft assets. Use YouTube, Vimeo, Mux, Spotify, HLS streams, video files and other supported sources in your existing content model.

## What it's for

Polymedia creates a lightweight `.pmedia` file for each media URL. It is a native Craft Asset element with search, relations, permissions and eager loading. A database record holds the provider data, playback settings and links to posters and text tracks.

The manifest stays in the folder your editor chooses. Polymedia-created posters and tracks live in a separate sidecar volume, outside normal asset browsing. Existing library assets selected as posters or tracks stay where they are.

Use the Twig helpers or GraphQL data with your own front end, or render a player with one Twig call. The built-in renderer uses [Media Chrome](https://www.media-chrome.org/) and provider web components, with no required JavaScript framework.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- Media Chrome and provider scripts if you use the built-in player renderer, loaded through the `scripts()` helper or your own bundler

## Installation

```bash
composer require boccdotdev/polymedia
php craft plugin/install polymedia
```

## Updating to 2.2

Existing installations need a dedicated sidecar volume before creating new poster or VTT uploads. Existing attachments continue to work in place. Follow the [sidecar volume guide](#sidecar-volume) to configure storage and preview the conservative migration.

## Field setup

Create a **Polymedia** field (appears in the field type picker). It extends the native Assets field, so it inherits all Craft's relation features — min/max limits, eager loading, element conditions.

Field settings:
- **Allowed Providers** — restrict which provider types can be selected (e.g. only YouTube + Mux). Leave empty for all.

Native Assets fields also support `.pmedia` files and provider filtering. Use them to mix URL media with images, uploaded videos and other library assets.

## Editions

Polymedia ships with two Craft Plugin Store editions:

| Edition | Includes |
|---------|----------|
| Lite, free | URL media for every supported provider, including Mux stream URLs; fields, players, posters and text tracks |
| Pro | Everything in Lite, plus Mux library browsing and search, direct uploads, optional signed webhooks and console status sync |

Pasting a Mux stream URL works in Lite. The Mux library, upload and API integration tools require Pro.

On non-public domains Craft allows unlicensed Pro for development (normal Craft trial rules).

## Adding Media

Open the **Add media** menu and choose **From URL**, paste any supported URL, and give it a title. The plugin auto-detects the provider type and creates a `.pmedia` manifest asset. (With Pro + Mux credentials, the same menu also offers **Browse Mux library** and **Upload to Mux**.) The menu is available in two places:

- **Assets index** — sits beside **Upload files**. The manifest lands in the volume/folder you're currently browsing, exactly like an uploaded file.
- **Field selection modals** — when picking media for a Polymedia or Assets field. The manifest lands in the field's upload location.

No volume picker — the target follows your current location, falling back to the configured default volume (then the first ordinary volume you can write to) if none can be resolved. The sidecar volume is never a manifest destination. If no writable library volume is available, creation stops with an error.

For providers that can't be auto-detected (Shaka, Video.js, PeerTube), use the "Force Type" dropdown.

You can also set a poster image right on the **From URL** screen. Existing library images stay where they are. Inline uploads land in the plugin's sidecar volume. Posters and tracks can still be managed later on the asset edit screen.

### Mux library & upload (Pro)

1. Install **Pro** and open **Settings → Plugins → Polymedia → Mux**.
2. Add the Mux API **Token ID** and **Token Secret** to each environment, then select their references in plugin settings (for example `$MUX_TOKEN_ID` and `$MUX_TOKEN_SECRET`). Polymedia rejects literal credentials so they cannot be written to project config.
3. On the Assets index (or field asset modal), open the **Add media** menu and use:
   - **Browse Mux library** — live list from your Mux account; import creates a `.pmedia` or reuses one matched by **playback ID**.
   - **Upload to Mux** — browser direct upload (UpChunk); when Mux has a playback ID, Craft creates/reuses the `.pmedia`.

**Playback policy:** v1 imports **public** playback only. Signed-only assets are flagged in the browse UI and cannot be imported yet.

**Mux service fees** are separate from the Polymedia Pro license.

Optional setting **Delete Mux asset when Craft asset is deleted** (default **off**): when enabled, **hard-deleting** a Mux `.pmedia` also deletes the video in Mux. Soft-delete / trash never calls Mux.

**Notes on delete-from-Mux:** the remote delete runs **synchronously** in the hard-delete request (not a queue job). Deleting many Mux items at once may take longer. If the site is on **Lite** or credentials are missing while this setting is still on, Craft logs a **warning** and skips the Mux API call (the remote asset may remain until deleted in Mux).

### Mux status updates: polling, sync, and webhooks (Pro)

A Mux item's processing status (`preparing` / `ready` / `errored`) and duration are stored on the media item. Webhooks are optional. The upload flow and console command still work without them:

1. **Polling (default, zero setup).** The Upload to Mux modal polls until the asset is ready and stores its status.
2. **Console sync.** Pull current state from the Mux API for every imported item (or one), fetching posters for newly ready assets. Safe to re-run; also repairs items that missed a webhook:

   ```
   ./craft polymedia/mux/sync-status
   ./craft polymedia/mux/sync-status --mux-asset-id=<id>
   ```

3. **Webhooks (optional).** Mux pushes `video.asset.*` events (ready, errored, updated, deleted) to your site. This keeps status and posters current after the editor closes the upload modal. The modal can continue polling while it is open.

#### Webhook setup

1. In the [Mux dashboard](https://dashboard.mux.com/) open **Settings → Webhooks**, pick the **same environment** your API tokens belong to, and click **Create new webhook**.
2. **URL to notify:** copy the **Webhook URL** shown in **Settings → Plugins → Polymedia → Mux** (it's `https://your-site/actions/polymedia/webhooks/mux`).
3. Mux generates a **signing secret** for the webhook. Put it in your `.env` (e.g. `MUX_WEBHOOK_SECRET=…`) and set **Mux Webhook Signing Secret** to `$MUX_WEBHOOK_SECRET`.

Every delivery is verified against the signing secret (HMAC, with a replay-window check); the endpoint is disabled entirely while no secret is set. Deliveries for videos that aren't in Craft are acknowledged and ignored. `video.asset.deleted` only marks the item's stored status — it never deletes the Craft asset.

Lock timeouts or failed state writes return HTTP `503` so Mux can retry rather than treating the delivery as handled. Repeated deliveries with unchanged state are safe. Console sync reports failed items and exits non-zero so scheduled runs can detect and retry failures.

Each Mux **environment** has its own webhooks and secrets, so configure one per environment and keep the secret in that environment's `.env`.

#### Webhooks on local dev

Polling and `sync-status` cover local development without any webhook setup. When you do need to test webhooks, the [Mux CLI](https://www.mux.com/docs/core/listen-for-webhooks) is the simplest option. It forwards events to the site without exposing your computer to the internet:

```
npx @mux/cli login
npx @mux/cli webhooks listen \
  --forward-to http://your-site.ddev.site/actions/polymedia/webhooks/mux
```

The listener prints a local signing secret. Set `MUX_WEBHOOK_SECRET` to that value and keep the Polymedia setting as `$MUX_WEBHOOK_SECRET`. The CLI reuses this secret for the selected Mux environment.

Create or update a video in that Mux environment to receive a real event. You can also check the endpoint and signature handling with a synthetic event:

```
npx @mux/cli webhooks trigger video.asset.ready \
  --forward-to http://your-site.ddev.site/actions/polymedia/webhooks/mux
```

Synthetic events usually refer to an asset that does not exist in Craft. Polymedia returns `200` and ignores that item, which still confirms that forwarding and signature verification work.

The CLI signing secret and a dashboard webhook's signing secret are different. Set `MUX_WEBHOOK_SECRET` to the secret for the delivery method you are testing.

##### Testing dashboard delivery through a tunnel

Use a tunnel when you need to test the full Mux dashboard to local-site route. Add the tunnel URL as a webhook in the development Mux environment, then use the signing secret generated for that dashboard webhook.

- **Tailscale Funnel** has a stable URL. With DDEV, allow the router to answer for your tailnet hostname, then open the funnel:

  ```yaml
  # .ddev/config.yaml
  additional_fqdns:
    - your-machine.your-tailnet.ts.net
  ```

  ```
  ddev restart
  tailscale funnel --bg --https=443 https+insecure://127.0.0.1:443
  ```

  Webhook URL: `https://your-machine.your-tailnet.ts.net/actions/polymedia/webhooks/mux`

- **ngrok:** `ddev share` (DDEV's built-in ngrok wrapper). Free-tier URLs rotate per run, so re-paste the URL in the Mux dashboard each session.
- **Cloudflare quick tunnel:** `cloudflared tunnel --url https://your-site.ddev.site`. It needs no account, but the URL rotates per run.

Use a **separate Mux environment for development** so local experiments never receive (or miss) production events.

### Sidecar volume

`.pmedia` manifests stay directly in the folder where the editor creates or moves them. Polymedia stores files it manages, such as fetched posters and inline-uploaded WebVTT tracks, in a separate **sidecar volume**. The plugin hides that volume from asset indexes and pickers. Existing images or tracks selected from another asset volume stay in place and are never deleted by Polymedia.

New installations create a public local filesystem and volume named **Polymedia Sidecars**:

- Base path: `@webroot/polymedia-sidecars`
- Base URL: `@web/polymedia-sidecars`

For an existing installation, create the same default volume with:

```bash
php craft polymedia/setup/sidecar-volume --dry-run
php craft polymedia/setup/sidecar-volume
```

The path and URL are configurable:

```bash
php craft polymedia/setup/sidecar-volume \
  --path='$POLYMEDIA_SIDECAR_PATH' \
  --url='$POLYMEDIA_SIDECAR_URL'
```

Keep the sidecar filesystem's base path or object prefix outside every other Craft filesystem's base path or prefix. Nesting it under an existing Uploads filesystem can cause both volumes to index the same files. Local directories must also persist across deployments.

To use S3, Google Cloud Storage, or another remote filesystem, create a dedicated Craft filesystem and volume with a non-overlapping prefix, then select it under **Settings → Plugins → Polymedia → Sidecar Volume**. You can also change the filesystem used by the auto-created volume without changing its Polymedia setting. After selecting a different volume, run `php craft polymedia/migrate/sidecars` to move existing managed files into it.

Editors who use the inline poster or WebVTT upload controls need permission to save assets in the sidecar volume. Polymedia still removes the volume from their Assets index.

#### Migrating items created by Polymedia 1.2–2.1

After configuring the sidecar volume, preview and apply the migration:

```bash
php craft polymedia/migrate/sidecars --dry-run
php craft polymedia/migrate/sidecars
```

The command moves each legacy `.pmedia` into its parent folder, but leaves legacy posters and tracks in place. Older versions did not record whether Polymedia created an attachment or an editor selected it from the library. Matching a title or folder name is not proof of ownership, so these files are reported as `kept` for manual review. Their existing relations continue to work.

When changing sidecar volumes, the command can move files already in that item's explicit asset-UID folder. It leaves files referenced by another Polymedia item or a native Craft relation field in place, including references from trashed items.

Keep the previous volume and filesystem available while any retained files still use them.

The dry run reports `planned` moves without creating folders or changing files. Execution reports `moved`, `kept`, and `failed` results with asset IDs and destinations. Failed moves are not counted as successful, and any failure produces a non-zero exit code. Correct the reported problem and rerun the command; files already moved to the selected sidecar volume are skipped.

The command never deletes a legacy folder. Only remove folders after verifying they are empty; folders containing retained posters or tracks must remain.

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
2. Else if **Auto-Fetch Poster** is on (URL create), or always for **Mux library/upload** imports: download a still into the item's folder in the sidecar volume and attach it as the poster.
3. **Mux** uses the Image API first frame: `https://image.mux.com/{playbackId}/thumbnail.jpg?time=0` (Mux’s default without `time` is mid-video).
4. If the Mux image is not ready yet, a queue job retries with backoff.
5. Last resort: remote thumbnail URL only in manifest metadata (CP may show it until a local poster exists).

CP asset index / picker thumbs for `.pmedia` files use the related poster when present, else the remote thumbnail URL.

## Accessibility

Attach `.vtt` files to video items via the asset edit screen, in three roles: **Captions**, **Subtitles**, and **Descriptions**. Each role is a multi-select picker that uploads into the item's folder in the sidecar volume. The pickers appear only on video items (audio items show the poster picker alone).

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

### Video hosting and asset workflows

Implementation is on the [`2.2` development branch](https://github.com/boccdotdev/polymedia/tree/2.2), pending live Craft and provider testing. These features are not part of the current release.

- [ ] Bunny Stream integration for Pro: library browsing and search, import, resumable direct uploads, poster and metadata synchronization, optional signed webhooks and console sync.
- [ ] One developer-selected hosting provider, Mux or Bunny, with provider-neutral **Browse video library** and **Upload video** actions for authors. Existing assets keep their original provider.
- [ ] Per-provider HLS or MP4 playback defaults, with HLS selected initially, template overrides and HLS fallback when an MP4 is unavailable. Playback continues to use Media Chrome.
- [ ] Pro-only automatic routing of new native MP4 uploads to the selected provider, creating `.pmedia` assets instead of storing the video in the native volume. Explicitly opt-in, off by default, and limited to selected volumes with supported uploaders.
- [ ] Lite **From existing asset** action to reference Craft-hosted videos by UID, retaining the original file and following source moves or renames.
- [ ] Optional Bunny remote deletion on permanent Craft deletion, disabled by default. Trashing an asset never deletes its remote video.

## Credits

Built on [Media Chrome](https://www.media-chrome.org/) and the [media-elements](https://github.com/muxinc/media-elements) monorepo by Mux.

## License

[Craft License](https://craftcms.github.io/license/). Lite is free to install from the Plugin Store; Pro is a paid edition.
