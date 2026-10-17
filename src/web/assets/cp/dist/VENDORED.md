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
