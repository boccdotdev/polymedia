const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname,
  '../../src/web/assets/cp/dist/native-video-routing.js'), 'utf8');

function setup(overrides = {}, transfer = async () => ({assetId: 42})) {
  let transport;
  let native = 0;
  let aborted = 0;
  let calls = 0;
  const config = {isPro: true, videoEnabled: true, autoRouteVideoUploads: true,
    videoUploadVolumeUids: ['allowed'], ...overrides};
  function Uploader() {
    this.settings = {};
    this.formData = {folderId: 4};
    this.valid = 0;
    this.active = 0;
  }
  // Model Craft's validation + native submission ownership, not provider logic.
  Uploader.prototype.onFileAdd = function (_event, data) {
    if (this.allowedKinds && !this.allowedKinds.some((kind) =>
      Craft.fileKinds[kind].extensions.includes(data.files[0].name.split('.').pop().toLowerCase()))) return;
    if (data.files[0].size > 100 || this.valid >= (this.settings.limit || 9)) return;
    this.valid++;
    this.active++;
    data.submit();
  };
  const original = Uploader.prototype.onFileAdd;
  const Craft = {Uploader, fileKinds: {video: {extensions: ['mp4']},
    polymedia: {extensions: ['pmedia']}}, PolymediaVideoUpload(file, context) {
    calls++;
    return {promise: transfer(file, context), abort() {aborted++;}};
  }};
  const $ = {
    ajaxTransport: (_type, factory) => {transport = factory;},
    ajax: () => {
      native++;
      return {done(cb) {cb({assetId: 1}); return this;}, fail() {return this;}, abort() {}};
    },
  };
  vm.runInNewContext(source, {Craft, $, window: {CraftPolymediaConfig: config}});
  const uploader = new Uploader();
  function add(name, size = 10, target = uploader) {
    const data = {files: [{name, size}], submit() {
      const handler = transport && transport(data);
      if (handler) handler.send({}, () => target.active--);
      else {native++; target.active--;}
    }};
    target.onFileAdd({}, data);
    return data;
  }
  return {Craft, uploader, add, original, transport: (data) => transport(data),
    calls: () => calls, native: () => native, aborted: () => aborted};
}

test('disabled and Lite preserve original native uploader', () => {
  for (const config of [{isPro: false}, {autoRouteVideoUploads: false},
    {videoEnabled: false}, {videoUploadVolumeUids: []}]) {
    const s = setup(config);
    assert.equal(s.Craft.Uploader.prototype.onFileAdd, s.original);
    s.add('video.mp4');
    assert.equal(s.native(), 1);
  }
});

test('mixed batches retain native submit, active counts, validation and limits', async () => {
  let done;
  const s = setup({}, () => new Promise((resolve) => {done = resolve;}));
  s.uploader.settings.limit = 3;
  s.add('a.MP4');
  s.add('poster.jpg');
  s.add('oversize.mp4', 101);
  s.add('b.mov');
  s.add('over-limit.mp4');
  assert.equal(s.calls(), 1);
  assert.equal(s.native(), 2);
  assert.equal(s.uploader.valid, 3);
  assert.equal(s.uploader.active, 1);
  done({assetId: 42});
  await new Promise(setImmediate);
  assert.equal(s.uploader.active, 0);
});

test('replacement and custom uploader subclasses are never changed', () => {
  const s = setup();
  s.uploader.settings.replace = true;
  assert.equal(s.add('replacement.mp4').polymediaRoute, undefined);
  class Custom extends s.Craft.Uploader {}
  assert.equal(s.add('custom.mp4', 10, new Custom()).polymediaRoute, undefined);
  assert.equal(s.calls(), 0);
});

test('captures live folder and inline field context at file add', () => {
  const s = setup();
  s.uploader.formData = {fieldId: 3, elementId: 7, siteId: 2};
  const first = s.add('a.mp4');
  s.uploader.formData = {folderId: 22};
  const second = s.add('b.mp4');
  assert.equal(first.polymediaRoute.context.fieldId, 3);
  assert.equal(first.polymediaRoute.context.siteId, 2);
  assert.equal(second.polymediaRoute.context.folderId, 22);
});

test('provider failures settle native accounting without native fallback', async () => {
  const s = setup({}, async () => {throw new Error('Provider denied');});
  s.add('a.mp4');
  await new Promise(setImmediate);
  assert.equal(s.native(), 0);
  assert.equal(s.uploader.active, 0);
});

