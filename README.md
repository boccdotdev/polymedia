# Polymedia for Craft CMS

Store media URLs as Craft assets. Use YouTube, Vimeo, Mux, Bunny Stream, Spotify, HLS streams, video files and other supported sources in your existing content model.

## What it's for

Polymedia creates a lightweight `.pmedia` file for each media URL. It is a native Craft Asset element with search, relations, permissions and eager loading. A database record holds the provider data, playback settings and links to posters and text tracks.

The manifest stays in the folder your editor chooses. Polymedia-created posters and tracks live in a separate sidecar volume, outside normal asset browsing. Existing library assets selected as posters or tracks stay where they are.

Use the Twig helpers or GraphQL data with your own front end, or render a player with one Twig call. The built-in renderer uses [Media Chrome](https://www.media-chrome.org/) and provider web components, with no required JavaScript framework. [Video.js v10](#videojs-player-optional) is available as an opt-in alternative.

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

## Updating to 2.5

Nothing changes until you opt in. Existing installations keep Mux as their video hosting provider and HLS as their playback format, and automatic MP4 upload routing stays off even when provider credentials are configured. No videos are migrated, re-encoded or deleted, and no database migration or MP4 backfill runs.

Explicit manifest destinations are now strict: if an editor creates media while browsing a folder they can't write to (or one that no longer exists), Polymedia refuses instead of quietly saving the `.pmedia` in another volume.

The 2.2 sidecar volume and environment-reference requirements still apply.

## Field setup

Create a **Polymedia** field (appears in the field type picker). It extends the native Assets field, so it inherits all Craft's relation features — min/max limits, eager loading, element conditions.

Field settings:
- **Allowed Providers** — restrict which provider types can be selected (e.g. only YouTube + Mux). Leave empty for all.

Native Assets fields also support `.pmedia` files and provider filtering. Use them to mix URL media with images, uploaded videos and other library assets.

## Editions

Polymedia ships with two Craft Plugin Store editions:

| Edition | Includes |
|---------|----------|
| Lite, free | URL media for every supported provider, including Mux stream URLs; references to videos already in Craft; fields, players, posters and text tracks |
| Pro | Everything in Lite, plus a connected Mux or Bunny Stream library (browse, search, import), direct uploads, optional automatic MP4 upload routing, signed webhooks and console sync |

Pasting a Mux, HLS or MP4 URL works in Lite. Connected libraries, hosted uploads and provider API tools require Pro.

On non-public domains Craft allows unlicensed Pro for development (normal Craft trial rules).

## Adding Media

Open the **Add media** menu and choose **From URL**, paste any supported URL, and give it a title. The plugin auto-detects the provider type and creates a `.pmedia` manifest asset. **From existing asset** wraps a video that's already in Craft (see [Reference an existing Craft video](#reference-an-existing-craft-video)). With Pro and a configured hosting provider, the menu also offers **Browse video library** and **Upload video**. The menu is available in two places:

- **Assets index** — sits beside **Upload files**. The manifest lands in the volume/folder you're currently browsing, exactly like an uploaded file.
- **Field selection modals** — when picking media for a Polymedia or Assets field. The manifest lands in the field's upload location.

No volume picker: the target follows your current location. When there's no current location, it falls back to the configured default volume, then the first ordinary volume you can write to. A current folder you can't write to is refused rather than swapped for another volume. The sidecar volume is never a manifest destination. If no writable library volume is available, creation stops with an error.

For providers that can't be auto-detected (Shaka, Video.js, PeerTube), use the "Force Type" dropdown.

You can also set a poster image right on the **From URL** screen. Existing library images stay where they are. Inline uploads land in the plugin's sidecar volume. Posters and tracks can still be managed later on the asset edit screen.

### Choose a video hosting provider (Pro)

In **Settings → Plugins → Polymedia → Video Hosting**, choose **Mux** or **Bunny Stream** and fill in its connection. Only the selected provider handles new library imports and uploads, so authors see one **Browse video library** and one **Upload video** action without choosing a provider.

Existing assets keep their own provider and playback URL when you change this setting; nothing is moved. Keep the old provider's credentials while its assets still need syncing or remote deletion.

**Upload video** keeps each upload session in Craft's cache for up to six hours, between starting the upload and completing it. On load-balanced hosting, use a cache all servers share (Redis, or Craft's database cache) so the completion request finds the session. Otherwise completion fails with "upload session unavailable" and the video stays in the provider library, where **Browse video library** can import it.

