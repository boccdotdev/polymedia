# Browser dependencies

- `upchunk.js`: existing Mux UpChunk browser distribution.
- `tus.js`: unmodified `dist/tus.min.js` from the official npm package
  `tus-js-client@4.3.1`. Package SHA-1:
  `2f9a47fba006206fb0d08c649fa01254944d5d87`.
  `tus.min.js.map` and `tus.LICENSE` come from the same package.

Native video routing deliberately supports only the standard `Craft.Uploader`
blueimp implementation. It does not replace filesystem uploader registrations.
The AJAX transport retains native submission, validation, queue accounting,
progress events and cancellation. Preflight must explicitly return `route:false`
to use the native transfer for a non-allowlisted destination. Errors never trigger
a native fallback.

Custom filesystem uploader classes are outside this supported scope. If a selected
routing volume uses one, its filesystem type's native upload controls are refused
before transfer, rather than silently storing raw MP4s. Remove those volumes from
routing to restore native controls, or use the explicit video uploader. For routed MP4 files,
server preflight validates the resulting media asset kind instead of the original
MP4 kind. If preflight selects native storage, the original MP4 kind restriction
still applies. Other files retain native kind validation.

`tests/js/blueimp-transport.test.cjs` can additionally exercise the actual queue
with `jquery@3.7.1`, `blueimp-file-upload@10.32.0`, and `jsdom@26.1.0`
installed with `npm ci --prefix tests/js`. This is not a substitute for checking installed Craft
versions and filesystem plugins in a real control panel.