test('only explicit non-allowlisted preflight decision delegates native transfer', async () => {
  const s = setup({}, async () => ({native: true}));
  s.add('a.mp4');
  await new Promise(setImmediate);
  assert.equal(s.native(), 1);
  assert.equal(s.uploader.active, 0);
});

test('abort cancels provider and ignores late completion', async () => {
  const s = setup({}, async () => ({assetId: 42}));
  let settled = false;
  const handler = s.transport({polymediaRoute: {file: {}, context: {}}});
  handler.send({}, () => {settled = true;});
  handler.abort();
  await new Promise(setImmediate);
  assert.equal(s.aborted(), 1);
  assert.equal(settled, false);
});

test('media-only fields route MP4 while restoring native kind validation', async () => {
  const s = setup();
  s.uploader.allowedKinds = ['polymedia'];
  const routed = s.add('a.mp4');
  assert.equal(routed.polymediaRoute.originalKindAllowed, false);
  assert.deepEqual(s.uploader.allowedKinds, ['polymedia']);
  s.add('not-media.jpg');
  assert.equal(s.uploader.valid, 1);
  await new Promise(setImmediate);
  assert.equal(s.calls(), 1);
  assert.equal(s.native(), 0);
  assert.equal(s.uploader.active, 0);
});

test('media-only field never transfers raw MP4 to a nonallowlisted destination', async () => {
  const s = setup({}, async () => ({native: true}));
  s.uploader.allowedKinds = ['polymedia'];
  s.add('a.mp4');
  await new Promise(setImmediate);
  assert.equal(s.native(), 0);
  assert.equal(s.uploader.active, 0);
});

test('custom filesystem is reported before choosing files and factory result stays unchanged', () => {
  function Uploader() {}
  Uploader.prototype.onFileAdd = () => {};
  const custom = {};
  const notices = [];
  const Craft = {Uploader, createUploader: () => custom,
    t: (_category, message) => message,
    cp: {displayNotice: (message) => notices.push(message)}};
  vm.runInNewContext(source, {Craft, $: {ajaxTransport() {}},
    window: {CraftPolymediaConfig: {isPro: true, videoEnabled: true,
      autoRouteVideoUploads: true, videoUploadVolumeUids: ['allowed']}}});
  assert.equal(Craft.createUploader(), custom);
  assert.equal(Craft.createUploader(), custom);
  assert.equal(notices.length, 1);
  assert.match(notices[0], /unavailable for this filesystem/);
});

test('routing-selected custom filesystem is refused before native uploader construction', () => {
  function Uploader() {}
  function Custom() {}
  Uploader.prototype.onFileAdd = function () {};
  let created = 0;
  const notices = [];
  const Craft = {
    Uploader,
    _uploaderClasses: {custom: Custom},
    createUploader: () => {created++; return new Custom();},
    t: (_category, message) => message,
    cp: {displayError: (message) => notices.push(message)},
  };
  const control = () => ({
    disabled: false,
    prop(_key, value) {this.disabled = value; return this;},
    addClass() {return this;},
    removeClass() {return this;},
  });
  const button = control();
  button.is = () => true;
  const fileInput = control();
  let dropHandler;
  const dropZone = {
    on(_events, callback) {dropHandler = callback;},
    off() {dropHandler = null;},
  };
  vm.runInNewContext(source, {Craft, $: {ajaxTransport() {}},
    window: {CraftPolymediaConfig: {isPro: true, videoEnabled: true,
      autoRouteVideoUploads: true, videoUploadVolumeUids: ['allowed'],
      videoUploadFsTypes: ['custom']}}});
  const blocked = Craft.createUploader('custom', button, {fileInput, dropZone});
  assert.equal(created, 0);
  assert.equal(button.disabled, true);
  assert.equal(fileInput.disabled, true);
  assert.equal(blocked.getInProgress(), 0);
  assert.equal(blocked.isLastUpload(), true);
  assert.match(notices[0], /Native uploads are disabled/);
  let prevented = false;
  dropHandler({preventDefault() {prevented = true;}, stopPropagation() {}});
  assert.equal(prevented, true);
  blocked.destroy();
  assert.equal(dropHandler, null);
  assert.equal(fileInput.disabled, false);
  assert.equal(Craft.createUploader('custom', button, {replace: true}) instanceof Custom, true);
  assert.equal(created, 1, 'Existing-file replacement is outside routing policy.');
});
