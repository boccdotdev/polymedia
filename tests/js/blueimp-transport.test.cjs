// Optional real jQuery/blueimp integration proof:
// NODE_PATH=/path/to/modules node --test tests/js/blueimp-transport.test.cjs
// Dependencies: jquery@3.7.1 jsdom@26.1.0 blueimp-file-upload@10.32.0.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
let available = true;
try { require.resolve('jsdom'); require.resolve('blueimp-file-upload'); } catch { available = false; }
const installedCraftSource = path.join(__dirname,
  '../../vendor/craftcms/cms/src/web/assets/cp/src/js/Uploader.js');
const craftSource = process.env.POLYMEDIA_CRAFT_UPLOADER_SOURCE ||
  (fs.existsSync(installedCraftSource) ? installedCraftSource : null);

test('real blueimp queue retains active accounting and never sends routed bytes to native XHR',
  {skip: !available}, async () => {
    const {JSDOM} = require('jsdom');
    const dom = new JSDOM('<input id="upload" type="file" multiple>', {
      url: 'https://craft.example/admin', runScripts: 'outside-only',
    });
    const w = dom.window;
    for (const module of ['jquery/dist/jquery.js',
      'blueimp-file-upload/js/vendor/jquery.ui.widget.js',
      'blueimp-file-upload/js/jquery.fileupload.js']) {
      w.eval(fs.readFileSync(require.resolve(module), 'utf8'));
    }
    const $ = w.jQuery;
    const calls = [];
    const done = [];
    const pending = [];
    const added = [];
    let failures = 0;
    // Provider is deliberately pending while the native blueimp queue owns it.
    w.Craft = {PolymediaVideoUpload(file) {
      calls.push(file.name);
      return {promise: new Promise((resolve) => pending.push(resolve)), abort() {}};
    }};
    w.Craft.Uploader = function () {
      this.settings = {};
      this.formData = {folderId: 4};
      this._totalFileCounter = 0;
      this._validFileCounter = 0;
      this._rejectedFiles = {type: [], size: [], limit: []};
    };
    w.Craft.Uploader.prototype.onFileAdd = function (_event, data) {data.submit();};
    // Optionally run the upstream Craft onFileAdd verbatim, supplied locally.
    // This avoids a test-time network dependency or shipping Craft's source.
    if (craftSource) {
      w.Craft.BaseUploader = {extend(definition) {
        Object.assign(w.Craft.Uploader.prototype, definition);
        return w.Craft.Uploader;
      }};
      w.eval(fs.readFileSync(craftSource, 'utf8'));
      w.Craft.Uploader.prototype.processErrorMessages = function () {};
    }
    w.Craft.fileKinds = {polymedia: {extensions: ['pmedia']}};
    w.Craft.Uploader.prototype._createExtensionList = function () {
      this._extensionList = this.allowedKinds.flatMap((kind) => w.Craft.fileKinds[kind].extensions);
    };
    w.CraftPolymediaConfig = {isPro: true, videoEnabled: true,
      autoRouteVideoUploads: true, videoUploadVolumeUids: ['allowed']};
    w.eval(fs.readFileSync(path.join(__dirname,
      '../../src/web/assets/cp/dist/native-video-routing.js'), 'utf8'));
    let nativeRequests = 0;
    $.ajaxTransport('+*', (options) => {
      if (options.polymediaRoute) return;
      return {send(_headers, complete) {
        nativeRequests++;
        complete(200, 'success', {json: {assetId: 9}});
      }, abort() {}};
    });
    const uploader = new w.Craft.Uploader();
    const input = $('#upload').fileupload({
      url: '/actions/assets/upload',
      dataType: 'json',
      sequentialUploads: true,
      autoUpload: false,
      add: (event, data) => {added.push(data); uploader.onFileAdd(event, data);},
      done: (_event, data) => done.push(data.result.assetId),
      fail: () => failures++,
    });
    input.fileupload('add', {files: [
      new w.File(['video'], 'a.mp4', {type: 'video/mp4'}),
      new w.File(['image'], 'poster.jpg', {type: 'image/jpeg'}),
      new w.File(['video'], 'b.mp4', {type: 'video/mp4'}),
    ]});
    assert.equal(input.fileupload('active'), 3);
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.deepEqual(calls, ['a.mp4']);
    assert.equal(nativeRequests, 0);
    pending.shift()({assetId: 42});
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(nativeRequests, 1);
    assert.deepEqual(calls, ['a.mp4', 'b.mp4']);
    assert.equal(input.fileupload('active'), 1);
    pending.shift()({assetId: 43});
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.deepEqual(done, [42, 9, 43]);
    assert.equal(input.fileupload('active'), 0);

    if (craftSource) {
      uploader.allowedKinds = ['polymedia'];
      input.fileupload('add', {files: [
        new w.File(['video'], 'media-only.mp4', {type: 'video/mp4'}),
        new w.File(['image'], 'rejected.jpg', {type: 'image/jpeg'}),
      ]});
      await new Promise((resolve) => setTimeout(resolve, 20));
      assert.equal(input.fileupload('active'), 1);
      assert.deepEqual(uploader.allowedKinds, ['polymedia']);
      assert.equal(nativeRequests, 1);
      pending.shift()({assetId: 44});
      await new Promise((resolve) => setTimeout(resolve, 20));
      assert.deepEqual(done, [42, 9, 43, 44]);
    }

    uploader.allowedKinds = ['polymedia'];
    input.fileupload('add', {files: [new w.File(['video'], 'outside.mp4')]});
    await new Promise((resolve) => setTimeout(resolve, 20));
    pending.shift()({native: true});
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(failures, 1);
    assert.equal(nativeRequests, 1);
    assert.equal(input.fileupload('active'), 0);

    input.fileupload('add', {files: [new w.File(['video'], 'cancel.mp4')]});
    await new Promise((resolve) => setTimeout(resolve, 20));
    added[added.length - 1].abort();
    pending.shift()({assetId: 45});
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(input.fileupload('active'), 0);
    assert.equal(done.includes(45), false);

    // A retry is a fresh native submission; failed/cancelled work leaves no
    // pending active counters behind. Explicit route:false still delegates.
    uploader.allowedKinds = null;
    input.fileupload('add', {files: [new w.File(['video'], 'outside.mp4')]});
    await new Promise((resolve) => setTimeout(resolve, 20));
    pending.shift()({native: true});
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(input.fileupload('active'), 0);
    assert.equal(nativeRequests, 2);
    dom.window.close();
  });