Imported and uploaded videos show their media URL and type as read-only technical details on the asset edit screen. Authors edit the title, poster and tracks, not the provider identity.

### Reference an existing Craft video

**Add media → From existing asset** works in Lite. Pick a public MP4, WebM or MOV asset, and Polymedia creates (or reuses) a `.pmedia` that points at it, without copying, moving or deleting the original.

The `.pmedia` stores an `asset:<uid>` reference, never a copied public or signed URL. Twig helpers and GraphQL resolve the source's current URL, so moving or renaming the source keeps working. When the source is trashed or deleted, the reference plays nothing. Deleting the reference never deletes the source.

The source must be in a volume with public URLs. Polymedia checks that the file exists when you create the reference, not on every page view; if files are removed straight from storage, reindex assets as you would for any other asset.

### HLS and MP4 playback

Each provider's connection has a **Default Playback Format**, HLS to start with. HLS adapts quality to the viewer's connection. **MP4 when available** plays the highest ready MP4 rendition and falls back to HLS when there isn't one.

- **Mux:** choosing MP4 asks Mux for a static rendition on new uploads only. Renditions of existing videos are picked up on import, `polymedia/mux/sync-status` and rendition webhooks. Mux may charge for rendition storage and delivery.
- **Bunny Stream:** turn on **MP4 Fallback** in the Bunny library before uploading. Polymedia checks which MP4 files Bunny actually serves. Choosing MP4 in Craft doesn't change Bunny's encoding or regenerate older videos.
- Neither option generates MP4s in bulk, and rendering never calls a provider API.
- The choice is applied when rendering. The asset's HLS URL is never rewritten, so changing the default affects every asset that uses it.

Override it for one player:

```twig
{{ craft.polymedia.player(asset, { playbackFormat: 'mp4' }) }}
{{ craft.polymedia.element(asset, { playbackFormat: 'hls' }) }}
```

Both player engines render the result: an HLS element (`mux-video`, or the HLS element for Bunny) for HLS, and a native `<video>` for MP4. Include the providers you use in `scripts()`, for example `craft.polymedia.scripts({ providers: ['mux', 'bunny'] })` (a plain list also works: `scripts(['mux', 'bunny'])`). Bunny's hosted iframe player isn't used.

### Bunny Stream library and uploads (Pro)

1. Select **Bunny Stream** as the video hosting provider.
2. Enter the Stream **library ID**, the library's **API key** and its delivery hostname, such as `vz-example.b-cdn.net`.
3. Keep the key in your environment and select a reference such as `$BUNNY_API_KEY`. Use the library's own API key, not your account API key. Polymedia rejects literal keys, and the key never reaches the browser. The library ID and hostname accept environment references too.
4. In the Bunny library's **Security** settings, turn off **Block direct URL file access** and **Token authentication**.
5. Use **Browse video library** to search and import existing videos, or **Upload video** for a signed, resumable browser upload (tus).

Only anonymous HLS playback from the native `*.b-cdn.net` host is supported. Polymedia checks the playlist from your server before importing, and fetches posters the same way. Those requests carry no referrer, so with **Block direct URL file access** on, Bunny answers `403` and imports and uploads fail with "Bunny refused anonymous playback". Token authentication, DRM and custom CDN domains aren't supported either. Each imported asset keeps the library ID, video ID and hostname it was imported with.

Uploads poll until Bunny has encoded the video and its playlist is public. Cancelling an upload stops the browser transfer, but a video Bunny has already created stays in the library. If an upload finishes but creating the Craft asset is interrupted, import the video with **Browse video library**; delete unwanted incomplete videos in the Bunny dashboard.

To refresh imported items without webhooks (for example from cron):

```bash
php craft polymedia/bunny/sync
php craft polymedia/bunny/sync --video-id=<guid>
```

