# Browser dependencies

- `upchunk.js`: the Mux UpChunk browser distribution.
- `tus.js`: unmodified `dist/tus.min.js` from the npm package
  `tus-js-client@4.3.1`. Package SHA-1:
  `2f9a47fba006206fb0d08c649fa01254944d5d87`.
  `tus.min.js.map` and `tus.LICENSE` come from the same package.

`video-upload.js` is Polymedia's own code. It asks the server to authorize an
upload (`polymedia/video-uploads/preflight`) before any bytes leave the
browser, then sends the file to the selected provider: UpChunk for Mux, tus
for Bunny Stream. Cancelling stops the browser transfer but doesn't delete a
video the provider already created.

## Native upload routing

`native-video-routing.js` (Pro, opt-in) only supports Craft's standard
`Craft.Uploader` (blueimp). It doesn't replace filesystem uploader
registrations. A jQuery AJAX transport keeps Craft's own submission,
validation, queue accounting, progress events and cancellation, and swaps only
a routed MP4's bytes for a hosted upload. Preflight must explicitly return
`route: false` before a non-selected volume gets a native transfer. Errors
never fall back to native storage.

Custom filesystem uploaders aren't supported. If a volume selected for routing
uses one, that filesystem type's native upload controls are refused before any
transfer, rather than silently storing raw MP4s. Remove those volumes from
routing to restore native controls, or use the Upload video action. For a
routed MP4, server preflight validates the resulting `.pmedia` kind instead of
the MP4 kind. When preflight chooses native storage, the MP4 kind restriction
still applies; other files keep Craft's native validation.

`tests/js/blueimp-transport.test.cjs` exercises the real queue with
`jquery@3.7.1`, `blueimp-file-upload@10.32.0` and `jsdom@26.1.0`, installed
with `npm ci --prefix tests/js`. Point `POLYMEDIA_CRAFT_UPLOADER_SOURCE` at
Craft's `Uploader.js` source to run Craft's own `onFileAdd`. This doesn't
replace checking installed Craft versions and filesystem plugins in a real
control panel.