**Webhooks (optional):** put the library's **read-only API key** in your environment and select its reference under **Bunny Webhook Verification Key**. Then copy the **Bunny Webhook URL** shown in the settings (`https://your-site/actions/polymedia/webhooks/bunny`) into the library's webhook setting. Polymedia verifies Bunny's signature over the raw request body, refreshes only videos that are already imported, and ignores the status code in the payload (it re-reads the video from the API). Unknown videos are acknowledged and ignored; temporary failures answer `503` so Bunny retries. The endpoint is off while the key is empty.

**Remote deletion (optional):** **Delete Bunny video when Craft asset is deleted** is off by default. When on, permanently deleting a Bunny `.pmedia` also deletes the video, but only with Pro and credentials for the library the video came from. Trashing an asset never deletes the video. Craft's automatic trash purging (after `softDeleteDuration`) skips element events, so it never deletes remote videos; only deleting from the Trash, or a direct hard delete, does.

### Automatically route native MP4 uploads (Pro)

A separate, **opt-in** setting. Turn on **Automatically host uploaded MP4 videos** and select the ordinary volumes it applies to; the sidecar volume can't be selected. With it off, native uploads are unchanged.

In those volumes, an MP4 uploaded with Craft's normal **Upload files** button or by drag and drop goes straight to the selected provider, and a `.pmedia` lands in the resolved Craft folder. Other files upload normally. Mixed batches keep Craft's ordering, file limits, progress and completion events. Replacing an existing file, and existing MP4 assets, are left alone.

- Routing supports Craft's standard uploader. Volumes on Craft Cloud's filesystem, which has its own uploader, can't be selected; use **Upload video** for them. If another selected volume's filesystem type turns out to use a custom JavaScript uploader, Polymedia disables native upload controls rather than storing raw MP4s. The browser only knows the filesystem type, so that applies to **every volume of that type**, including volumes you didn't select and every Assets field that uploads to them. Remove the volume from routing to restore them; **Upload video** keeps working either way.
- Fields must allow the Polymedia file kind and the selected provider. Polymedia fields offer native upload only where routing applies. Upload permissions, owner permissions, source and location restrictions and the field's selection condition still apply.
- A provider or authorization failure never falls back to storing the MP4 natively.
- Cancelling stops the browser transfer; a video the provider already created stays in its library.

### Mux library & upload (Pro)

1. Install **Pro**, select **Mux** under **Video Hosting** and open **Mux Connection**.
2. Add the Mux API **Token ID** and **Token Secret** to each environment, then select their references in plugin settings (for example `$MUX_TOKEN_ID` and `$MUX_TOKEN_SECRET`). Polymedia rejects literal credentials so they cannot be written to project config.
3. On the Assets index (or field asset modal), open the **Add media** menu and use:
   - **Browse video library**: a live list from your Mux account; import creates a `.pmedia` or reuses one matched by playback ID.
   - **Upload video**: a browser direct upload (UpChunk); when Mux has a playback ID, Craft creates or reuses the `.pmedia`.

**Playback policy:** v1 imports **public** playback only. Signed-only assets are flagged in the browse UI and cannot be imported yet.

**Mux service fees** are separate from the Polymedia Pro license.

Optional setting **Delete Mux asset when Craft asset is deleted** (default **off**): when enabled, **hard-deleting** a Mux `.pmedia` also deletes the video in Mux. Soft-delete / trash never calls Mux.

**Notes on delete-from-Mux:** the remote delete runs **synchronously** in the hard-delete request (not a queue job). Deleting many Mux items at once may take longer. If the site is on **Lite** or credentials are missing while this setting is still on, Craft logs a **warning** and skips the Mux API call (the remote asset may remain until deleted in Mux). Craft's automatic trash purging (after `softDeleteDuration`) skips element events, so it never deletes remote videos; only deleting from the Trash, or a direct hard delete, does.

### Mux status updates: polling, sync, and webhooks (Pro)

A Mux item's processing status (`preparing` / `ready` / `errored`) and duration are stored on the media item. Webhooks are optional. The upload flow and console command still work without them:

1. **Polling (default, zero setup).** The Upload video modal polls until Mux has a playback ID and stores its status.
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

This emits `<script type="module">` tags for Media Chrome and the providers listed in your plugin settings. With the [Video.js engine](#videojs-player-optional) it emits the Video.js files instead.

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

Renders a full `<media-controller>` player (see [Video.js player](#videojs-player-optional) for the other engine's markup and options):

```twig
{{ craft.polymedia.player(media) }}
```

Options: `autoplay`, `loop`, `muted`, `playsinline`, `preload`, `crossorigin`, `poster`, `class`, `id`, `attrs`, `mediaAttrs`, `children`, `tracks`, `playbackFormat` (`hls` or `mp4`, for Mux and Bunny items; see [HLS and MP4 playback](#hls-and-mp4-playback)). For Mux, Bunny and MP4 items, `mediaAttrs` can't override `src`, `playback-id` or `type`.

> **Note:** `controls` is intentionally **not** applied inside `player()`. Media Chrome supplies the control UI; native controls would conflict with `<media-controller>`.
>
> - `attrs` — HTML attributes on the outer `<media-controller>`
> - `mediaAttrs` — HTML attributes on the inner media element (e.g. `title`, `referrerpolicy`)

### `craft.polymedia.element(asset, options)`

Renders just the media element (no controller wrapper). Use this when you want a bare provider element without Media Chrome.

Options: `autoplay`, `loop`, `muted`, **`controls`**, `playsinline`, `preload`, `crossorigin`, `poster`, `mediaAttrs`, `playbackFormat`. Defaults include `controls: true` so bare `<video>`/`<audio>` elements are usable without a custom control bar.

### `craft.polymedia.data(asset)`

Returns the parsed manifest data as an array.

### `craft.polymedia.is(asset)`

Returns `true` if the value is a polymedia asset.

### `craft.polymedia.scripts(options)`

Renders script tags. Options: `providers` (array), `version` (Media Chrome major), `mode` (`cdn`/`self-host`/`none`). The Video.js engine reads `version` as an exact Video.js version and adds `skins` and `locale`; see [Loading scripts](#loading-scripts).

### `craft.polymedia.poster(asset)`

Returns the resolved poster URL for a polymedia asset.

### `craft.polymedia.aspectRatio(asset)`

Returns the media's aspect ratio as a CSS `aspect-ratio` value (`16 / 9`), or `null` when it isn't known. Use it to reserve the player's box so lazy-loaded players don't shift the page:

```twig
<div style="aspect-ratio: {{ craft.polymedia.aspectRatio(media) ?? '16 / 9' }}">
    {{ craft.polymedia.player(media) }}
</div>
```

Mux items store the ratio Mux reports (`metadata.aspectRatio`, e.g. `9:16`) and the video's stored `width`/`height` on import, upload, sync, and webhook updates. Items imported before 2.3 get them on their next update; run `craft polymedia/mux/sync-status` to fill them in for all of them. For other providers it returns `null` unless `metadata.aspectRatio` or `width`/`height` are set. The dimensions are also available as `width`/`height` in `craft.polymedia.data(asset)` and GraphQL.

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
| `getAspectRatio()` | `string\|null` | `craft.polymedia.aspectRatio(asset)` |
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

## Video.js player (optional)

Polymedia can render [Video.js v10](https://videojs.org/) players instead of Media Chrome. Video.js v10 is built by the Media Chrome team, and Media Chrome is expected to wind down as a standalone project. Media Chrome stays the default throughout 2.x, and nothing changes until you switch.

### Switching engines

Set **Player Engine** in the plugin settings. It applies to the whole site: Media Chrome and Video.js register the same element names, so the two can't load on the same page.

To try Video.js in one environment first, select an environment variable for the setting and set it per environment:

```bash
# .env on staging
POLYMEDIA_PLAYER_ENGINE=videojs
```

An unset or unrecognised value falls back to Media Chrome. Video.js needs Chrome 111, Firefox 113, Safari 16.2 or newer.

`craft.polymedia.playerEngine` returns the active engine (`media-chrome` or `videojs`), so templates can branch while you trial it, for example to pass the right `children` markup.

### Loading scripts

Put `{{ craft.polymedia.scripts() }}` in your `<head>`. With Video.js it emits the Video.js stylesheet, a few layout rules, the video and audio skins, and one module per provider, all pinned to the **Video.js Version** setting (`10.1.0` by default):

```twig
{{ craft.polymedia.scripts({ providers: ['mux', 'youtube'] }) }}
```

Extra `scripts()` options with Video.js:

- `skins`: extra skins to load alongside the configured one, e.g. `['neutral']`
- `version`: an exact Video.js version, overriding the setting
- `locale`: a language such as `de`, or `false` to skip translations. If you pass `locale` to `player()`, pass the same value here so the language pack loads.

The layout rules and each player's `aspect-ratio` are inline styles. A Content Security Policy without `'unsafe-inline'` in `style-src` blocks them; in that case add the rules below to your own CSS and size players with `attrs: { class: … }`.

In self-host mode, copy the contents of the `@videojs/cdn` package into your **Self-Host Base URL**, keeping its layout (`global.css`, `i18n.js`, `video*.js`, `audio*.js`, `locales/`, `media/` and the chunk files next to them). Add `jwplayer-video-element.min.js`, `peertube-video-element.min.js` or `videojs-video-element.min.js` at the top level if you use those providers.

In none mode, load the files yourself and add these rules to your CSS. The first gives skins and `<media-container>` a block box so `aspect-ratio` applies, the second sizes iframe providers inside a skin, and the third lets standalone embeds fill the width:

```css
:where(video-skin,video-neutral-skin,video-compat-skin,audio-skin,audio-neutral-skin,audio-compat-skin):not(:defined),:where(media-container){display:block}
:where(video-skin,video-neutral-skin,video-compat-skin,media-container)>:where(youtube-video,vimeo-video,cloudflare-video,twitch-video,tiktok-video){display:block;width:100%;height:100%;min-width:0;min-height:0}
:where(jwplayer-video,peertube-video,videojs-video){display:block;width:100%}
```

### Rendered markup

```twig
{{ craft.polymedia.player(media) }}
{# <video-player poster="…"><video-skin style="aspect-ratio: 16 / 9;"><mux-video src="https://stream.mux.com/abc123.m3u8" playsinline crossorigin="anonymous"></mux-video></video-skin></video-player> #}
```

- The poster goes on `<video-player>`, where the skin reads it.
- Mux renders with a `src` stream URL. Video.js has no `playback-id` attribute.
- Generic HLS uses `<hlsjs-video>`, which plays MPEG-TS streams.
- Audio files render as `<audio-player><audio-skin>`. There is no cover image.
- `class`, `id` and `attrs` go on the skin, which is the visible box. `mediaAttrs` still go on the media element.
- On a site whose language Video.js translates, the player is wrapped in `<media-i18n lang="…">`. Pass `locale: false` to turn that off, or a language to override it.

### Skins

Choose the default under **Video.js Skin** (`default`, `neutral` or `compat`) or per player:

```twig
{{ craft.polymedia.player(media, { skin: 'neutral' }) }}
```

Load any extra skin with the `skins` option on `scripts()`. Style a skin with its CSS custom properties: `--media-accent-color`, `--media-accent-text-color`, `--media-border-color`, `--media-border-radius`, `--media-font-family`, `--media-object-fit`, `--media-object-position` and `--media-scale-unit`. See the [Video.js skin docs](https://videojs.org/docs/framework/html/guides/customize-skins).

### Skin examples

[`examples/videojs/`](examples/videojs) has two starting points. Copy the files into your project; Polymedia doesn't load them for you.

**Brand theme** (`brand-theme.css`) restyles a packaged skin with its CSS custom properties only: accent colour, square corners, font and control size. No build step and no copied skin source:

```twig
{{ craft.polymedia.player(media, { class: 'brand-player' }) }}
```

**Media Chrome look-alike** (`media-chrome-controls.twig` and `media-chrome.css`) recreates Media Chrome's control bar with your own controls, so a site moving from Media Chrome keeps its look. Copy the Twig file to `templates/_polymedia/` and the CSS into your stylesheet:

```twig
{{ craft.polymedia.player(media, {
    class: 'media-chrome',
    children: include('_polymedia/media-chrome-controls.twig'),
}) }}
```

The CSS reads Media Chrome's own variables first (`--media-primary-color`, `--media-control-background`, `--media-control-hover-background`, `--media-range-bar-color` and the rest), so colours and sizes you set for Media Chrome carry over. It differs in two small ways: times show as `00:00` rather than `0:00`, and controls the media can't use, such as captions without tracks, are hidden rather than disabled.

### Box size

The player reserves its box with an inline `aspect-ratio`, so lazy players don't shift the page. It uses the stored ratio ([`aspectRatio()`](#craftpolymediaaspectratioasset)), or 16 / 9 for video when the ratio isn't known. A ratio in your own `attrs.style` wins.

```twig
{{ craft.polymedia.player(media, { aspectRatio: '4 / 3' }) }}
{{ craft.polymedia.player(media, { aspectRatio: false }) }}
```

### Custom controls

Passing `children` drops the skin and renders `<media-container>` with your own Video.js controls. The skin's script defines every control element. They come unstyled and expose their state as `data-*` attributes (`data-paused`, `data-muted`, `data-fullscreen`, `data-active` on captions), which your CSS uses to pick icons. The poster goes on the media element, since there's no skin to show it.

```twig
{{ craft.polymedia.player(media, {
    class: 'my-player',
    children: '<media-controls>
        <media-play-button><media-icon name="play"></media-icon><media-icon name="pause"></media-icon></media-play-button>
        <media-mute-button><media-icon name="volume-high"></media-icon><media-icon name="volume-off"></media-icon></media-mute-button>
        <media-fullscreen-button><media-icon name="fullscreen-enter"></media-icon><media-icon name="fullscreen-exit"></media-icon></media-fullscreen-button>
    </media-controls>'
}) }}
```

```css
.my-player media-play-button[data-paused] [name="pause"],
.my-player media-play-button:not([data-paused]) [name="play"],
.my-player media-mute-button[data-muted] [name="volume-high"],
.my-player media-mute-button:not([data-muted]) [name="volume-off"],
.my-player media-fullscreen-button[data-fullscreen] [name="fullscreen-enter"],
.my-player media-fullscreen-button:not([data-fullscreen]) [name="fullscreen-exit"] {
  display: none;
}
```

`<media-icon>` draws icons from the default skin, which `scripts()` loads unless you configure another skin. You can use your own SVGs instead. Sliders need their track, fill and thumb parts; the [Media Chrome look-alike](#skin-examples) shows a complete control bar.

Media Chrome control markup isn't translated. Several elements were renamed (`media-time-range` is now `media-time-slider`, `media-control-bar` is now `media-controls`) and state attributes became `data-*` attributes. See the [migration guide](https://videojs.org/docs/framework/html/guides/migrate-from-media-chrome).

### Providers without a Video.js element

JW Player, PeerTube and Video.js (v8) embeds have no Video.js v10 element. They render as their standalone element with the provider's own controls and no skin, and still get the `aspect-ratio` box. Spotify also renders standalone, because its embedded widget is the only working control.

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
| Bunny Stream | `<hls-video>` | Library import only (a pasted `b-cdn.net` playlist is plain HLS) |
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

Added in 2.5.0 (unreleased, pending live provider testing):

- [x] Bunny Stream integration for Pro: library browsing and search, import, resumable direct uploads, poster and metadata syncing, optional signed webhooks and console sync.
- [x] One developer-selected hosting provider, Mux or Bunny, with provider-neutral **Browse video library** and **Upload video** actions for authors. Existing assets keep their original provider.
- [x] Per-provider HLS or MP4 playback defaults, HLS to start with, template overrides and HLS fallback when an MP4 is unavailable, on both player engines.
- [x] Pro-only automatic routing of new native MP4 uploads to the selected provider. Opt-in, off by default, and limited to selected volumes with Craft's standard uploader.
- [x] Lite **From existing asset** action that references Craft-hosted videos by UID and follows source moves and renames.
- [x] Optional Bunny remote deletion on permanent Craft deletion, off by default. Trashing an asset never deletes its remote video.

## Credits

Built on [Media Chrome](https://www.media-chrome.org/) and the [media-elements](https://github.com/muxinc/media-elements) monorepo by Mux.

## License

[Craft License](https://craftcms.github.io/license/). Lite is free to install from the Plugin Store; Pro is a paid edition.
